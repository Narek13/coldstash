<?php
/**
 * Constant definitions for PHPStan analysis (loaded via scanFiles).
 * Not executed at runtime — the real values are defined in the plugin bootstrap.
 *
 * @package Coldstash
 */

declare(strict_types=1);

define( 'COLDSTASH_VERSION', '0.0.0' );
define( 'COLDSTASH_FILE', __FILE__ );
define( 'COLDSTASH_DIR', __DIR__ . '/' );
define( 'COLDSTASH_URL', 'https://example.test/' );
define( 'WC_VERSION', '0.0.0' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WooCommerce's own constant.
