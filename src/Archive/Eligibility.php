<?php

declare(strict_types=1);

namespace Coldstash\Archive;

use Coldstash\Settings\Settings;

/**
 * Decides whether one order may be archived.
 *
 * Kept deliberately free of $wpdb and WooCommerce objects: it takes a plain
 * row plus a few pre-computed flags, so the rules can be exercised in the
 * standalone smoke test without standing up WordPress. The expensive lookups
 * (downloads, subscriptions) happen once in the candidate query, not here.
 */
final class Eligibility {

	/**
	 * Takes the archive settings the rules read from.
	 *
	 * @param Settings $settings Archive settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Returns why an order may not be archived, or null if it may.
	 *
	 * @param array<string, mixed> $order Row from wc_orders (at least: status, type, date_created_gmt).
	 * @param array<string, bool>  $flags has_active_downloads, is_subscription_related,
	 *                                    is_booking_related, is_membership_related,
	 *                                    already_archived, restored, parent_eligible.
	 * @param int|null             $now   Unix timestamp to measure age from; defaults to now.
	 * @return ExclusionReason|null
	 */
	public function check( array $order, array $flags = array(), ?int $now = null ): ?ExclusionReason {
		if ( $flags['already_archived'] ?? false ) {
			return ExclusionReason::AlreadyArchived;
		}

		if ( $flags['restored'] ?? false ) {
			return ExclusionReason::Restored;
		}

		$status = (string) ( $order['status'] ?? '' );
		if ( ! in_array( $status, $this->settings->statuses, true ) ) {
			return ExclusionReason::NotTerminalStatus;
		}

		$created = (string) ( $order['date_created_gmt'] ?? '' );
		if ( '' === $created || $created >= $this->settings->cutoffGmt( $now ) ) {
			return ExclusionReason::TooRecent;
		}

		if ( $this->settings->excludeActiveDownloads && ( $flags['has_active_downloads'] ?? false ) ) {
			return ExclusionReason::ActiveDownloads;
		}

		if ( $flags['is_subscription_related'] ?? false ) {
			return ExclusionReason::SubscriptionRelated;
		}

		if ( $flags['is_booking_related'] ?? false ) {
			return ExclusionReason::BookingRelated;
		}

		if ( $flags['is_membership_related'] ?? false ) {
			return ExclusionReason::MembershipRelated;
		}

		// A refund can only be archived alongside the order it belongs to.
		if ( (int) ( $order['parent_order_id'] ?? 0 ) > 0 && ! ( $flags['parent_eligible'] ?? false ) ) {
			return ExclusionReason::ParentNotEligible;
		}

		return null;
	}
}
