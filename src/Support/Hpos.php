<?php

declare(strict_types=1);

namespace Coldstash\Support;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Everything we need to know about HPOS, in one place.
 *
 * This plugin is HPOS-only by design. Supporting legacy post storage as well
 * would roughly double the storage layer and its edge cases, for a shrinking
 * slice of stores. Instead we detect it, refuse to run, and say so clearly.
 *
 * Note that order items live in the *shared* legacy tables even under HPOS —
 * woocommerce_order_items / woocommerce_order_itemmeta were never migrated.
 * On a large store those are usually the biggest tables of the lot, so they
 * matter more to archiving than the HPOS tables themselves.
 */
final class Hpos {

	/**
	 * Whether WooCommerce is using HPOS as its order storage.
	 *
	 * @return bool
	 */
	public static function isEnabled(): bool {
		if ( ! class_exists( OrderUtil::class ) ) {
			return false;
		}

		return OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * True when HPOS is on *and* legacy sync is off.
	 *
	 * With sync enabled WooCommerce mirrors every write back into wp_posts, so
	 * archiving would leave orphaned post rows behind and the merchant would
	 * see no space reclaimed. Better to tell them to turn sync off first.
	 */
	public static function isAuthoritative(): bool {
		return self::isEnabled() && ! self::syncEnabled();
	}

	/**
	 * Whether compatibility-mode sync to the posts table is on.
	 *
	 * @return bool
	 */
	public static function syncEnabled(): bool {
		return get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ) === 'yes';
	}

	/**
	 * HPOS orders table name.
	 *
	 * @return string
	 */
	public static function ordersTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'wc_orders';
	}

	/**
	 * HPOS order addresses table name.
	 *
	 * @return string
	 */
	public static function addressesTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'wc_order_addresses';
	}

	/**
	 * HPOS operational data table name.
	 *
	 * @return string
	 */
	public static function operationalDataTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'wc_order_operational_data';
	}

	/**
	 * HPOS order meta table name.
	 *
	 * @return string
	 */
	public static function metaTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'wc_orders_meta';
	}

	/**
	 * Order items table name (shared legacy table).
	 *
	 * @return string
	 */
	public static function itemsTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'woocommerce_order_items';
	}

	/**
	 * Order item meta table name (shared legacy table).
	 *
	 * @return string
	 */
	public static function itemMetaTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'woocommerce_order_itemmeta';
	}

	/**
	 * Every source table an order's data is spread across, keyed by the name
	 * we use inside the archive payload.
	 *
	 * This is also the allowlist: no table name reaches a query unless it came
	 * from here. Nothing user-supplied is ever interpolated into SQL.
	 *
	 * @return array<string, string>
	 */
	public static function sourceTables(): array {
		return array(
			'order'            => self::ordersTable(),
			'addresses'        => self::addressesTable(),
			'operational_data' => self::operationalDataTable(),
			'meta'             => self::metaTable(),
			'items'            => self::itemsTable(),
			'item_meta'        => self::itemMetaTable(),
		);
	}

	/**
	 * Storage engine of the orders table. Archiving runs each order inside a
	 * transaction, which silently does nothing on MyISAM — so we check.
	 */
	public static function supportsTransactions(): bool {
		global $wpdb;

		$table = self::ordersTable();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);

		return is_string( $engine ) && strtoupper( $engine ) === 'INNODB';
	}
}
