<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this plugin moves rows between WooCommerce's order tables and its archive tables; no WordPress API covers that, and results must never be cached.

namespace Coldstash\Privacy;

use Coldstash\Admin\ArchivedOrdersTable;
use Coldstash\Archive\Payload;
use Coldstash\Storage\ArchiveRepository;
use Throwable;
use WP_User;

/**
 * Hooks archived orders into WordPress's personal data export and erasure tools.
 *
 * Archived orders are no longer in the live tables, so WooCommerce's own
 * exporter and eraser cannot see them. These run alongside WooCommerce's and
 * follow the same rules, including its "Remove personal data from orders on
 * request" setting.
 */
final class Privacy {

	public const ID = 'coldstash-orders';

	private const PAGE_SIZE = 10;

	/**
	 * Wires the archive table access.
	 *
	 * @param ArchiveRepository $archive Archive table access.
	 */
	public function __construct( private readonly ArchiveRepository $archive ) {
	}

	/**
	 * Registers the exporter, the eraser and the privacy policy suggestion.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'registerExporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'registerEraser' ) );
		add_action( 'admin_init', array( $this, 'addPolicyContent' ) );
	}

	/**
	 * Adds the archived-orders exporter.
	 *
	 * @param array<string, mixed> $exporters Registered exporters.
	 * @return array<string, mixed>
	 */
	public function registerExporter( array $exporters ): array {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Archived orders', 'coldstash' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the archived-orders eraser.
	 *
	 * @param array<string, mixed> $erasers Registered erasers.
	 * @return array<string, mixed>
	 */
	public function registerEraser( array $erasers ): array {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Archived orders', 'coldstash' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports one page of a customer's archived orders.
	 *
	 * @param string $email Email address from the export request.
	 * @param int    $page  Page number, starting at 1.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$rows = $this->archive->findForCustomer( $email, $this->userId( $email ), self::PAGE_SIZE, ( max( 1, $page ) - 1 ) * self::PAGE_SIZE );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'          => 'woocommerce_archived_orders',
				'group_label'       => __( 'Archived orders', 'coldstash' ),
				'group_description' => __( 'User&#8217;s archived WooCommerce orders data.', 'coldstash' ),
				'item_id'           => 'archived-order-' . (int) $row['id'],
				'data'              => $this->exportData( $row ),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erases, or retains, personal data in one page of a customer's archived orders.
	 *
	 * @param string $email Email address from the erasure request.
	 * @param int    $page  Page number, starting at 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$enabled = $this->erasureEnabled();

		// Anonymized orders no longer match the email or user, so when erasing, the next batch is always the first page.
		$offset = $enabled ? 0 : ( max( 1, $page ) - 1 ) * self::PAGE_SIZE;
		$rows   = $this->archive->findForCustomer( $email, $this->userId( $email ), self::PAGE_SIZE, $offset );

		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $rows ) < self::PAGE_SIZE,
		);

		foreach ( $rows as $row ) {
			$orderId = (int) $row['id'];

			if ( $enabled && $this->anonymize( $orderId, (string) $row['payload'] ) ) {
				/* translators: %s: order number. */
				$response['messages'][]    = sprintf( __( 'Removed personal data from archived order %s.', 'coldstash' ), $orderId );
				$response['items_removed'] = true;
				continue;
			}

			if ( $enabled ) {
				// Still matches the request, so re-reading page one would loop forever.
				$response['done'] = true;
			}

			/* translators: %s: order number. */
			$response['messages'][]     = sprintf( __( 'Personal data within archived order %s has been retained.', 'coldstash' ), $orderId );
			$response['items_retained'] = true;
		}

		return $response;
	}

	/**
	 * Suggests privacy policy text under Settings → Privacy.
	 */
	public function addPolicyContent(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'Old orders may be moved from the live order tables into archive tables in this site\'s own database. Archived orders keep the same personal data as the original order (such as name, email, billing and shipping address, phone number and IP address) and are not sent to any third party.', 'coldstash' ) . '</p>'
			. '<p>' . esc_html__( 'Archived orders are included when you export or erase a customer\'s personal data, following the same "Remove personal data from orders on request" setting as live orders.', 'coldstash' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Coldstash – Archive Old Orders for WooCommerce', 'coldstash' ), wp_kses_post( $content ) );
	}

	/**
	 * Name/value pairs for one archived order, labelled as WooCommerce labels them.
	 *
	 * @param array<string, mixed> $row Archive row, payload included.
	 * @return list<array{name: string, value: string}>
	 */
	private function exportData( array $row ): array {
		$personal = array();
		try {
			$personal = OrderPersonalData::export( Payload::fromJson( (string) $row['payload'] )->data );
		} catch ( Throwable $e ) {
			$personal['billing_email'] = (string) $row['billing_email'];
			$personal['items']         = (string) $row['item_summary'];
		}

		$values = array(
			'order_number' => (string) (int) $row['id'],
			'date_created' => $this->formatDate( (string) $row['date_created_gmt'] ),
			'total'        => html_entity_decode( wp_strip_all_tags( ArchivedOrdersTable::formatPrice( (float) $row['total_amount'], (string) $row['currency'] ) ), ENT_QUOTES, 'UTF-8' ),
		) + $personal;

		$labels = array(
			'order_number'         => __( 'Order Number', 'coldstash' ),
			'date_created'         => __( 'Order Date', 'coldstash' ),
			'total'                => __( 'Order Total', 'coldstash' ),
			'items'                => __( 'Items Purchased', 'coldstash' ),
			'ip_address'           => __( 'IP Address', 'coldstash' ),
			'user_agent'           => __( 'Browser User Agent', 'coldstash' ),
			'billing_address'      => __( 'Billing Address', 'coldstash' ),
			'shipping_address'     => __( 'Shipping Address', 'coldstash' ),
			'billing_phone'        => __( 'Phone Number', 'coldstash' ),
			'billing_email'        => __( 'Email Address', 'coldstash' ),
			'shipping_phone'       => __( 'Shipping Phone Number', 'coldstash' ),
			'Payer first name'     => __( 'Payer first name', 'coldstash' ),
			'Payer last name'      => __( 'Payer last name', 'coldstash' ),
			'Payer PayPal address' => __( 'Payer PayPal address', 'coldstash' ),
			'Transaction ID'       => __( 'Transaction ID', 'coldstash' ),
		);

		$data = array();
		foreach ( $labels as $key => $label ) {
			if ( isset( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$data[] = array(
					'name'  => $label,
					'value' => $values[ $key ],
				);
			}
		}

		return $data;
	}

	/**
	 * Anonymizes one archived order's payload and indexed columns.
	 *
	 * @param int    $orderId Order ID.
	 * @param string $json    Stored payload.
	 * @return bool Whether the anonymized order was stored intact.
	 */
	private function anonymize( int $orderId, string $json ): bool {
		try {
			$data = Payload::fromJson( $json )->data;
		} catch ( Throwable $e ) {
			return false;
		}

		if ( OrderPersonalData::isAnonymized( $data ) ) {
			return false;
		}

		$data = OrderPersonalData::anonymize(
			$data,
			static fn ( string $type, string $value ): string => (string) wp_privacy_anonymize_data( $type, $value )
		);

		try {
			$payload = Payload::fromArray( $data );
		} catch ( Throwable $e ) {
			return false;
		}

		return $this->archive->replacePayload(
			$orderId,
			$payload,
			(string) wp_privacy_anonymize_data( 'email', 'deleted' ),
			OrderPersonalData::billingName( $data )
		);
	}

	/**
	 * WooCommerce's "Remove personal data from orders on request" setting.
	 *
	 * @return bool
	 */
	private function erasureEnabled(): bool {
		$value = get_option( 'woocommerce_erasure_request_removes_order_data', 'no' );

		return function_exists( 'wc_string_to_bool' ) ? wc_string_to_bool( $value ) : 'yes' === $value;
	}

	/**
	 * ID of the account registered with this email, or 0.
	 *
	 * @param string $email Email address.
	 * @return int
	 */
	private function userId( string $email ): int {
		$user = get_user_by( 'email', $email );

		return $user instanceof WP_User ? (int) $user->ID : 0;
	}

	/**
	 * Formats a GMT datetime in the site's date and time format.
	 *
	 * @param string $gmt Datetime in GMT, as stored.
	 * @return string
	 */
	private function formatDate( string $gmt ): string {
		if ( '' === $gmt ) {
			return '';
		}

		return get_date_from_gmt( $gmt, get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) );
	}
}
