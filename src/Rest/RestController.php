<?php

declare(strict_types=1);

namespace Coldstash\Rest;

use Coldstash\Archive\CandidateQuery;
use Coldstash\Archive\Payload;
use Coldstash\Archive\RestoreService;
use Coldstash\Archive\Scheduler;
use Coldstash\Settings\Settings;
use Coldstash\Storage\ArchiveRepository;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API for the dashboard SPA (and for anyone scripting the archive).
 *
 * The admin screen is server-rendered today; these routes exist so a React
 * dashboard can take it over without touching the engine. They are also how
 * an agency would wire archive status into their own monitoring.
 *
 * Everything requires manage_woocommerce — archived data is order data.
 */
final class RestController {

	private const NAMESPACE = 'coldstash/v1';

	/**
	 * Constructor.
	 *
	 * @param ArchiveRepository $archive   Archive storage.
	 * @param RestoreService    $restore   Restore engine.
	 * @param Scheduler         $scheduler Batch scheduler.
	 */
	public function __construct(
		private readonly ArchiveRepository $archive,
		private readonly RestoreService $restore,
		private readonly Scheduler $scheduler,
	) {
	}

	/**
	 * Registers the plugin's REST routes.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'listOrders' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => array(
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 25,
						'minimum' => 1,
						'maximum' => 200,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getOrder' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'restoreOrder' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/run',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'stop' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);
	}

	/**
	 * Permission callback shared by every route.
	 *
	 * @return bool
	 */
	public function canManage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /status: archive summary, eligibility preview, run state and settings.
	 *
	 * @return WP_REST_Response
	 */
	public function status(): WP_REST_Response {
		$settings = Settings::fromOptions();

		return new WP_REST_Response(
			array(
				'summary'  => $this->archive->summary(),
				'preview'  => ( new CandidateQuery( $settings ) )->preview(),
				'running'  => $this->scheduler->isRunning(),
				'settings' => $settings->toArray(),
			)
		);
	}

	/**
	 * GET /orders: paginated search over archived orders.
	 *
	 * @param WP_REST_Request $request Request with search, per_page and page.
	 * @return WP_REST_Response
	 */
	public function listOrders( WP_REST_Request $request ): WP_REST_Response {
		$perPage = (int) $request->get_param( 'per_page' );
		$page    = (int) $request->get_param( 'page' );

		$search = (string) $request->get_param( 'search' );
		$rows   = $this->archive->search( $search, $perPage, ( $page - 1 ) * $perPage );

		$response = new WP_REST_Response( $rows );
		$response->header( 'X-WP-Total', (string) $this->archive->searchCount( $search ) );

		return $response;
	}

	/**
	 * GET /orders/{id}: one archived order with its decoded payload.
	 *
	 * @param WP_REST_Request $request Request with the order ID.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getOrder( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->archive->find( (int) $request->get_param( 'id' ) );

		if ( null === $row ) {
			return new WP_Error( 'coldstash_not_found', __( 'Order is not in the archive.', 'coldstash' ), array( 'status' => 404 ) );
		}

		try {
			$payload = Payload::fromJson( (string) $row['payload'] );
		} catch ( Throwable $e ) {
			return new WP_Error( 'coldstash_bad_payload', $e->getMessage(), array( 'status' => 500 ) );
		}

		unset( $row['payload'] );
		$row['order']          = $payload->row( 'order' );
		$row['items']          = $payload->rows( 'items' );
		$row['notes']          = $payload->rows( 'notes' );
		$row['checksum_valid'] = $payload->matches( (string) $row['payload_checksum'] );

		return new WP_REST_Response( $row );
	}

	/**
	 * POST /orders/{id}/restore: move one order back into the live tables.
	 *
	 * @param WP_REST_Request $request Request with the order ID.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restoreOrder( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->restore->restore( (int) $request->get_param( 'id' ) );

		if ( ! $result['ok'] ) {
			return new WP_Error(
				'coldstash_' . $result['reason'],
				$result['message'],
				array( 'status' => 'not_found' === $result['reason'] ? 404 : 409 )
			);
		}

		return new WP_REST_Response( array( 'restored' => (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * POST /run: queue a background archive run.
	 *
	 * @return WP_REST_Response
	 */
	public function start(): WP_REST_Response {
		return new WP_REST_Response( array( 'batch_id' => $this->scheduler->start() ) );
	}

	/**
	 * DELETE /run: cancel the background archive run.
	 *
	 * @return WP_REST_Response
	 */
	public function stop(): WP_REST_Response {
		$this->scheduler->stop();

		return new WP_REST_Response( array( 'running' => false ) );
	}
}
