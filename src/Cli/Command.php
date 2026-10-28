<?php

declare(strict_types=1);

namespace Coldstash\Cli;

use Coldstash\Archive\CandidateQuery;
use Coldstash\Settings\Settings;
use Coldstash\Plugin;
use WP_CLI;

/**
 * WP-CLI interface.
 *
 * Everything the admin screen can do is available here first — a merchant with
 * 900,000 orders is far more likely to run the first pass over SSH than to
 * leave a browser tab open, and support will ask for CLI output anyway.
 *
 * wp coldstash preview
 * wp coldstash run [--batch=<n>] [--max=<n>]
 * wp coldstash status
 * wp coldstash restore <id>|--all
 * wp coldstash verify
 */
final class Command {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * Registers the `wp coldstash` command.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public static function register( Plugin $plugin ): void {
		WP_CLI::add_command( 'coldstash', new self( $plugin ) );
	}

	/**
	 * Show what would be archived, without changing anything.
	 */
	public function preview(): void {
		$preview = ( new CandidateQuery( Settings::fromOptions() ) )->preview();

		WP_CLI::line( sprintf( 'Live orders:      %d', $preview['total'] ) );
		WP_CLI::line( sprintf( 'Eligible now:     %d', $preview['eligible'] ) );
		WP_CLI::line( sprintf( 'Already archived: %d', $this->plugin->archiveRepository()->count() ) );
		WP_CLI::line( '' );
		WP_CLI::line( 'Excluded:' );
		foreach ( $preview['excluded'] as $reason => $count ) {
			WP_CLI::line( sprintf( '  %-22s %d', $reason, $count ) );
		}
	}

	/**
	 * Archive eligible orders now, in the foreground.
	 *
	 * @param array<int, string>    $args  Positional arguments (unused).
	 * @param array<string, string> $assoc Associative arguments: batch, max.
	 */
	public function run( array $args, array $assoc ): void {
		$settings = Settings::fromOptions();
		$batch    = (int) ( $assoc['batch'] ?? $settings->batchSize );
		$max      = (int) ( $assoc['max'] ?? 0 );

		$batchId = wp_generate_uuid4();
		$service = $this->plugin->archiveService();
		$done    = 0;
		$failed  = 0;

		while ( true ) {
			$candidates = ( new CandidateQuery( $settings ) )->candidates( $batch );
			if ( array() === $candidates ) {
				break;
			}

			foreach ( $candidates as $orderId ) {
				$result = $service->archive( $orderId, $batchId );

				if ( $result['ok'] ) {
					++$done;
				} else {
					++$failed;
					WP_CLI::warning( sprintf( '#%d skipped (%s): %s', $orderId, $result['reason'], $result['message'] ) );
				}

				if ( $max > 0 && $done >= $max ) {
					break 2;
				}
			}
		}

		WP_CLI::success( sprintf( 'Archived %d order(s), %d failure(s). Batch %s.', $done, $failed, $batchId ) );
	}

	/**
	 * Current archive size and whether a background run is queued.
	 */
	public function status(): void {
		$summary = $this->plugin->archiveRepository()->summary();

		WP_CLI::line( sprintf( 'Archived orders: %d', $summary['archived'] ) );
		WP_CLI::line( sprintf( 'Oldest archived: %s', $summary['oldest'] ?? '—' ) );
		WP_CLI::line( sprintf( 'Newest archived: %s', $summary['newest'] ?? '—' ) );
		WP_CLI::line( sprintf( 'Background run:  %s', $this->plugin->scheduler()->isRunning() ? 'queued' : 'idle' ) );
	}

	/**
	 * Restore one order, or everything.
	 *
	 * @param array<int, string>    $args  Positional arguments: the order ID.
	 * @param array<string, string> $assoc Associative arguments: all.
	 */
	public function restore( array $args, array $assoc ): void {
		$service = $this->plugin->restoreService();
		$batchId = wp_generate_uuid4();

		if ( ! empty( $assoc['all'] ) ) {
			$done   = 0;
			$failed = 0;

			while ( true ) {
				$rows = $this->plugin->archiveRepository()->search( '', 100 );
				if ( array() === $rows ) {
					break;
				}

				foreach ( $rows as $row ) {
					$result = $service->restore( (int) $row['id'], $batchId, false );
					if ( $result['ok'] ) {
						++$done;
					} else {
						++$failed;
						WP_CLI::warning( sprintf( '#%d: %s', $row['id'], $result['message'] ) );
						// Stop rather than spin forever on an unrestorable row.
						if ( $failed > 25 ) {
							WP_CLI::error( 'Too many failures; stopping.' );
						}
					}
				}
			}

			WP_CLI::success( sprintf( 'Restored %d order(s), %d failure(s).', $done, $failed ) );

			return;
		}

		$orderId = (int) ( $args[0] ?? 0 );
		if ( $orderId <= 0 ) {
			WP_CLI::error( 'Pass an order ID or --all.' );
		}

		$result = $service->restore( $orderId, $batchId );

		if ( ! $result['ok'] ) {
			WP_CLI::error( sprintf( '#%d not restored (%s): %s', $orderId, $result['reason'], $result['message'] ) );
		}

		WP_CLI::success( sprintf( 'Restored order #%d.', $orderId ) );
	}

	/**
	 * Re-check every archived payload against its stored checksum.
	 */
	public function verify(): void {
		$repo    = $this->plugin->archiveRepository();
		$offset  = 0;
		$bad     = 0;
		$checked = 0;

		while ( true ) {
			$rows = $repo->search( '', 200, $offset );
			if ( array() === $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				$full = $repo->find( (int) $row['id'] );
				if ( null === $full ) {
					continue;
				}

				++$checked;
				if ( hash( 'sha256', (string) $full['payload'] ) !== (string) $full['payload_checksum'] ) {
					++$bad;
					WP_CLI::warning( sprintf( '#%d checksum mismatch.', $row['id'] ) );
				}
			}

			$offset += 200;
		}

		if ( $bad > 0 ) {
			WP_CLI::error( sprintf( '%d of %d archived order(s) failed verification.', $bad, $checked ) );
		}

		WP_CLI::success( sprintf( 'All %d archived order(s) verified.', $checked ) );
	}
}
