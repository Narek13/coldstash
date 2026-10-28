<?php

declare(strict_types=1);

namespace Coldstash\Archive;

use Coldstash\Settings\Settings;

/**
 * Background batching via Action Scheduler, which ships with WooCommerce — no
 * extra dependency, and it already survives the things WP-Cron does not
 * (overlapping runs, long queues, shared hosting killing PHP mid-request).
 *
 * One action processes one batch and then queues the next only if there is
 * more to do, so a finished run leaves nothing scheduled.
 */
final class Scheduler {

	public const HOOK  = 'coldstash_run_batch';
	public const GROUP = 'coldstash';

	private const LAST_RUN_OPTION = 'coldstash_last_run';

	/**
	 * Wires the service that archives each order.
	 *
	 * @param ArchiveService $archiveService Archives a single order.
	 */
	public function __construct(
		private readonly ArchiveService $archiveService,
	) {
	}

	/**
	 * Hooks the batch runner into Action Scheduler.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'runBatch' ), 10, 1 );
	}

	/**
	 * Starts a new archive run and runs its first batch as soon as possible.
	 * Starting while a run is already queued joins that run instead of adding a second one.
	 *
	 * @return string The run's batch ID.
	 */
	public function start(): string {
		if ( $this->isRunning() ) {
			return (string) get_option( 'coldstash_current_batch', '' );
		}

		$batchId = wp_generate_uuid4();

		// Not autoloaded, so isPaused() can drop it from the cache and read it fresh.
		update_option( 'coldstash_paused', false, false );
		update_option( 'coldstash_current_batch', $batchId );
		update_option(
			self::LAST_RUN_OPTION,
			array(
				'batch_id'     => $batchId,
				'archived'     => 0,
				'failed'       => 0,
				'finished_gmt' => '',
			),
			false
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $batchId ), self::GROUP );
		}

		return $batchId;
	}

	/**
	 * Pauses the run and unschedules every queued batch.
	 */
	public function stop(): void {
		update_option( 'coldstash_paused', true, false );

		// Hook only: with a hook and a group, Action Scheduler would cancel only actions without arguments.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
		}
	}

	/**
	 * Whether a batch is queued or running.
	 *
	 * @return bool
	 */
	public function isRunning(): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		// Null matches any batch ID; an empty array would match only actions without arguments.
		return as_has_scheduled_action( self::HOOK, null, self::GROUP );
	}

	/**
	 * Progress of the current or most recent run.
	 *
	 * @return array{batch_id:string, archived:int, failed:int, finished_gmt:string}|null
	 */
	public function lastRun(): ?array {
		$run = get_option( self::LAST_RUN_OPTION );
		if ( ! is_array( $run ) ) {
			return null;
		}

		return array(
			'batch_id'     => (string) ( $run['batch_id'] ?? '' ),
			'archived'     => (int) ( $run['archived'] ?? 0 ),
			'failed'       => (int) ( $run['failed'] ?? 0 ),
			'finished_gmt' => (string) ( $run['finished_gmt'] ?? '' ),
		);
	}

	/**
	 * Archives one batch of candidates, then queues the next if there is more to do.
	 *
	 * @param string $batchId Run the batch belongs to.
	 */
	public function runBatch( string $batchId = '' ): void {
		if ( $this->isPaused() ) {
			return;
		}

		$settings   = Settings::fromOptions();
		$candidates = ( new CandidateQuery( $settings ) )->candidates( $settings->batchSize );

		$archived = 0;
		$failed   = 0;

		foreach ( $candidates as $orderId ) {
			if ( $this->isPaused() ) {
				break;
			}

			$result = $this->archiveService->archive( $orderId, $batchId );
			if ( $result['ok'] ) {
				++$archived;
			} else {
				++$failed;
			}
		}

		$this->recordProgress( $batchId, $archived, $failed );

		if ( $this->isPaused() ) {
			return;
		}

		if ( count( $candidates ) < $settings->batchSize ) {
			$this->finish( $batchId );
			return;
		}

		$this->queueNext( $batchId );
	}

	/**
	 * Ends the run: nothing left to archive.
	 *
	 * @param string $batchId Run that finished.
	 */
	private function finish( string $batchId ): void {
		update_option( 'coldstash_paused', true, false );

		$run = $this->lastRun();
		if ( null !== $run && $run['batch_id'] === $batchId ) {
			$run['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
			update_option( self::LAST_RUN_OPTION, $run, false );
		}

		/**
		 * Fires when an archive run finishes with nothing left to do.
		 *
		 * @since 1.0.0
		 *
		 * @param string $batchId Run that finished.
		 */
		do_action( 'coldstash_run_completed', $batchId );
	}

	/**
	 * Adds a batch's outcome to the run's totals.
	 *
	 * @param string $batchId  Run the batch belongs to.
	 * @param int    $archived Orders archived in this batch.
	 * @param int    $failed   Orders that failed in this batch.
	 */
	private function recordProgress( string $batchId, int $archived, int $failed ): void {
		$run = $this->lastRun();
		if ( null === $run || $run['batch_id'] !== $batchId ) {
			return;
		}

		$run['archived'] += $archived;
		$run['failed']   += $failed;
		update_option( self::LAST_RUN_OPTION, $run, false );
	}

	/**
	 * Whether the kill switch is on.
	 *
	 * The Stop button and deactivation flip it from another request, so the
	 * in-process cached value would never change mid-batch: drop it and re-read.
	 *
	 * @phpstan-impure
	 *
	 * @return bool
	 */
	private function isPaused(): bool {
		wp_cache_delete( 'coldstash_paused', 'options' );

		return (bool) get_option( 'coldstash_paused' );
	}

	/**
	 * Queues the next batch a minute from now.
	 *
	 * @param string $batchId Run the batch belongs to.
	 */
	private function queueNext( string $batchId ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action( time() + 60, self::HOOK, array( $batchId ), self::GROUP );
	}
}
