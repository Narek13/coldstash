<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this plugin moves rows between WooCommerce's order tables and its archive tables; no WordPress API covers that, and results must never be cached.

namespace Coldstash\Archive;

use Coldstash\Settings\Settings;
use Coldstash\Storage\Schema;
use Coldstash\Support\Hpos;

/**
 * Finds orders that may be archived, and counts the ones that may not.
 *
 * The expensive exclusion checks (downloads, subscriptions) are done in SQL
 * here, once per batch, rather than per order in Eligibility — which stays a
 * pure function so it can be tested without a database.
 */
final class CandidateQuery {

	/**
	 * Most candidates the preview screen counts, so it stays fast on huge stores.
	 */
	public const PREVIEW_CAP = 100000;

	/**
	 * Order type of refunds, which live as child orders of the order they refund.
	 */
	private const REFUND_TYPE = 'shop_order_refund';

	/**
	 * Action Scheduler hook WooCommerce Analytics uses to sync one order into its lookup tables.
	 */
	private const ANALYTICS_IMPORT_HOOK = 'wc-admin_import_orders';

	/**
	 * Takes the archive settings the query filters by.
	 *
	 * @param Settings $settings Archive settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * IDs of the oldest orders that may be archived.
	 *
	 * @param int $limit Most IDs to return.
	 * @return list<int>
	 */
	public function candidates( int $limit ): array {
		global $wpdb;

		$statuses = $this->settings->statuses;

		$sql = 'SELECT o.id
                FROM %i o
                LEFT JOIN %i a ON a.id = o.id
                WHERE o.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')
                  AND o.date_created_gmt < %s
                  AND o.parent_order_id = 0
                  AND a.id IS NULL
                  AND NOT EXISTS (
                        SELECT 1 FROM %i r WHERE r.order_id = o.id AND r.meta_key = %s
                      )
                  AND NOT EXISTS (
                        SELECT 1 FROM %i c WHERE c.parent_order_id = o.id AND c.type = %s
                      )
                  AND NOT EXISTS (
                        SELECT 1 FROM %i q
                        WHERE q.hook = %s AND q.status = %s AND q.args = CONCAT(\'[\', o.id, \']\')
                      )';

		$args = array(
			Hpos::ordersTable(),
			Schema::ordersTable(),
			...$statuses,
			$this->settings->cutoffGmt(),
			Hpos::metaTable(),
			RestoreService::RESTORED_META_KEY,
			Hpos::ordersTable(),
			self::REFUND_TYPE,
			$wpdb->prefix . 'actionscheduler_actions',
			self::ANALYTICS_IMPORT_HOOK,
			'pending',
		);

		if ( $this->settings->excludeActiveDownloads ) {
			$sql   .= ' AND NOT EXISTS (
                        SELECT 1 FROM %i d
                        WHERE d.order_id = o.id
                          AND (d.access_expires IS NULL OR d.access_expires > UTC_TIMESTAMP())
                      )';
			$args[] = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
		}

		foreach ( $this->extensionExclusionClauses() as $clause ) {
			$sql .= ' ' . $clause;
		}

		$sql   .= ' ORDER BY o.date_created_gmt ASC LIMIT %d';
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- literals and placeholders only; values go through prepare().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Orders that would otherwise qualify but are held back by an extension.
	 *
	 * Subscriptions, Bookings and Memberships attach their own records to
	 * orders and resolve them lazily, so archiving one of their orders breaks
	 * renewals or entitlements in ways that only surface weeks later. v1 skips
	 * them entirely rather than guessing.
	 *
	 * @return list<string>
	 */
	private function extensionExclusionClauses(): array {
		global $wpdb;

		$orders  = Hpos::ordersTable();
		$meta    = Hpos::metaTable();
		$clauses = array();

		if ( class_exists( 'WC_Subscriptions' ) ) {
			// Subscriptions themselves, their parents, and their renewals.
			$clauses[] = "AND o.type = 'shop_order'";
			$clauses[] = "AND NOT EXISTS (
                            SELECT 1 FROM {$orders} s
                            WHERE s.type = 'shop_subscription' AND s.parent_order_id = o.id
                          )";
			$clauses[] = "AND NOT EXISTS (
                            SELECT 1 FROM {$meta} m
                            WHERE m.order_id = o.id
                              AND m.meta_key IN ('_subscription_renewal', '_subscription_switch', '_subscription_resubscribe')
                          )";
		}

		if ( class_exists( 'WC_Bookings' ) ) {
			$clauses[] = "AND NOT EXISTS (
                            SELECT 1 FROM {$wpdb->posts} b
                            WHERE b.post_type = 'wc_booking' AND b.post_parent = o.id
                          )";
		}

		if ( class_exists( 'WC_Memberships' ) ) {
			$clauses[] = "AND NOT EXISTS (
                            SELECT 1 FROM {$wpdb->postmeta} pm
                            WHERE pm.meta_key = '_order_id' AND pm.meta_value = o.id
                          )";
		}

		/**
		 * Add site-specific SQL exclusions to the candidate query.
		 *
		 * Clauses are appended verbatim, so anything dynamic must be escaped
		 * by the caller.
		 *
		 * @since 1.0.0
		 *
		 * @param list<string> $clauses SQL fragments, each starting with AND.
		 */
		return (array) apply_filters( 'coldstash_candidate_exclusions', $clauses );
	}

	/**
	 * Headline numbers for the preview screen: how many qualify, how many
	 * exist in total, and what the gap is made of.
	 *
	 * @return array{eligible:int, total:int, excluded:array<string,int>}
	 */
	public function preview(): array {
		global $wpdb;

		$orders   = Hpos::ordersTable();
		$statuses = $this->settings->statuses;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE parent_order_id = 0', $orders )
		);

		$wrongStatus = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE parent_order_id = 0 AND status NOT IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
				...array_merge( array( $orders ), $statuses )
			)
		);

		$tooRecent = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
                 WHERE parent_order_id = 0 AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ') AND date_created_gmt >= %s',
				...array_merge( array( $orders ), $statuses, array( $this->settings->cutoffGmt() ) )
			)
		);

		$qualifying = 'o.parent_order_id = 0 AND o.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ') AND o.date_created_gmt < %s';
		$base       = array_merge( $statuses, array( $this->settings->cutoffGmt() ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- literals and placeholders only, unpacked.
		$restored = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i o WHERE ' . $qualifying . ' AND EXISTS (SELECT 1 FROM %i r WHERE r.order_id = o.id AND r.meta_key = %s)',
				...array_merge( array( $orders ), $base, array( Hpos::metaTable(), RestoreService::RESTORED_META_KEY ) )
			)
		);

		$hasRefunds = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i o WHERE ' . $qualifying . ' AND EXISTS (SELECT 1 FROM %i c WHERE c.parent_order_id = o.id AND c.type = %s)',
				...array_merge( array( $orders ), $base, array( $orders, self::REFUND_TYPE ) )
			)
		);

		$analyticsPending = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i o WHERE ' . $qualifying . " AND EXISTS (SELECT 1 FROM %i q WHERE q.hook = %s AND q.status = %s AND q.args = CONCAT('[', o.id, ']'))",
				...array_merge( array( $orders ), $base, array( $wpdb->prefix . 'actionscheduler_actions', self::ANALYTICS_IMPORT_HOOK, 'pending' ) )
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$eligible = count( $this->candidates( self::PREVIEW_CAP ) );

		return array(
			'eligible' => $eligible,
			'total'    => $total,
			'excluded' => array(
				ExclusionReason::NotTerminalStatus->value => $wrongStatus,
				ExclusionReason::TooRecent->value         => $tooRecent,
				ExclusionReason::Restored->value          => $restored,
				ExclusionReason::HasRefunds->value        => $hasRefunds,
				ExclusionReason::AnalyticsPending->value  => $analyticsPending,
			),
		);
	}
}
