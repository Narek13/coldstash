<?php

declare(strict_types=1);

namespace Coldstash\Storage;

/**
 * Custom database tables.
 *
 * The archive is a hybrid: indexed columns for everything the merchant browses
 * or searches by, plus a complete versioned JSON payload for restore.
 *
 * A pure mirror of WooCommerce's six order tables would break every time Woo
 * adds a column — and it has, repeatedly. A bare JSON blob would be safe but
 * unsearchable. Doing both costs a little disk and solves both problems: the
 * indexed columns never need the payload decoded, and the payload can restore
 * columns that did not exist when this version shipped.
 */
final class Schema {

	public const DB_VERSION = '1';

	/**
	 * Archived orders table name.
	 *
	 * @return string
	 */
	public static function ordersTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'coldstash_orders';
	}

	/**
	 * Per-order run log table name.
	 *
	 * @return string
	 */
	public static function runsTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'coldstash_runs';
	}

	/**
	 * Creates or upgrades both tables via dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$orders  = self::ordersTable();
		$runs    = self::runsTable();

		$sql = array();

		/*
		 * `id` is the original order ID, not an auto-increment. Order numbers
		 * appear in the merchant's accounting, shipping labels and support
		 * history, so a restored order has to come back as the same order.
		 */
		$sql[] = "CREATE TABLE {$orders} (
            id BIGINT UNSIGNED NOT NULL,
            parent_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT '',
            type VARCHAR(20) NOT NULL DEFAULT 'shop_order',
            currency CHAR(3) NOT NULL DEFAULT '',
            total_amount DECIMAL(26,8) NOT NULL DEFAULT 0,
            customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            billing_email VARCHAR(320) NOT NULL DEFAULT '',
            billing_name VARCHAR(200) NOT NULL DEFAULT '',
            order_key VARCHAR(100) NOT NULL DEFAULT '',
            payment_method VARCHAR(100) NOT NULL DEFAULT '',
            transaction_id VARCHAR(100) NOT NULL DEFAULT '',
            item_summary TEXT NULL,
            date_created_gmt DATETIME NULL,
            date_paid_gmt DATETIME NULL,
            payload LONGTEXT NOT NULL,
            payload_schema SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            payload_checksum CHAR(64) NOT NULL DEFAULT '',
            source_wc_version VARCHAR(20) NOT NULL DEFAULT '',
            archived_at DATETIME NOT NULL,
            batch_id CHAR(36) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY parent_order_id (parent_order_id),
            KEY customer_id (customer_id),
            KEY billing_email (billing_email),
            KEY order_key (order_key),
            KEY transaction_id (transaction_id),
            KEY date_created_gmt (date_created_gmt),
            KEY archived_at (archived_at),
            KEY batch_id (batch_id),
            KEY status (status)
        ) {$charset};";

		$sql[] = "CREATE TABLE {$runs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id CHAR(36) NOT NULL,
            direction VARCHAR(10) NOT NULL DEFAULT 'archive',
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            result VARCHAR(12) NOT NULL DEFAULT 'ok',
            reason VARCHAR(64) NOT NULL DEFAULT '',
            message TEXT NULL,
            logged_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY batch_id (batch_id),
            KEY order_id (order_id),
            KEY result (result),
            KEY logged_at (logged_at)
        ) {$charset};";

		dbDelta( $sql );
	}
}
