<?php
/**
 * PHPUnit bootstrap. Unit tests use Brain Monkey to stub WordPress functions,
 * so no full WP install is required.
 *
 * The archive/restore round-trip cannot be meaningfully unit-tested — it is a
 * database round-trip — so that lives in the DDEV environment instead, driven
 * by `ddev wp coldstash` (see SETUP.md).
 *
 * @package Coldstash
 */

declare(strict_types=1);

$coldstash_autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! is_readable( $coldstash_autoload ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI bootstrap, no WP_Filesystem available.
	fwrite( STDERR, "Dependencies not installed. Run `composer install` first.\n" );
	exit( 1 );
}

require $coldstash_autoload;
