<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this plugin moves rows between WooCommerce's order tables and its archive tables; no WordPress API covers that, and results must never be cached.

namespace Coldstash\Storage;

use Coldstash\Archive\Payload;

/**
 * Reads/writes archive rows. The indexed columns answer every browse and
 * search query; the payload is only decoded when an order is actually opened
 * or restored.
 */
final class ArchiveRepository {

	/**
	 * Writes one archive row.
	 *
	 * @param int                  $orderId Original order ID, kept as the row ID.
	 * @param Payload              $payload Full order payload.
	 * @param array<string, mixed> $indexed Values for the indexed columns.
	 * @param string               $batchId Batch this order was archived in.
	 * @return bool Whether the row was written.
	 */
	public function insert( int $orderId, Payload $payload, array $indexed, string $batchId ): bool {
		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::ordersTable(),
			array(
				'id'                => $orderId,
				'parent_order_id'   => (int) ( $indexed['parent_order_id'] ?? 0 ),
				'status'            => (string) ( $indexed['status'] ?? '' ),
				'type'              => (string) ( $indexed['type'] ?? 'shop_order' ),
				'currency'          => (string) ( $indexed['currency'] ?? '' ),
				'total_amount'      => (string) ( $indexed['total_amount'] ?? '0' ),
				'customer_id'       => (int) ( $indexed['customer_id'] ?? 0 ),
				'billing_email'     => (string) ( $indexed['billing_email'] ?? '' ),
				'billing_name'      => (string) ( $indexed['billing_name'] ?? '' ),
				'order_key'         => (string) ( $indexed['order_key'] ?? '' ),
				'payment_method'    => (string) ( $indexed['payment_method'] ?? '' ),
				'transaction_id'    => (string) ( $indexed['transaction_id'] ?? '' ),
				'item_summary'      => (string) ( $indexed['item_summary'] ?? '' ),
				'date_created_gmt'  => $indexed['date_created_gmt'] ?? null,
				'date_paid_gmt'     => $indexed['date_paid_gmt'] ?? null,
				'payload'           => $payload->json,
				'payload_schema'    => $payload->schemaVersion,
				'payload_checksum'  => $payload->checksum(),
				'source_wc_version' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
				'archived_at'       => gmdate( 'Y-m-d H:i:s' ),
				'batch_id'          => $batchId,
			),
			array(
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		return false !== $inserted;
	}

	/**
	 * Read the stored payload straight back out of the database.
	 *
	 * This is the "verify" half of verify-before-delete: we compare what MySQL
	 * actually stored against what we meant to store, before anything is
	 * removed from the live tables.
	 *
	 * @param int $orderId Order ID.
	 * @return string|null Stored JSON, or null when there is no row.
	 */
	public function storedPayload( int $orderId ): ?string {
		global $wpdb;

		$json = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE id = %d', Schema::ordersTable(), $orderId ) );

		return is_string( $json ) ? $json : null;
	}

	/**
	 * Whether an order is in the archive.
	 *
	 * @param int $orderId Order ID.
	 * @return bool
	 */
	public function exists( int $orderId ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE id = %d', Schema::ordersTable(), $orderId ) );
	}

	/**
	 * One full archive row, payload included.
	 *
	 * @param int $orderId Order ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $orderId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::ordersTable(), $orderId ), ARRAY_A );

		return is_array( $row ) && array() !== $row ? $row : null;
	}

	/**
	 * Removes an order from the archive (after a successful restore).
	 *
	 * @param int $orderId Order ID.
	 * @return bool Whether the delete query succeeded.
	 */
	public function delete( int $orderId ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Schema::ordersTable(), array( 'id' => $orderId ), array( '%d' ) );
	}

	/**
	 * Archived orders belonging to a customer, matched by billing email or user ID,
	 * for the personal data exporter and eraser.
	 *
	 * @param string $email  Billing email address.
	 * @param int    $userId WordPress user ID, or 0 when the email has no account.
	 * @param int    $limit  Page size.
	 * @param int    $offset Rows to skip.
	 * @return list<array<string, mixed>>
	 */
	public function findForCustomer( string $email, int $userId, int $limit, int $offset ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, currency, total_amount, billing_email, item_summary, date_created_gmt, payload
				 FROM %i
				 WHERE billing_email = %s OR ( customer_id > 0 AND customer_id = %d )
				 ORDER BY id ASC LIMIT %d OFFSET %d',
				Schema::ordersTable(),
				$email,
				$userId,
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Replaces an archived order's payload with an anonymized one, keeping the
	 * indexed columns in step, then reads it back to verify the checksum.
	 *
	 * @param int     $orderId Order ID.
	 * @param Payload $payload Anonymized payload.
	 * @param string  $billingEmail Anonymized billing email.
	 * @param string  $billingName  Anonymized billing name.
	 * @return bool Whether the new payload was stored intact.
	 */
	public function replacePayload( int $orderId, Payload $payload, string $billingEmail, string $billingName ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET payload = %s, payload_checksum = %s, billing_email = %s, billing_name = %s,
				 customer_id = 0, transaction_id = ''
				 WHERE id = %d",
				Schema::ordersTable(),
				$payload->json,
				$payload->checksum(),
				$billingEmail,
				$billingName,
				$orderId
			)
		);

		if ( false === $updated ) {
			return false;
		}

		$stored = $this->storedPayload( $orderId );

		return null !== $stored && $payload->matches( hash( 'sha256', $stored ) );
	}

	/**
	 * Archived order IDs above a cursor, lowest first.
	 *
	 * @param int $afterId Last ID already handled; 0 to start from the beginning.
	 * @param int $limit   Most IDs to return.
	 * @return list<int>
	 */
	public function idsAfter( int $afterId, int $limit ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', Schema::ordersTable(), $afterId, $limit )
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Number of archived orders.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::ordersTable() ) );
	}

	/**
	 * Archive browse/search over the indexed columns; the payload column is never touched.
	 *
	 * @param string $term   Order ID, email, name, order key, transaction ID or item text; empty lists all.
	 * @param int    $limit  Page size.
	 * @param int    $offset Rows to skip.
	 * @param string $status Order status without the "wc-" prefix; empty for any.
	 * @return list<array<string, mixed>>
	 */
	public function search( string $term = '', int $limit = 50, int $offset = 0, string $status = '' ): array {
		global $wpdb;

		$args = $this->searchArgs( $term, $status );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders unpacked from searchArgs().
			$wpdb->prepare(
				"SELECT id, parent_order_id, status, type, currency, total_amount, customer_id,
				 billing_email, billing_name, order_key, transaction_id, item_summary,
				 date_created_gmt, archived_at
				 FROM %i
				 WHERE ( %s = '' OR status = %s )
				 AND ( %s = '' OR id = %d OR billing_email LIKE %s OR billing_name LIKE %s
					OR order_key = %s OR transaction_id = %s OR item_summary LIKE %s )
				 ORDER BY date_created_gmt DESC, id DESC LIMIT %d OFFSET %d",
				...array_merge( $args, array( $limit, $offset ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Number of archived orders a search() with the same filters would page through.
	 *
	 * @param string $term   Same as search().
	 * @param string $status Same as search().
	 * @return int
	 */
	public function searchCount( string $term = '', string $status = '' ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders unpacked from searchArgs().
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE ( %s = '' OR status = %s )
				 AND ( %s = '' OR id = %d OR billing_email LIKE %s OR billing_name LIKE %s
					OR order_key = %s OR transaction_id = %s OR item_summary LIKE %s )",
				...$this->searchArgs( $term, $status )
			)
		);
	}

	/**
	 * Count of archived orders per status, for the list table's status filter.
	 *
	 * @return array<string, int>
	 */
	public function statusCounts(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT status, COUNT(*) AS orders FROM %i GROUP BY status ORDER BY status', Schema::ordersTable() ),
			ARRAY_A
		);

		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['orders'];
		}

		return $counts;
	}

	/**
	 * Placeholder values shared by search() and searchCount(), in query order.
	 *
	 * @param string $term   Search term.
	 * @param string $status Status filter.
	 * @return list<string|int>
	 */
	private function searchArgs( string $term, string $status ): array {
		global $wpdb;

		$term = trim( $term );
		$like = '%' . $wpdb->esc_like( $term ) . '%';

		return array(
			Schema::ordersTable(),
			$status,
			$status,
			$term,
			(int) ltrim( $term, '#' ),
			$like,
			$like,
			$term,
			$term,
			$like,
		);
	}

	/**
	 * Archive size and the date range it covers.
	 *
	 * @return array{archived:int, oldest:?string, newest:?string}
	 */
	public function summary(): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS archived, MIN(date_created_gmt) AS oldest, MAX(date_created_gmt) AS newest FROM %i',
				Schema::ordersTable()
			),
			ARRAY_A
		);

		return array(
			'archived' => (int) ( $row['archived'] ?? 0 ),
			'oldest'   => $row['oldest'] ?? null,
			'newest'   => $row['newest'] ?? null,
		);
	}

	/**
	 * Appends one line to the run log.
	 *
	 * @param string $batchId   Batch ID.
	 * @param string $direction archive or restore.
	 * @param int    $orderId   Order ID.
	 * @param string $result    ok or failed.
	 * @param string $reason    Machine-readable failure reason.
	 * @param string $message   Human-readable detail, truncated to 2000 characters.
	 */
	public function log( string $batchId, string $direction, int $orderId, string $result, string $reason = '', string $message = '' ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::runsTable(),
			array(
				'batch_id'  => $batchId,
				'direction' => $direction,
				'order_id'  => $orderId,
				'result'    => $result,
				'reason'    => $reason,
				'message'   => mb_substr( $message, 0, 2000 ),
				'logged_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
