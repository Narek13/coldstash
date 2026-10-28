<?php
/**
 * Plugin Name:       Coldstash – Archive Old Orders for WooCommerce
 * Description:       Archive old WooCommerce orders out of the live order tables, without deleting them: searchable, restorable with the original order number, and Analytics reports unchanged.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:   11.2
 * Author:            Narek Zakarian
 * Author URI:        https://profile.wordpress.org/nareknovembit
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coldstash
 *
 * @package Coldstash
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( defined( 'COLDSTASH_VERSION' ) ) {
	return;
}

define( 'COLDSTASH_VERSION', '1.0.0' );
define( 'COLDSTASH_FILE', __FILE__ );
define( 'COLDSTASH_DIR', plugin_dir_path( __FILE__ ) );
define( 'COLDSTASH_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloading.
 *
 * Prefer Composer's autoloader if the plugin has been built (vendor/ present).
 * Otherwise fall back to a tiny PSR-4 autoloader so the plugin runs as soon as
 * it's dropped into wp-content/plugins, no build step required.
 */
if ( is_readable( COLDSTASH_DIR . 'vendor/autoload.php' ) ) {
	require COLDSTASH_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Coldstash\\';
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$path     = COLDSTASH_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require $path;
			}
		}
	);
}

use Coldstash\Activation\Activator;
use Coldstash\Activation\Deactivator;
use Coldstash\Plugin;

/*
 * Declare HPOS compatibility.
 *
 * Must run on before_woocommerce_init — later is too late, WooCommerce has
 * already decided which data store to load. This plugin is HPOS-only; the
 * engine refuses to run against legacy post storage (see Support\Hpos).
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
