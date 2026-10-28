<?php

declare(strict_types=1);

namespace Coldstash\Archive;

/**
 * Why an order was not archived.
 *
 * Every skipped order carries one of these into the preview screen. A merchant
 * with 900,000 orders who is offered 40,000 candidates will immediately ask
 * where the rest went, and "we excluded them" is not an answer.
 */
enum ExclusionReason: string {

	case NotTerminalStatus   = 'not_terminal_status';
	case TooRecent           = 'too_recent';
	case ActiveDownloads     = 'active_downloads';
	case SubscriptionRelated = 'subscription_related';
	case BookingRelated      = 'booking_related';
	case MembershipRelated   = 'membership_related';
	case ParentNotEligible   = 'parent_not_eligible';
	case AlreadyArchived     = 'already_archived';
	case Restored            = 'restored';
	case HasRefunds          = 'has_refunds';
	case AnalyticsPending    = 'analytics_pending';
	case FilteredOut         = 'filtered_out';

	/**
	 * Human-readable reason for the preview screen.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) { // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- enum methods are an object context.
			self::NotTerminalStatus => __( 'Order is still in progress', 'coldstash' ),
			self::TooRecent => __( 'Newer than the archive age threshold', 'coldstash' ),
			self::ActiveDownloads => __( 'Customer still has active download permissions', 'coldstash' ),
			self::SubscriptionRelated => __( 'Linked to a subscription', 'coldstash' ),
			self::BookingRelated => __( 'Linked to a booking', 'coldstash' ),
			self::MembershipRelated => __( 'Grants an active membership', 'coldstash' ),
			self::ParentNotEligible => __( 'Its parent order cannot be archived yet', 'coldstash' ),
			self::AlreadyArchived => __( 'Already archived', 'coldstash' ),
			self::Restored => __( 'Restored from the archive, so kept live', 'coldstash' ),
			self::HasRefunds => __( 'Has refunds, which are not archived yet', 'coldstash' ),
			self::AnalyticsPending => __( 'WooCommerce Analytics has not finished syncing it yet', 'coldstash' ),
			self::FilteredOut => __( 'Excluded by a site-specific rule', 'coldstash' ),
		};
	}
}
