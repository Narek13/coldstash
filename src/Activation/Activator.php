<?php

declare(strict_types=1);

namespace Coldstash\Activation;

use Coldstash\Storage\Schema;

/**
 * Runs on plugin activation: create/upgrade the archive tables and stamp the
 * installed DB version so later upgrades can migrate.
 *
 * Deliberately does not check for HPOS here — activation should always
 * succeed, and the engine gates itself at runtime with a clear admin notice.
 * Refusing to activate just leaves the merchant with no explanation.
 */
final class Activator {

	/**
	 * Creates the archive tables and records the installed schema version.
	 */
	public static function activate(): void {
		Schema::install();

		add_option( 'coldstash_db_version', Schema::DB_VERSION );
		add_option( 'coldstash_installed_at', time() );
	}
}
