<?php
/**
 * PHPStan bootstrap.
 *
 * WooCommerce is not a Composer dependency (it is a runtime peer), so the
 * WooCommerce classes this plugin touches are declared here as stubs. Without
 * them PHPStan reports every WooCommerce symbol as unknown and the real
 * findings drown in the noise.
 *
 * @package Coldstash
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter -- Stubs of WooCommerce's own classes: they must keep WooCommerce's namespace and signatures.

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( OrderUtil::class ) ) {
	/**
	 * Stub of WooCommerce's OrderUtil.
	 */
	class OrderUtil {
		/**
		 * Whether HPOS is the authoritative order store.
		 *
		 * @return bool
		 */
		public static function custom_orders_table_usage_is_enabled(): bool {
			return true;
		}
	}
}

if ( ! class_exists( FeaturesUtil::class ) ) {
	/**
	 * Stub of WooCommerce's FeaturesUtil.
	 */
	class FeaturesUtil {
		/**
		 * Declares a plugin's compatibility with a WooCommerce feature.
		 *
		 * @param string $feature  Feature ID, e.g. custom_order_tables.
		 * @param string $file     Main plugin file.
		 * @param bool   $positive Whether the plugin is compatible.
		 * @return bool
		 */
		public static function declare_compatibility( string $feature, string $file, bool $positive = true ): bool {
			return true;
		}
	}
}
