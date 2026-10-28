<?php

declare(strict_types=1);

namespace Coldstash\Admin;

/**
 * Enqueues admin CSS/JS, only on the plugin's own screen.
 */
final class Assets {

	/**
	 * Hooks asset enqueueing for the plugin screen.
	 */
	public function register(): void {
		add_action(
			'admin_enqueue_scripts',
			function ( string $hook ): void {
				if ( 'woocommerce_page_coldstash' !== $hook ) {
					return;
				}

				wp_enqueue_style(
					'coldstash-admin',
					COLDSTASH_URL . 'assets/admin.css',
					array(),
					COLDSTASH_VERSION
				);

				wp_enqueue_script(
					'coldstash-admin',
					COLDSTASH_URL . 'assets/admin.js',
					array( 'wp-api-fetch' ),
					COLDSTASH_VERSION,
					true
				);

				wp_localize_script(
					'coldstash-admin',
					'Coldstash',
					array(
						'restUrl' => esc_url_raw( rest_url( 'coldstash/v1' ) ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
					)
				);
			}
		);
	}
}
