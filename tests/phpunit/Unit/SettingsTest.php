<?php

declare(strict_types=1);

namespace Coldstash\Tests\Unit;

use Coldstash\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The settings screen posts straight into Settings::fromArray(), so it is the
 * only thing standing between a typo and archiving orders that are still live.
 */
final class SettingsTest extends TestCase {

	/**
	 * The archive age is clamped to the safe range.
	 */
	public function testAgeIsClampedToTheSafeRange(): void {
		self::assertSame( Settings::MINIMUM_AGE_MONTHS, Settings::fromArray( array( 'age_months' => 1 ) )->ageMonths );
		self::assertSame( Settings::MAXIMUM_AGE_MONTHS, Settings::fromArray( array( 'age_months' => 10000 ) )->ageMonths );
		self::assertSame( 36, Settings::fromArray( array( 'age_months' => 36 ) )->ageMonths );
	}

	/**
	 * Only allowlisted statuses survive; in-progress and refunded ones are dropped.
	 */
	public function testOnlyAllowlistedStatusesSurvive(): void {
		$settings = Settings::fromArray( array( 'statuses' => array( 'wc-processing', 'wc-refunded', 'wc-failed', 'wc-on-hold' ) ) );

		self::assertSame( array( 'wc-failed' ), $settings->statuses );
	}

	/**
	 * Unticking every status falls back to completed orders rather than to nothing or everything.
	 */
	public function testNoStatusesFallsBackToCompleted(): void {
		self::assertSame( array( 'wc-completed' ), Settings::fromArray( array( 'statuses' => array() ) )->statuses );
	}

	/**
	 * Values the screen does not post keep their defaults.
	 */
	public function testMissingValuesKeepDefaults(): void {
		$settings = Settings::fromArray( array() );

		self::assertSame( 24, $settings->ageMonths );
		self::assertSame( array( 'wc-completed', 'wc-cancelled' ), $settings->statuses );
		self::assertTrue( $settings->excludeActiveDownloads );
	}
}
