<?php

declare(strict_types=1);

namespace Coldstash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Coldstash\Archive\ArchiveService;
use Coldstash\Archive\PayloadBuilder;
use Coldstash\Archive\Scheduler;
use Coldstash\Storage\ArchiveRepository;
use PHPUnit\Framework\TestCase;

/**
 * Action Scheduler treats an empty args array as "only actions without
 * arguments" and null as "any arguments". Batches carry a batch ID, so getting
 * this wrong makes a running archive look idle and leaves Stop unable to cancel it.
 */
final class SchedulerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Boots Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears Brain Monkey down again.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A scheduler with a real (but unused) archive service.
	 *
	 * @return Scheduler
	 */
	private function scheduler(): Scheduler {
		return new Scheduler( new ArchiveService( new PayloadBuilder(), new ArchiveRepository() ) );
	}

	/**
	 * The running check matches queued batches whatever their batch ID.
	 */
	public function testIsRunningMatchesBatchesWithAnyArguments(): void {
		Functions\expect( 'as_has_scheduled_action' )
			->once()
			->with( Scheduler::HOOK, null, Scheduler::GROUP )
			->andReturn( true );

		self::assertTrue( $this->scheduler()->isRunning() );
	}

	/**
	 * Stop cancels every queued batch by hook, not just argument-less ones.
	 */
	public function testStopUnschedulesEveryBatch(): void {
		Functions\expect( 'update_option' )->once()->with( 'coldstash_paused', true, false );
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( Scheduler::HOOK );

		$this->scheduler()->stop();
	}

	/**
	 * Starting while a run is queued joins it instead of queueing a second run.
	 */
	public function testStartWhileRunningDoesNotQueueAnotherRun(): void {
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 'existing-batch' );
		Functions\expect( 'as_enqueue_async_action' )->never();
		Functions\expect( 'update_option' )->never();

		self::assertSame( 'existing-batch', $this->scheduler()->start() );
	}

	/**
	 * Starting from idle queues the first batch to run right away.
	 */
	public function testStartQueuesTheFirstBatchImmediately(): void {
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'new-batch' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Scheduler::HOOK, array( 'new-batch' ), Scheduler::GROUP );

		self::assertSame( 'new-batch', $this->scheduler()->start() );
	}
}
