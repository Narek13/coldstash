<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this plugin moves rows between WooCommerce's order tables and its archive tables; no WordPress API covers that, and results must never be cached.

namespace Coldstash\Archive;

use JsonException;
use Coldstash\Storage\ArchiveRepository;
use Coldstash\Support\Hpos;
use Throwable;

/**
 * Moves one order from the live tables into the archive.
 *
 * The order of operations is the whole safety story:
 *
 *   1. build the payload from all six tables
 *   2. INSERT it into the archive
 *   3. read it back out of MySQL and compare checksums
 *   4. only then DELETE the live rows
 *
 * If step 3 disagrees, we roll back and leave the live data exactly as it was.
 * A failed archive costs the merchant nothing; a half-archived order costs
 * them an order.
 *
 * Analytics lookup tables (wc_order_stats and friends) are deliberately never
 * touched — that is what keeps historical reports identical after archiving.
 */
final class ArchiveService {

	/**
	 * Wires the payload builder and archive storage.
	 *
	 * @param PayloadBuilder    $payloads Captures an order's rows.
	 * @param ArchiveRepository $archive  Archive table access.
	 */
	public function __construct(
		private readonly PayloadBuilder $payloads,
		private readonly ArchiveRepository $archive,
	) {
	}

	/**
	 * Archives one order: capture, write, verify, then delete the live rows.
	 *
	 * @param int    $orderId Order to archive.
	 * @param string $batchId Run the order belongs to, for the log.
	 * @return array{ok:bool, reason:string, message:string}
	 */
	public function archive( int $orderId, string $batchId = '' ): array {
		global $wpdb;

		if ( ! Hpos::isEnabled() ) {
			return $this->fail( 'hpos_required', 'HPOS is not enabled on this store.' );
		}

		if ( $this->archive->exists( $orderId ) ) {
			return $this->fail( 'already_archived', 'Order is already in the archive.' );
		}

		/**
		 * Last chance for site-specific rules to veto an order.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $should  Whether to archive the order.
		 * @param int  $orderId Order about to be archived.
		 */
		if ( ! apply_filters( 'coldstash_should_archive_order', true, $orderId ) ) {
			return $this->fail( 'filtered_out', 'Excluded by the coldstash_should_archive_order filter.' );
		}

		try {
			$payload = $this->payloads->build( $orderId );
		} catch ( JsonException $e ) {
			// Nothing has been written yet, so there is nothing to roll back.
			$this->archive->log( $batchId, 'archive', $orderId, 'error', 'unencodable', $e->getMessage() );

			return $this->fail( 'unencodable', $e->getMessage() );
		}

		if ( ! $payload instanceof Payload ) {
			return $this->fail( 'not_found', 'Order not found in the live tables.' );
		}

		$order       = $payload->row( 'order' ) ?? array();
		$billing     = $this->billingAddress( $payload );
		$operational = $payload->row( 'operational_data' ) ?? array();

		$indexed = array(
			'parent_order_id'  => $order['parent_order_id'] ?? 0,
			'status'           => $order['status'] ?? '',
			'type'             => $order['type'] ?? 'shop_order',
			'currency'         => $order['currency'] ?? '',
			'total_amount'     => $order['total_amount'] ?? '0',
			'customer_id'      => $order['customer_id'] ?? 0,
			'billing_email'    => $order['billing_email'] ?? ( $billing['email'] ?? '' ),
			'billing_name'     => trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) ),
			'order_key'        => $operational['order_key'] ?? '',
			'payment_method'   => $order['payment_method'] ?? '',
			'transaction_id'   => $order['transaction_id'] ?? '',
			'item_summary'     => $this->payloads->itemSummary( $payload->rows( 'items' ) ),
			'date_created_gmt' => $order['date_created_gmt'] ?? null,
			'date_paid_gmt'    => $operational['date_paid_gmt'] ?? null,
		);

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->writeVerified( $orderId, $payload, $indexed, $batchId );
			$this->deleteLiveRows( $orderId, $payload );

			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$this->archive->log( $batchId, 'archive', $orderId, 'error', 'exception', $e->getMessage() );

			return $this->fail( 'exception', $e->getMessage() );
		}

		$this->archive->log( $batchId, 'archive', $orderId, 'ok' );

		/**
		 * Fires after an order has been archived and removed from the live tables.
		 *
		 * @since 1.0.0
		 *
		 * @param int $orderId Archived order.
		 */
		do_action( 'coldstash_order_archived', $orderId );

		return array(
			'ok'      => true,
			'reason'  => '',
			'message' => '',
		);
	}

	/**
	 * Writes the archive row and reads it back. Verify before delete: never skip this.
	 *
	 * @param int                  $orderId Order being archived.
	 * @param Payload              $payload Captured order.
	 * @param array<string, mixed> $indexed Searchable columns.
	 * @param string               $batchId Run the order belongs to.
	 * @throws \RuntimeException When the row cannot be written or does not read back identically.
	 */
	private function writeVerified( int $orderId, Payload $payload, array $indexed, string $batchId ): void {
		if ( ! $this->archive->insert( $orderId, $payload, $indexed, $batchId ) ) {
			throw new \RuntimeException( 'Could not write the archive row.' );
		}

		$stored = $this->archive->storedPayload( $orderId );
		if ( null === $stored || ! $payload->matches( hash( 'sha256', $stored ) ) ) {
			throw new \RuntimeException( 'Archived payload did not match after read-back.' );
		}
	}

	/**
	 * Deletes the order's rows from every live table the payload captured.
	 *
	 * @param int     $orderId Archived order.
	 * @param Payload $payload Captured order, for the item and note IDs.
	 */
	private function deleteLiveRows( int $orderId, Payload $payload ): void {
		global $wpdb;

		$tables = Hpos::sourceTables();

		$itemIds = array_map(
			static fn ( array $i ): int => (int) $i['order_item_id'],
			$payload->rows( 'items' )
		);
		$noteIds = array_map(
			static fn ( array $n ): int => (int) $n['comment_ID'],
			$payload->rows( 'notes' )
		);

		if ( array() !== $itemIds ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE order_item_id IN (' . implode( ',', array_fill( 0, count( $itemIds ), '%d' ) ) . ')',
					...array_merge( array( $tables['item_meta'] ), $itemIds )
				)
			);
		}

		if ( array() !== $noteIds ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE comment_id IN (' . implode( ',', array_fill( 0, count( $noteIds ), '%d' ) ) . ')',
					...array_merge( array( $wpdb->commentmeta ), $noteIds )
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE comment_ID IN (' . implode( ',', array_fill( 0, count( $noteIds ), '%d' ) ) . ')',
					...array_merge( array( $wpdb->comments ), $noteIds )
				)
			);
		}

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', $tables['items'], $orderId ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', $tables['meta'], $orderId ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', $tables['operational_data'], $orderId ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', $tables['addresses'], $orderId ) );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE order_id = %d',
				$wpdb->prefix . 'woocommerce_downloadable_product_permissions',
				$orderId
			)
		);
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $tables['order'], $orderId ) );
	}

	/**
	 * The billing row from the captured addresses, or an empty array.
	 *
	 * @param Payload $payload Captured order.
	 * @return array<string, mixed>
	 */
	private function billingAddress( Payload $payload ): array {
		foreach ( $payload->rows( 'addresses' ) as $address ) {
			if ( 'billing' === ( $address['address_type'] ?? '' ) ) {
				return $address;
			}
		}

		return array();
	}

	/**
	 * Builds a failure result.
	 *
	 * @param string $reason  Machine-readable reason code.
	 * @param string $message Human-readable explanation.
	 * @return array{ok:bool, reason:string, message:string}
	 */
	private function fail( string $reason, string $message ): array {
		return array(
			'ok'      => false,
			'reason'  => $reason,
			'message' => $message,
		);
	}
}
