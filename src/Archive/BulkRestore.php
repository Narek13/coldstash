<?php

declare(strict_types=1);

namespace Coldstash\Archive;

use Coldstash\Storage\ArchiveRepository;

/**
 * "Restore all": moves every archived order back to the live tables in
 * background batches, the safe way out before uninstalling or before
 * regenerating WooCommerce Analytics.
 *
 * Walks the archive by order ID with a cursor, so an order that fails to
 * restore stays in the archive, is counted, and is not retried forever.
 */
final class BulkRestore {

	public const HOOK = 'coldstash_restore_batch';

	private const BATCH_SIZE = 50;

	private const PROGRESS_OPTION = 'coldstash_bulk_restore';

	/**
	 * Wires the restore engine and the archive it reads from.
	 *
	 * @param RestoreService    $restore Restores a single order.
	 * @param ArchiveRepository $archive Archive table access.
	 */
	public function __construct(
		private readonly RestoreService $restore,
		private readonly ArchiveRepository $archive,
	) {
	}

	/**
	 * Hooks the batch runner into Action Scheduler.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'runBatch' ), 10, 1 );
	}

	/**
	 * Starts restoring everything; joins a restore that is already queued.
	 *
	 * @return string The run's ID.
	 */
	public function start(): string {
		$progress = $this->progress();
		if ( $this->isRunning() && null !== $progress ) {
			return $progress['run_id'];
		}

		$runId = wp_generate_uuid4();

		update_option(
			self::PROGRESS_OPTION,
			array(
				'run_id'       => $runId,
				'cursor'       => 0,
				'restored'     => 0,
				'failed'       => 0,
				'stopped'      => false,
				'finished_gmt' => '',
			),
			false
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $runId ), Scheduler::GROUP );
		}

		return $runId;
	}

	/**
	 * Stops after the current order and unschedules every queued batch.
	 */
	public function stop(): void {
		$progress = $this->progress();
		if ( null !== $progress ) {
			$progress['stopped']      = true;
			$progress['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
			$this->save( $progress );
		}

		// Hook only: with a hook and a group, Action Scheduler would cancel only actions without arguments.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
		}
	}

	/**
	 * Whether a restore batch is queued or running.
	 *
	 * @return bool
	 */
	public function isRunning(): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		return as_has_scheduled_action( self::HOOK, null, Scheduler::GROUP );
	}

	/**
	 * Progress of the current or most recent "Restore all".
	 *
	 * @return array{run_id:string, cursor:int, restored:int, failed:int, stopped:bool, finished_gmt:string}|null
	 */
	public function progress(): ?array {
		wp_cache_delete( self::PROGRESS_OPTION, 'options' );
		$progress = get_option( self::PROGRESS_OPTION );
		if ( ! is_array( $progress ) ) {
			return null;
		}

		return array(
			'run_id'       => (string) ( $progress['run_id'] ?? '' ),
			'cursor'       => (int) ( $progress['cursor'] ?? 0 ),
			'restored'     => (int) ( $progress['restored'] ?? 0 ),
			'failed'       => (int) ( $progress['failed'] ?? 0 ),
			'stopped'      => (bool) ( $progress['stopped'] ?? false ),
			'finished_gmt' => (string) ( $progress['finished_gmt'] ?? '' ),
		);
	}

	/**
	 * Restores the next batch of archived orders, then queues another if any are left.
	 *
	 * @param string $runId Run the batch belongs to.
	 */
	public function runBatch( string $runId = '' ): void {
		$progress = $this->progress();
		if ( null === $progress || $progress['run_id'] !== $runId || $progress['stopped'] ) {
			return;
		}

		$ids = $this->archive->idsAfter( $progress['cursor'], self::BATCH_SIZE );

		foreach ( $ids as $orderId ) {
			if ( $this->stopRequested() ) {
				$progress['stopped']      = true;
				$progress['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
				$this->save( $progress );
				return;
			}

			$result = $this->restore->restore( $orderId, $runId, false );
			if ( $result['ok'] ) {
				++$progress['restored'];
			} else {
				++$progress['failed'];
			}
			$progress['cursor'] = $orderId;
		}

		$progress['stopped'] = $this->stopRequested();

		if ( $progress['stopped'] || count( $ids ) < self::BATCH_SIZE ) {
			$progress['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
		}

		$this->save( $progress );

		if ( $progress['stopped'] || '' !== $progress['finished_gmt'] ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $runId ), Scheduler::GROUP );
		}
	}

	/**
	 * Whether Stop was clicked since the batch started, read fresh from the database.
	 *
	 * @return bool
	 */
	private function stopRequested(): bool {
		$progress = $this->progress();

		return null === $progress || $progress['stopped'];
	}

	/**
	 * Saves progress without autoloading it, so progress() can read it fresh.
	 *
	 * @param array{run_id:string, cursor:int, restored:int, failed:int, stopped:bool, finished_gmt:string} $progress Progress to save.
	 */
	private function save( array $progress ): void {
		update_option( self::PROGRESS_OPTION, $progress, false );
	}
}
