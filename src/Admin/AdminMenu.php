<?php

declare(strict_types=1);

namespace Coldstash\Admin;

use Coldstash\Archive\ArchivedOrder;
use Coldstash\Archive\BulkRestore;
use Coldstash\Archive\CandidateQuery;
use Coldstash\Archive\RestoreService;
use Coldstash\Archive\Scheduler;
use Coldstash\Settings\Settings;
use Coldstash\Storage\ArchiveRepository;
use Throwable;

/**
 * Registers the admin screen: overview, archive browser, read-only order view
 * and settings, plus the archive, restore and settings handlers behind them.
 */
final class AdminMenu {

	public const PAGE = 'coldstash';

	private const RESULT_TRANSIENT = 'coldstash_restore_result_';

	public const DROP_DATA_OPTION = 'coldstash_drop_data_on_uninstall';

	/**
	 * Constructor.
	 *
	 * @param ArchiveRepository $archive   Archive storage.
	 * @param Scheduler         $scheduler Batch scheduler.
	 * @param RestoreService    $restore   Restore engine.
	 * @param BulkRestore       $bulk      "Restore all" runner.
	 */
	public function __construct(
		private readonly ArchiveRepository $archive,
		private readonly Scheduler $scheduler,
		private readonly RestoreService $restore,
		private readonly BulkRestore $bulk,
	) {
	}

	/**
	 * Registers the submenu page and the form handlers.
	 */
	public function register(): void {
		add_action(
			'admin_menu',
			function (): void {
				$hook = add_submenu_page(
					'woocommerce',
					__( 'Coldstash', 'coldstash' ),
					__( 'Coldstash', 'coldstash' ),
					'manage_woocommerce',
					self::PAGE,
					array( $this, 'render' )
				);

				if ( false !== $hook ) {
					add_action( 'load-' . $hook, array( $this, 'handleBulkRestore' ) );
				}
			}
		);

		add_action( 'admin_post_coldstash_start', array( $this, 'handleStart' ) );
		add_action( 'admin_post_coldstash_stop', array( $this, 'handleStop' ) );
		add_action( 'admin_post_coldstash_restore', array( $this, 'handleRestore' ) );
		add_action( 'admin_post_coldstash_restore_all', array( $this, 'handleRestoreAll' ) );
		add_action( 'admin_post_coldstash_restore_all_stop', array( $this, 'handleRestoreAllStop' ) );
		add_action( 'admin_post_coldstash_save_settings', array( $this, 'handleSaveSettings' ) );
	}

	/**
	 * URL of the plugin screen with extra query args.
	 *
	 * @param array<string, string|int> $args Query args, e.g. tab and order.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Nonced URL that restores one archived order.
	 *
	 * @param int $orderId Archived order.
	 * @return string
	 */
	public static function restoreUrl( int $orderId ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'coldstash_restore',
					'order_id' => $orderId,
				),
				admin_url( 'admin-post.php' )
			),
			'coldstash_restore_' . $orderId
		);
	}

	/**
	 * Renders the tab, archive browser or single order the request asks for.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$orderId = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		// phpcs:enable

		$notice = $this->takeRestoreResult();

		if ( 'orders' === $tab && $orderId > 0 ) {
			$this->renderOrder( $orderId, $notice );
			return;
		}

		if ( 'orders' === $tab ) {
			$this->renderOrders( $notice );
			return;
		}

		if ( 'settings' === $tab ) {
			$this->renderSettings( $notice );
			return;
		}

		$this->renderOverview( $notice );
	}

	/**
	 * Handles the "start archiving" link.
	 */
	public function handleStart(): void {
		$this->authorize( 'coldstash_start' );

		if ( $this->bulk->isRunning() ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		$this->scheduler->start();

		wp_safe_redirect( self::url( array( 'started' => 1 ) ) );
		exit;
	}

	/**
	 * Handles the "stop archiving" link.
	 */
	public function handleStop(): void {
		$this->authorize( 'coldstash_stop' );

		$this->scheduler->stop();

		wp_safe_redirect( self::url( array( 'stopped' => 1 ) ) );
		exit;
	}

	/**
	 * Handles the Restore link for one order, from the list or the order view.
	 */
	public function handleRestore(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce action includes this ID; verified next.
		$orderId = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		$this->authorize( 'coldstash_restore_' . $orderId );

		$this->restoreOrders( array( $orderId ) );

		wp_safe_redirect( self::url( array( 'tab' => 'orders' ) ) );
		exit;
	}

	/**
	 * Handles the list table's "Restore" bulk action before the screen renders.
	 */
	public function handleBulkRestore(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only routes the request; the nonce is verified before anything changes.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( 'restore' !== $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}
		// phpcs:enable

		if ( 'restore' !== $action ) {
			return;
		}

		$this->authorize( 'bulk-archived-orders' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by authorize() above.
		$ids = isset( $_REQUEST['order_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['order_ids'] ) ) : array();

		$this->restoreOrders( array_values( array_filter( $ids ) ) );

		wp_safe_redirect( self::url( array( 'tab' => 'orders' ) ) );
		exit;
	}

	/**
	 * Handles "Restore all": stops archiving, then restores every archived order in the background.
	 */
	public function handleRestoreAll(): void {
		$this->authorize( 'coldstash_restore_all' );

		$this->scheduler->stop();
		$this->bulk->start();

		wp_safe_redirect( self::url( array( 'tab' => 'orders' ) ) );
		exit;
	}

	/**
	 * Handles Stop on a running "Restore all".
	 */
	public function handleRestoreAllStop(): void {
		$this->authorize( 'coldstash_restore_all_stop' );

		$this->bulk->stop();

		wp_safe_redirect( self::url( array( 'tab' => 'orders' ) ) );
		exit;
	}

	/**
	 * Handles the settings form.
	 */
	public function handleSaveSettings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'coldstash' ) );
		}

		check_admin_referer( 'coldstash_save_settings' );

		$input = array(
			'age_months'               => isset( $_POST['age_months'] ) ? absint( $_POST['age_months'] ) : 0,
			'statuses'                 => isset( $_POST['statuses'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['statuses'] ) ) : array(),
			'exclude_active_downloads' => ! empty( $_POST['exclude_active_downloads'] ),
		);

		$settings = Settings::fromArray( array_merge( Settings::fromOptions()->toArray(), $input ) );
		update_option( Settings::OPTION, $settings->toArray() );

		$dropData = ! empty( $_POST['drop_data_on_uninstall'] ) && 0 === $this->archive->count();
		update_option( self::DROP_DATA_OPTION, $dropData );

		wp_safe_redirect(
			self::url(
				array(
					'tab'   => 'settings',
					'saved' => 1,
				)
			)
		);
		exit;
	}

	/**
	 * State of "Restore all" for the progress banner every tab shows.
	 *
	 * @return array{running:bool, progress:array{run_id:string, cursor:int, restored:int, failed:int, stopped:bool, finished_gmt:string}|null, stopUrl:string}
	 */
	private function bulkState(): array {
		return array(
			'running'  => $this->bulk->isRunning(),
			'progress' => $this->bulk->progress(),
			'stopUrl'  => wp_nonce_url( admin_url( 'admin-post.php?action=coldstash_restore_all_stop' ), 'coldstash_restore_all_stop' ),
		);
	}

	/**
	 * Settings tab.
	 *
	 * @param array{restored:list<int>, failed:list<array{id:int, message:string}>}|null $notice Restore result to report.
	 */
	private function renderSettings( ?array $notice ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $notice is read by the included template.
		$settings = Settings::fromOptions();
		$archived = $this->archive->count();
		$dropData = (bool) get_option( self::DROP_DATA_OPTION ) && 0 === $archived;
		$bulk     = $this->bulkState();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		$saved = isset( $_GET['saved'] );
		$tab   = 'settings';

		require COLDSTASH_DIR . 'templates/settings.php';
	}

	/**
	 * Overview tab: counts, run controls, exclusions and recent archives.
	 *
	 * @param array{restored:list<int>, failed:list<array{id:int, message:string}>}|null $notice Restore result to report.
	 */
	private function renderOverview( ?array $notice ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $notice is read by the included template.
		$settings = Settings::fromOptions();
		$summary  = $this->archive->summary();
		$preview  = ( new CandidateQuery( $settings ) )->preview();
		$running  = $this->scheduler->isRunning();
		$lastRun  = $this->scheduler->lastRun();
		$recent   = $this->archive->search( '', 10 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirect.
		$started = isset( $_GET['started'] );
		$stopped = isset( $_GET['stopped'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$startUrl = wp_nonce_url( admin_url( 'admin-post.php?action=coldstash_start' ), 'coldstash_start' );
		$stopUrl  = wp_nonce_url( admin_url( 'admin-post.php?action=coldstash_stop' ), 'coldstash_stop' );
		$tab      = '';
		$bulk     = $this->bulkState();

		require COLDSTASH_DIR . 'templates/dashboard.php';
	}

	/**
	 * Archived orders tab: the archive browser.
	 *
	 * @param array{restored:list<int>, failed:list<array{id:int, message:string}>}|null $notice Restore result to report.
	 */
	private function renderOrders( ?array $notice ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $notice is read by the included template.
		$table = new ArchivedOrdersTable( $this->archive );
		$table->prepare_items();
		$tab           = 'orders';
		$bulk          = $this->bulkState();
		$archivedCount = $this->archive->count();
		$restoreAllUrl = wp_nonce_url( admin_url( 'admin-post.php?action=coldstash_restore_all' ), 'coldstash_restore_all' );

		require COLDSTASH_DIR . 'templates/orders.php';
	}

	/**
	 * Read-only view of one archived order.
	 *
	 * @param int                                                                        $orderId Archived order.
	 * @param array{restored:list<int>, failed:list<array{id:int, message:string}>}|null $notice  Restore result to report.
	 */
	private function renderOrder( int $orderId, ?array $notice ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $notice is read by the included template.
		$row   = $this->archive->find( $orderId );
		$order = null;
		$error = '';

		if ( null === $row ) {
			$error = __( 'This order is not in the archive. It may have been restored already.', 'coldstash' );
		} else {
			try {
				$order = ArchivedOrder::fromRow( $row );
			} catch ( Throwable $e ) {
				$error = __( 'This archived order could not be read.', 'coldstash' ) . ' ' . $e->getMessage();
			}
		}

		$tab  = 'orders';
		$bulk = $this->bulkState();

		require COLDSTASH_DIR . 'templates/order.php';
	}

	/**
	 * Restores orders one by one and remembers the outcome for the next screen.
	 *
	 * @param list<int> $orderIds Archived orders to restore.
	 */
	private function restoreOrders( array $orderIds ): void {
		$result = array(
			'restored' => array(),
			'failed'   => array(),
		);

		foreach ( $orderIds as $orderId ) {
			$outcome = $this->restore->restore( $orderId );

			if ( $outcome['ok'] ) {
				$result['restored'][] = $orderId;
				continue;
			}

			$result['failed'][] = array(
				'id'      => $orderId,
				'message' => $outcome['message'],
			);
		}

		set_transient( self::RESULT_TRANSIENT . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Reads and clears the last restore outcome for the current user.
	 *
	 * @return array{restored:list<int>, failed:list<array{id:int, message:string}>}|null
	 */
	private function takeRestoreResult(): ?array {
		$key    = self::RESULT_TRANSIENT . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return null;
		}

		delete_transient( $key );

		return array(
			'restored' => array_map( 'intval', (array) ( $result['restored'] ?? array() ) ),
			'failed'   => array_values( (array) ( $result['failed'] ?? array() ) ),
		);
	}

	/**
	 * Stops the request unless the user may manage WooCommerce and the nonce is valid.
	 *
	 * @param string $nonceAction Nonce action to verify.
	 */
	private function authorize( string $nonceAction ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'coldstash' ) );
		}

		check_admin_referer( $nonceAction );
	}
}
