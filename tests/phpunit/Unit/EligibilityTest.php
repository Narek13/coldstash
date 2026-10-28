<?php

declare(strict_types=1);

namespace Coldstash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Coldstash\Archive\Eligibility;
use Coldstash\Archive\ExclusionReason;
use Coldstash\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The eligibility rules decide which of a merchant's orders get moved out of
 * their live tables. Getting one wrong archives an order that is still being
 * fulfilled, so every rule gets a test.
 */
final class EligibilityTest extends TestCase {

	private const NOW = 1789574400; // 2026-09-16 12:00:00 UTC

	/**
	 * Boots Brain Monkey and stubs the translation functions.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
	}

	/**
	 * Tears Brain Monkey down again.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Builds the rules under test with a given age threshold.
	 *
	 * @param int $ageMonths Minimum order age in months.
	 * @return Eligibility
	 */
	private function eligibility( int $ageMonths = 24 ): Eligibility {
		return new Eligibility(
			new Settings( ageMonths: $ageMonths, statuses: array( 'wc-completed', 'wc-cancelled' ) )
		);
	}

	/**
	 * Builds a minimal wc_orders row.
	 *
	 * @param string $status  Order status, with the wc- prefix.
	 * @param string $created Creation date in GMT, MySQL format.
	 * @return array<string, mixed>
	 */
	private function order( string $status = 'wc-completed', string $created = '2020-01-01 00:00:00' ): array {
		return array(
			'status'           => $status,
			'date_created_gmt' => $created,
			'parent_order_id'  => 0,
		);
	}

	/**
	 * An old completed order passes every rule.
	 */
	public function testAnOldCompletedOrderIsEligible(): void {
		self::assertNull( $this->eligibility()->check( $this->order(), array(), self::NOW ) );
	}

	/**
	 * An order that is still being fulfilled is never eligible.
	 *
	 * @dataProvider nonTerminalStatuses
	 *
	 * @param string $status Order status, with the wc- prefix.
	 */
	public function testAnOrderStillInProgressIsNeverEligible( string $status ): void {
		self::assertSame(
			ExclusionReason::NotTerminalStatus,
			$this->eligibility()->check( $this->order( $status ), array(), self::NOW )
		);
	}

	/**
	 * Statuses that mean the order is still in progress.
	 *
	 * @return array<string, array{string}>
	 */
	public static function nonTerminalStatuses(): array {
		return array(
			'processing' => array( 'wc-processing' ),
			'on hold'    => array( 'wc-on-hold' ),
			'pending'    => array( 'wc-pending' ),
		);
	}

	/**
	 * An order newer than the age threshold is held back.
	 */
	public function testAnOrderInsideTheAgeThresholdIsHeldBack(): void {
		self::assertSame(
			ExclusionReason::TooRecent,
			$this->eligibility()->check( $this->order( 'wc-completed', '2026-06-01 00:00:00' ), array(), self::NOW )
		);
	}

	/**
	 * An order exactly at the cut-off is still too recent.
	 */
	public function testTheBoundaryIsExclusive(): void {
		// Exactly 24 months old is not yet older than the cut-off.
		$cutoff = ( new Settings( ageMonths: 24 ) )->cutoffGmt( self::NOW );

		self::assertSame(
			ExclusionReason::TooRecent,
			$this->eligibility()->check( $this->order( 'wc-completed', $cutoff ), array(), self::NOW )
		);
	}

	/**
	 * An order with downloads the customer can still use is held back.
	 */
	public function testAnOrderWithActiveDownloadsIsHeldBack(): void {
		self::assertSame(
			ExclusionReason::ActiveDownloads,
			$this->eligibility()->check( $this->order(), array( 'has_active_downloads' => true ), self::NOW )
		);
	}

	/**
	 * Orders owned by Subscriptions, Bookings or Memberships are held back.
	 *
	 * @dataProvider extensionFlags
	 *
	 * @param string          $flag     Flag set by the candidate query.
	 * @param ExclusionReason $expected Reason the order is held back.
	 */
	public function testExtensionOwnedOrdersAreHeldBack( string $flag, ExclusionReason $expected ): void {
		self::assertSame(
			$expected,
			$this->eligibility()->check( $this->order(), array( $flag => true ), self::NOW )
		);
	}

	/**
	 * Extension flags and the reason each one produces.
	 *
	 * @return array<string, array{string, ExclusionReason}>
	 */
	public static function extensionFlags(): array {
		return array(
			'subscriptions' => array( 'is_subscription_related', ExclusionReason::SubscriptionRelated ),
			'bookings'      => array( 'is_booking_related', ExclusionReason::BookingRelated ),
			'memberships'   => array( 'is_membership_related', ExclusionReason::MembershipRelated ),
		);
	}

	/**
	 * A refund follows its parent order: it moves only when the parent may.
	 */
	public function testARefundCannotBeArchivedWithoutItsParent(): void {
		$refund = array(
			'status'           => 'wc-completed',
			'date_created_gmt' => '2020-01-01 00:00:00',
			'parent_order_id'  => 99,
		);

		self::assertSame(
			ExclusionReason::ParentNotEligible,
			$this->eligibility()->check( $refund, array( 'parent_eligible' => false ), self::NOW )
		);

		self::assertNull( $this->eligibility()->check( $refund, array( 'parent_eligible' => true ), self::NOW ) );
	}

	/**
	 * An order that is already in the archive is not archived again.
	 */
	public function testAnAlreadyArchivedOrderIsNotArchivedTwice(): void {
		self::assertSame(
			ExclusionReason::AlreadyArchived,
			$this->eligibility()->check( $this->order(), array( 'already_archived' => true ), self::NOW )
		);
	}

	/**
	 * An order the merchant restored stays live, even though it is still old enough.
	 */
	public function testARestoredOrderIsNotArchivedAgain(): void {
		self::assertSame(
			ExclusionReason::Restored,
			$this->eligibility()->check( $this->order(), array( 'restored' => true ), self::NOW )
		);
	}

	/**
	 * An order with no creation date is treated as too recent.
	 */
	public function testAnOrderWithNoCreationDateIsNeverArchived(): void {
		self::assertSame(
			ExclusionReason::TooRecent,
			$this->eligibility()->check( $this->order( 'wc-completed', '' ), array(), self::NOW )
		);
	}

	/**
	 * Non-terminal statuses are not in the list a merchant can choose from.
	 */
	public function testProcessingCanNeverBeConfiguredAsArchivable(): void {
		self::assertNotContains( 'wc-processing', Settings::ARCHIVABLE_STATUSES );
		self::assertNotContains( 'wc-on-hold', Settings::ARCHIVABLE_STATUSES );
		self::assertNotContains( 'wc-pending', Settings::ARCHIVABLE_STATUSES );
	}
}
