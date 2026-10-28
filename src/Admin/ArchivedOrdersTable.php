<?php

declare(strict_types=1);

namespace Coldstash\Admin;

use Coldstash\Storage\ArchiveRepository;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Archive browser: search, status filter, view and restore over the indexed columns.
 */
final class ArchivedOrdersTable extends WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Archived order counts per status, loaded once per request.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $statusCounts = null;

	/**
	 * Wires the table to archive storage.
	 *
	 * @param ArchiveRepository $archive Archive storage.
	 */
	public function __construct( private readonly ArchiveRepository $archive ) {
		parent::__construct(
			array(
				'singular' => 'archived-order',
				'plural'   => 'archived-orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column headers.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'order'       => __( 'Order', 'coldstash' ),
			'date'        => __( 'Date', 'coldstash' ),
			'status'      => __( 'Status', 'coldstash' ),
			'items'       => __( 'Items', 'coldstash' ),
			'total'       => __( 'Total', 'coldstash' ),
			'archived_at' => __( 'Archived', 'coldstash' ),
		);
	}

	/**
	 * Loads the current page of rows for the search term and status filter.
	 */
	public function prepare_items() {
		$term   = $this->searchTerm();
		$status = $this->statusFilter();
		$total  = $this->archive->searchCount( $term, $status );

		$this->items = $this->archive->search(
			$term,
			self::PER_PAGE,
			( $this->get_pagenum() - 1 ) * self::PER_PAGE,
			$status
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'order' );
	}

	/**
	 * Status filter links above the table.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$current = $this->statusFilter();
		$counts  = $this->statusCounts();
		$base    = AdminMenu::url( array( 'tab' => 'orders' ) );

		$views = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'coldstash' ),
				esc_html( number_format_i18n( array_sum( $counts ) ) )
			),
		);

		foreach ( $counts as $status => $count ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( self::statusLabel( $status ) ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array(
			'restore' => __( 'Restore', 'coldstash' ),
		);
	}

	/**
	 * Message shown when nothing matches.
	 */
	public function no_items() {
		if ( '' !== $this->searchTerm() || '' !== $this->statusFilter() ) {
			esc_html_e( 'No archived orders match your search.', 'coldstash' );
			return;
		}

		esc_html_e( 'No orders have been archived yet.', 'coldstash' );
	}

	/**
	 * Row checkbox.
	 *
	 * @param array<string, mixed> $item Archive row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		$id = (int) $item['id'];

		return sprintf(
			'<label class="screen-reader-text" for="coldstash-cb-%1$d">%2$s</label><input type="checkbox" id="coldstash-cb-%1$d" name="order_ids[]" value="%1$d" />',
			$id,
			/* translators: %d: order number. */
			esc_html( sprintf( __( 'Select order #%d', 'coldstash' ), $id ) )
		);
	}

	/**
	 * Order number and customer, with View and Restore row actions.
	 *
	 * @param array<string, mixed> $item Archive row.
	 * @return string
	 */
	protected function column_order( $item ) {
		$id       = (int) $item['id'];
		$customer = (string) ( '' !== (string) $item['billing_name'] ? $item['billing_name'] : $item['billing_email'] );
		$viewUrl  = AdminMenu::url(
			array(
				'tab'   => 'orders',
				'order' => $id,
			)
		);

		$title = sprintf(
			'<strong><a class="row-title" href="%s">#%d %s</a></strong>',
			esc_url( $viewUrl ),
			$id,
			esc_html( $customer )
		);

		$actions = array(
			'view'    => sprintf( '<a href="%s">%s</a>', esc_url( $viewUrl ), esc_html__( 'View', 'coldstash' ) ),
			'restore' => sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				esc_url( AdminMenu::restoreUrl( $id ) ),
				/* translators: %d: order number. */
				esc_attr( sprintf( __( 'Restore order #%d', 'coldstash' ), $id ) ),
				esc_html__( 'Restore', 'coldstash' )
			),
		);

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Default column renderer.
	 *
	 * @param array<string, mixed> $item        Archive row.
	 * @param string               $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'date':
				return esc_html( self::formatDate( (string) $item['date_created_gmt'] ) );
			case 'archived_at':
				return esc_html( self::formatDate( (string) $item['archived_at'] ) );
			case 'status':
				return esc_html( self::statusLabel( (string) $item['status'] ) );
			case 'items':
				return esc_html( (string) $item['item_summary'] );
			case 'total':
				return wp_kses_post( self::formatPrice( (float) $item['total_amount'], (string) $item['currency'] ) );
		}

		return '';
	}

	/**
	 * Human-readable order status.
	 *
	 * @param string $status Stored status, with or without the "wc-" prefix.
	 * @return string
	 */
	public static function statusLabel( string $status ): string {
		$key = str_starts_with( $status, 'wc-' ) ? $status : 'wc-' . $status;

		return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $key ) : $status;
	}

	/**
	 * Formats a GMT datetime in the site's timezone and date format.
	 *
	 * @param string $gmt Datetime in GMT (Y-m-d H:i:s).
	 * @return string
	 */
	public static function formatDate( string $gmt ): string {
		if ( '' === $gmt ) {
			return '–';
		}

		return get_date_from_gmt( $gmt, (string) get_option( 'date_format' ) );
	}

	/**
	 * Formats an amount with WooCommerce's price formatting when available.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO currency code.
	 * @return string HTML.
	 */
	public static function formatPrice( float $amount, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $amount, array( 'currency' => $currency ) );
		}

		return esc_html( number_format_i18n( $amount, 2 ) . ' ' . $currency );
	}

	/**
	 * Current search term from the request.
	 *
	 * @return string
	 */
	private function searchTerm(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		return isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
	}

	/**
	 * Current status filter from the request, limited to statuses in the archive.
	 *
	 * @return string
	 */
	private function statusFilter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';

		return array_key_exists( $status, $this->statusCounts() ) ? $status : '';
	}

	/**
	 * Archived order counts per status.
	 *
	 * @return array<string, int>
	 */
	private function statusCounts(): array {
		return $this->statusCounts ??= $this->archive->statusCounts();
	}
}
