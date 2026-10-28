<?php

declare(strict_types=1);

namespace Coldstash\Admin;

use Coldstash\Storage\ArchiveRepository;

/**
 * Protects the one thing that makes this plugin safe.
 *
 * Archiving deliberately leaves the WooCommerce Analytics lookup tables alone,
 * so historical reports are unchanged after an order is archived. The catch is
 * that Woo's "Regenerate Analytics data" tool rebuilds those tables *from* the
 * order tables — run it after archiving and the archived orders drop out of
 * every report.
 *
 * We cannot block the tool outright (merchants legitimately need it), so we
 * make the consequence impossible to miss and offer the safe route: restore
 * everything first, regenerate, archive again.
 */
final class AnalyticsGuard {

	/**
	 * Constructor.
	 *
	 * @param ArchiveRepository $archive Archive storage.
	 */
	public function __construct( private readonly ArchiveRepository $archive ) {
	}

	/**
	 * Hooks the tool annotations and the status-screen notice.
	 */
	public function register(): void {
		add_filter( 'woocommerce_debug_tools', array( $this, 'annotateTools' ), 20 );
		add_action( 'admin_notices', array( $this, 'maybeWarn' ) );
	}

	/**
	 * Appends a warning to the Analytics regeneration tools while orders are archived.
	 *
	 * @param array<string, array<string, mixed>> $tools WooCommerce debug tools, keyed by tool ID.
	 * @return array<string, array<string, mixed>>
	 */
	public function annotateTools( array $tools ): array {
		$archived = $this->archive->count();
		if ( 0 === $archived ) {
			return $tools;
		}

		foreach ( array( 'clear_woocommerce_analytics_cache', 'delete_analytics_data_and_resync' ) as $key ) {
			if ( ! isset( $tools[ $key ] ) ) {
				continue;
			}

			$warning = sprintf(
				/* translators: %s: number of archived orders. */
				__( 'Warning: %s archived orders are not in the live order tables. Regenerating will remove them from all reports. Restore them first: WooCommerce → Coldstash → Archived orders → Restore all.', 'coldstash' ),
				number_format_i18n( $archived )
			);

			$tools[ $key ]['desc'] = ( $tools[ $key ]['desc'] ?? '' ) . '<br><strong>' . esc_html( $warning ) . '</strong>';
		}

		return $tools;
	}

	/**
	 * Shows a notice on WooCommerce → Status while orders are archived.
	 */
	public function maybeWarn(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! str_contains( (string) $screen->id, 'wc-status' ) ) {
			return;
		}

		$archived = $this->archive->count();
		if ( 0 === $archived ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of archived orders. */
					__( 'Coldstash: %s orders are archived. Analytics reports still include them — but regenerating Analytics data will not.', 'coldstash' ),
					number_format_i18n( $archived )
				)
			)
		);
	}
}
