<?php

declare(strict_types=1);

namespace Coldstash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Coldstash\Archive\BulkRestore;
use Coldstash\Archive\RestoreService;
use Coldstash\Archive\Scheduler;
use Coldstash\Storage\ArchiveRepository;
use PHPUnit\Framework\TestCase;

/**
 * "Restore all" is the merchant's way out, so its queue handling must not have
 * the empty-args mistake: restore batches carry a run ID.
 */
final class BulkRestoreTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Boots Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	/**
	 * Tears Brain Monkey down again.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A bulk restore with real (but unused) collaborators.
	 *
	 * @return BulkRestore
	 */
	private function bulk(): BulkRestore {
		$archive = new ArchiveRepository();

		return new BulkRestore( new RestoreService( $archive ), $archive );
	}

	/**
	 * The running check matches queued batches whatever their run ID.
	 */
	public function testIsRunningMatchesBatchesWithAnyArguments(): void {
		Functions\expect( 'as_has_scheduled_action' )
			->once()
			->with( BulkRestore::HOOK, null, Scheduler::GROUP )
			->andReturn( true );

		self::assertTrue( $this->bulk()->isRunning() );
	}

	/**
	 * Stop marks the run stopped and cancels every queued batch by hook.
	 */
	public function testStopMarksTheRunAndUnschedulesEveryBatch(): void {
		Functions\when( 'get_option' )->justReturn( array( 'run_id' => 'run-1' ) );
		Functions\expect( 'update_option' )
			->once()
			->with( 'coldstash_bulk_restore', \Mockery::on( static fn ( array $p ): bool => true === $p['stopped'] && '' !== $p['finished_gmt'] ), false );
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( BulkRestore::HOOK );

		$this->bulk()->stop();
	}

	/**
	 * Starting while a restore is queued joins it instead of starting over.
	 */
	public function testStartWhileRunningJoinsTheRun(): void {
		Functions\when( 'get_option' )->justReturn( array( 'run_id' => 'run-1' ) );
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		Functions\expect( 'as_enqueue_async_action' )->never();

		self::assertSame( 'run-1', $this->bulk()->start() );
	}

	/**
	 * A batch for a stopped or superseded run does nothing.
	 */
	public function testABatchForAStoppedRunDoesNothing(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'run_id'  => 'run-1',
				'stopped' => true,
			)
		);
		Functions\expect( 'as_enqueue_async_action' )->never();
		Functions\expect( 'update_option' )->never();

		$this->bulk()->runBatch( 'run-1' );
	}
}
