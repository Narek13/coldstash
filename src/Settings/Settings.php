<?php

declare(strict_types=1);

namespace Coldstash\Settings;

/**
 * Plugin settings, resolved once and passed around as a value object rather
 * than re-read from options deep inside the engine.
 */
final class Settings {

	/**
	 * Statuses that may ever be archived. `pending`, `processing` and
	 * `on-hold` are absent on purpose and cannot be added through settings —
	 * archiving an order that is still moving is how you lose a shipment.
	 * `refunded` is absent because refunds are not archived yet, so a refunded
	 * order would always be held back.
	 */
	public const ARCHIVABLE_STATUSES = array( 'wc-completed', 'wc-cancelled', 'wc-failed' );

	public const OPTION = 'coldstash_settings';

	public const MINIMUM_AGE_MONTHS = 12;

	public const MAXIMUM_AGE_MONTHS = 240;

	/**
	 * Constructor. Defaults are the out-of-the-box settings.
	 *
	 * @param int          $ageMonths              Minimum order age, in months.
	 * @param list<string> $statuses               Statuses to archive.
	 * @param int          $batchSize              Orders per background batch.
	 * @param bool         $showInMyAccount        Show archived orders in My Account.
	 * @param bool         $excludeActiveDownloads Skip orders with live downloads.
	 */
	public function __construct(
		public readonly int $ageMonths = 24,
		public readonly array $statuses = array( 'wc-completed', 'wc-cancelled' ),
		public readonly int $batchSize = 50,
		public readonly bool $showInMyAccount = true,
		public readonly bool $excludeActiveDownloads = true,
	) {
	}

	/**
	 * Reads and sanitizes the saved settings, falling back to defaults.
	 *
	 * @return self
	 */
	public static function fromOptions(): self {
		$saved = get_option( self::OPTION, array() );

		return self::fromArray( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Builds settings from saved or submitted values, clamped to what is safe.
	 *
	 * @param array<string, mixed> $saved Values keyed like toArray(); missing keys use defaults.
	 * @return self
	 */
	public static function fromArray( array $saved ): self {
		$defaults = new self();

		return new self(
			ageMonths: max( self::MINIMUM_AGE_MONTHS, min( self::MAXIMUM_AGE_MONTHS, (int) ( $saved['age_months'] ?? $defaults->ageMonths ) ) ),
			statuses: self::sanitizeStatuses( $saved['statuses'] ?? $defaults->statuses ),
			batchSize: max( 1, min( 500, (int) ( $saved['batch_size'] ?? $defaults->batchSize ) ) ),
			showInMyAccount: (bool) ( $saved['show_in_my_account'] ?? $defaults->showInMyAccount ),
			excludeActiveDownloads: (bool) ( $saved['exclude_active_downloads'] ?? $defaults->excludeActiveDownloads ),
		);
	}

	/**
	 * Keeps only statuses from the archivable allowlist.
	 *
	 * @param mixed $statuses Saved option value.
	 * @return list<string>
	 */
	private static function sanitizeStatuses( mixed $statuses ): array {
		if ( ! is_array( $statuses ) ) {
			return array( 'wc-completed' );
		}

		$clean = array_values(
			array_intersect(
				array_map( 'strval', $statuses ),
				self::ARCHIVABLE_STATUSES
			)
		);

		return array() !== $clean ? $clean : array( 'wc-completed' );
	}

	/**
	 * Cut-off date: orders created before this are old enough to archive.
	 *
	 * @param int|null $now Reference timestamp; defaults to the current time.
	 * @return string GMT datetime.
	 */
	public function cutoffGmt( ?int $now = null ): string {
		$now  ??= time();
		$cutoff = strtotime( "-{$this->ageMonths} months", $now );

		return gmdate( 'Y-m-d H:i:s', false !== $cutoff ? $cutoff : $now );
	}

	/**
	 * Settings as a plain array, keyed like the saved option.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'age_months'               => $this->ageMonths,
			'statuses'                 => $this->statuses,
			'batch_size'               => $this->batchSize,
			'show_in_my_account'       => $this->showInMyAccount,
			'exclude_active_downloads' => $this->excludeActiveDownloads,
		);
	}
}
