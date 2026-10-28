<?php

declare(strict_types=1);

namespace Coldstash;

use Coldstash\Admin\AdminMenu;
use Coldstash\Admin\AnalyticsGuard;
use Coldstash\Admin\Assets;
use Coldstash\Archive\ArchiveService;
use Coldstash\Archive\BulkRestore;
use Coldstash\Archive\PayloadBuilder;
use Coldstash\Archive\RestoreService;
use Coldstash\Archive\Scheduler;
use Coldstash\Settings\Settings;
use Coldstash\Cli\Command;
use Coldstash\Privacy\Privacy;
use Coldstash\Rest\RestController;
use Coldstash\Storage\ArchiveRepository;
use Coldstash\Support\Hpos;

/**
 * Plugin container + bootstrap.
 *
 * Deliberately a tiny service locator rather than a heavy DI container — a WP
 * plugin should boot fast and stay simple. Services are lazily instantiated
 * and shared.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Shared service instances keyed by class name.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Whether the plugin has already booted.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Returns the singleton plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	/**
	 * Private constructor — use instance() instead.
	 */
	private function __construct() {
	}

	/**
	 * Boots the plugin: text domain, admin UI, REST routes, CLI and the scheduler.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'renderWooCommerceNotice' ) );

			return;
		}

		// Admin UI loads either way, so the merchant can see *why* it is idle.
		if ( is_admin() ) {
			$this->adminMenu()->register();
			$this->assets()->register();
			$this->analyticsGuard()->register();
			add_action( 'admin_notices', array( $this, 'renderHposNotice' ) );
		}

		add_action( 'rest_api_init', fn () => $this->rest()->registerRoutes() );

		$this->privacy()->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Command::register( $this );
		}

		if ( ! Hpos::isEnabled() ) {
			return;
		}

		$this->scheduler()->register();
		$this->bulkRestore()->register();
	}

	/**
	 * Current settings, read fresh from options on every call.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return Settings::fromOptions();
	}

	/**
	 * Shared archive repository.
	 *
	 * @return ArchiveRepository
	 */
	public function archiveRepository(): ArchiveRepository {
		return $this->services[ ArchiveRepository::class ] ??= new ArchiveRepository();
	}

	/**
	 * Shared payload builder.
	 *
	 * @return PayloadBuilder
	 */
	public function payloadBuilder(): PayloadBuilder {
		return $this->services[ PayloadBuilder::class ] ??= new PayloadBuilder();
	}

	/**
	 * Shared archive service.
	 *
	 * @return ArchiveService
	 */
	public function archiveService(): ArchiveService {
		return $this->services[ ArchiveService::class ] ??= new ArchiveService(
			$this->payloadBuilder(),
			$this->archiveRepository(),
		);
	}

	/**
	 * Shared restore service.
	 *
	 * @return RestoreService
	 */
	public function restoreService(): RestoreService {
		return $this->services[ RestoreService::class ] ??= new RestoreService(
			$this->archiveRepository(),
		);
	}

	/**
	 * Shared "Restore all" runner.
	 *
	 * @return BulkRestore
	 */
	public function bulkRestore(): BulkRestore {
		return $this->services[ BulkRestore::class ] ??= new BulkRestore(
			$this->restoreService(),
			$this->archiveRepository(),
		);
	}

	/**
	 * Shared archive scheduler.
	 *
	 * @return Scheduler
	 */
	public function scheduler(): Scheduler {
		return $this->services[ Scheduler::class ] ??= new Scheduler(
			$this->archiveService(),
		);
	}

	/**
	 * Shared admin menu.
	 *
	 * @return AdminMenu
	 */
	private function adminMenu(): AdminMenu {
		return $this->services[ AdminMenu::class ] ??= new AdminMenu(
			$this->archiveRepository(),
			$this->scheduler(),
			$this->restoreService(),
			$this->bulkRestore(),
		);
	}

	/**
	 * Shared REST controller.
	 *
	 * @return RestController
	 */
	private function rest(): RestController {
		return $this->services[ RestController::class ] ??= new RestController(
			$this->archiveRepository(),
			$this->restoreService(),
			$this->scheduler(),
		);
	}

	/**
	 * Shared admin assets loader.
	 *
	 * @return Assets
	 */
	private function assets(): Assets {
		return $this->services[ Assets::class ] ??= new Assets();
	}

	/**
	 * Shared privacy exporter and eraser.
	 *
	 * @return Privacy
	 */
	private function privacy(): Privacy {
		return $this->services[ Privacy::class ] ??= new Privacy( $this->archiveRepository() );
	}

	/**
	 * Shared Analytics guard.
	 *
	 * @return AnalyticsGuard
	 */
	private function analyticsGuard(): AnalyticsGuard {
		return $this->services[ AnalyticsGuard::class ] ??= new AnalyticsGuard(
			$this->archiveRepository(),
		);
	}

	/**
	 * Admin notice shown when WooCommerce is missing.
	 */
	public function renderWooCommerceNotice(): void {
		if ( ! $this->onOwnOrPluginsScreen() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Coldstash requires WooCommerce to be installed and active.', 'coldstash' )
		);
	}

	/**
	 * Admin notice explaining why the engine is idle (no HPOS, or sync on).
	 */
	public function renderHposNotice(): void {
		if ( ! $this->onOwnOrPluginsScreen() ) {
			return;
		}

		if ( Hpos::isEnabled() && ! Hpos::syncEnabled() ) {
			return;
		}

		$message = Hpos::isEnabled()
			? __( 'Coldstash is idle: WooCommerce is still syncing orders to the legacy posts table. Turn compatibility-mode sync off so archiving actually reclaims space.', 'coldstash' )
			: __( 'Coldstash is idle: this store is not using High-Performance Order Storage. Enable HPOS in WooCommerce → Settings → Advanced → Features.', 'coldstash' );

		printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Whether the current admin screen is the Plugins list or Coldstash's own
	 * screen, the only places its notices appear (WordPress.org guideline 11).
	 *
	 * @return bool
	 */
	private function onOwnOrPluginsScreen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen ) {
			return false;
		}

		return 'plugins' === $screen->id || str_ends_with( (string) $screen->id, '_page_' . AdminMenu::PAGE );
	}
}
