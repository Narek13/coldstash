<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this plugin moves rows between WooCommerce's order tables and its archive tables; no WordPress API covers that, and results must never be cached.

namespace Coldstash\Archive;

use Coldstash\Storage\ArchiveRepository;
use Coldstash\Support\Hpos;
use Throwable;

/**
 * Puts an archived order back into the live tables, byte for byte.
 *
 * Restoring through WC_Order::save() would be tidier but mints a *new* order
 * ID, and order numbers appear in the merchant's accounting, shipping labels
 * and customer emails. So this writes the original rows back with their
 * original IDs, inside a transaction.
 *
 * On ID reuse: only old orders are ever archived, so archived IDs always sit
 * below MAX(id) in wc_orders. InnoDB's AUTO_INCREMENT counter is MAX(id)+1 —
 * persisted across restarts on MySQL 8.0, recomputed from MAX(id) on 5.7 —
 * and removing low IDs never moves it. Archiving therefore cannot cause reuse
 * on either version. We check for a collision anyway before writing.
 */
final class RestoreService {

	/**
	 * Order meta set on every restored order, so later archive runs leave it live.
	 */
	public const RESTORED_META_KEY = '_coldstash_restored';

	/**
	 * Takes the archive storage to restore from.
	 *
	 * @param ArchiveRepository $archive Archive table access.
	 */
	public function __construct( private readonly ArchiveRepository $archive ) {
	}

	/**
	 * Restores one archived order with its original IDs, then removes it from the archive.
	 *
	 * @param int    $orderId     Archived order to restore.
	 * @param string $batchId     Run the order belongs to, for the log.
	 * @param bool   $keepLive    Flag the order so later archive runs leave it live. True when a
	 *                            merchant restores an order to work on it; false for "Restore all",
	 *                            after which archiving should be able to pick orders up again.
	 * @return array{ok:bool, reason:string, message:string}
	 */
	public function restore( int $orderId, string $batchId = '', bool $keepLive = true ): array {
		global $wpdb;

		if ( ! Hpos::isEnabled() ) {
			return $this->fail( 'hpos_required', 'HPOS is not enabled on this store.' );
		}

		$row = $this->archive->find( $orderId );
		if ( null === $row ) {
			return $this->fail( 'not_found', 'Order is not in the archive.' );
		}

		$tables = Hpos::sourceTables();

		$collision = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE id = %d', $tables['order'], $orderId ) );
		if ( $collision ) {
			return $this->fail( 'id_collision', "Order ID {$orderId} already exists in the live tables." );
		}

		try {
			$payload = Payload::fromJson( (string) $row['payload'] );
		} catch ( Throwable $e ) {
			return $this->fail( 'bad_payload', $e->getMessage() );
		}

		if ( ! $payload->matches( (string) $row['payload_checksum'] ) ) {
			return $this->fail( 'checksum_mismatch', 'Stored payload does not match its checksum.' );
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->insertRows( $tables['order'], $payload->rows( 'order' ) );
			$this->insertRows( $tables['addresses'], $payload->rows( 'addresses' ) );
			$this->insertRows( $tables['operational_data'], $payload->rows( 'operational_data' ) );
			$this->insertRows( $tables['meta'], $payload->rows( 'meta' ) );
			$this->insertRows( $tables['items'], $payload->rows( 'items' ) );
			$this->insertRows( $tables['item_meta'], $payload->rows( 'item_meta' ) );
			$this->insertRows( $wpdb->comments, $payload->rows( 'notes' ) );
			$this->insertRows( $wpdb->commentmeta, $payload->rows( 'note_meta' ) );
			$this->insertRows(
				$wpdb->prefix . 'woocommerce_downloadable_product_permissions',
				$payload->rows( 'downloads' )
			);
			if ( $keepLive ) {
				$this->markRestored( $tables['meta'], $orderId );
			}

			$this->archive->delete( $orderId );

			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$this->archive->log( $batchId, 'restore', $orderId, 'error', 'exception', $e->getMessage() );

			return $this->fail( 'exception', $e->getMessage() );
		}

		$this->archive->log( $batchId, 'restore', $orderId, 'ok' );

		/**
		 * Fires after an archived order has been restored to the live tables.
		 *
		 * @since 1.0.0
		 *
		 * @param int $orderId Restored order.
		 */
		do_action( 'coldstash_order_restored', $orderId );

		return array(
			'ok'      => true,
			'reason'  => '',
			'message' => '',
		);
	}

	/**
	 * Flags the order as restored, so the archive run does not take it straight back.
	 *
	 * @param string $metaTable Live order meta table.
	 * @param int    $orderId   Restored order.
	 * @throws \RuntimeException When the flag cannot be written.
	 */
	private function markRestored( string $metaTable, int $orderId ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d AND meta_key = %s', $metaTable, $orderId, self::RESTORED_META_KEY ) );

		$inserted = $wpdb->insert(
			$metaTable,
			array(
				'order_id'   => $orderId,
				'meta_key'   => self::RESTORED_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single insert, not a query.
				'meta_value' => gmdate( 'Y-m-d H:i:s' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- single insert, not a query.
			)
		);

		if ( false === $inserted ) {
			throw new \RuntimeException( esc_html( 'Failed to flag the restored order: ' . $wpdb->last_error ) );
		}
	}

	/**
	 * Insert captured rows verbatim.
	 *
	 * Columns come from the payload, which came from the live schema, so a
	 * column added by a later WooCommerce release round-trips untouched. Any
	 * column that has since been *removed* would fail the insert loudly, which
	 * is the behaviour we want — better a refused restore than a silent one.
	 *
	 * @param string                     $table Live table, from the Hpos allowlist or core.
	 * @param list<array<string, mixed>> $rows  Captured rows for that table.
	 * @throws \RuntimeException When a row cannot be inserted.
	 */
	private function insertRows( string $table, array $rows ): void {
		global $wpdb;

		foreach ( $rows as $row ) {
			if ( array() === $row ) {
				continue;
			}

			if ( false === $wpdb->insert( $table, $row ) ) {
				throw new \RuntimeException(
					esc_html( sprintf( 'Failed restoring a row into %s: %s', $table, $wpdb->last_error ) )
				);
			}
		}
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
