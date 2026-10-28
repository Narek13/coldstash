<?php

declare(strict_types=1);

namespace Coldstash\Activation;

/**
 * Runs on deactivation. Archived data is kept — merchants toggle plugins, and
 * their orders must not depend on this plugin being active to survive.
 * Destructive cleanup lives in uninstall.php, behind an explicit opt-in.
 *
 * We do cancel queued work, so a deactivated plugin never keeps archiving in
 * the background.
 */
final class Deactivator {

	/**
	 * Cancels queued batches and pauses the engine; archived data is kept.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'coldstash_run_batch' );
			as_unschedule_all_actions( 'coldstash_restore_batch' );
		}

		// Not autoloaded: Scheduler re-reads it fresh between orders.
		update_option( 'coldstash_paused', true, false );
	}
}
