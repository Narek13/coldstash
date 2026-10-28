<?php
/**
 * Uninstall — runs only when the plugin is deleted from the WordPress admin.
 *
 * Unlike most plugins, deleting this one must NOT drop its tables by default.
 * The archive tables hold the merchant's real order data; dropping them on an
 * accidental delete would be catastrophic and unrecoverable. So the archive
 * survives uninstall unless the merchant explicitly opted in beforehand.
 *
 * The archive browser offers "Restore all" as the safe exit route, and the
 * settings screen only offers the opt-in once the archive is empty.
 *
 * @package Coldstash
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'coldstash_db_version' );
delete_option( 'coldstash_installed_at' );
delete_option( 'coldstash_settings' );
delete_option( 'coldstash_backup_acknowledged' );
delete_option( 'coldstash_paused' );
delete_option( 'coldstash_current_batch' );
delete_option( 'coldstash_last_run' );
delete_option( 'coldstash_bulk_restore' );

// Explicit opt-in only. Default is to leave the archive in place.
if ( ! get_option( 'coldstash_drop_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// Never drop archived orders, even with the opt-in: the settings screen only offers it once the archive is empty.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$coldstash_remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'coldstash_orders' ) );
if ( (int) $coldstash_remaining > 0 ) {
	return;
}

$coldstash_tables = array(
	$wpdb->prefix . 'coldstash_orders',
	$wpdb->prefix . 'coldstash_runs',
);

foreach ( $coldstash_tables as $coldstash_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $coldstash_table ) );
}

delete_option( 'coldstash_drop_data_on_uninstall' );
