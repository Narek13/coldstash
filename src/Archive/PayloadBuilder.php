<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- this plugin moves rows between WooCommerce's order tables and its archive tables; no WordPress API covers that, and results must never be cached.

namespace Coldstash\Archive;

use Coldstash\Support\Hpos;

/**
 * Reads every row belonging to an order and packs it into a Payload.
 *
 * Six tables under HPOS, plus order notes (which live in wp_comments with
 * comment_type = 'order_note') and download permissions. Miss one and the
 * restored order is quietly wrong, which is worse than failing loudly — so
 * everything captured here has a matching branch in RestoreService.
 *
 * Table names come from the Hpos allowlist and are never built from input.
 */
final class PayloadBuilder {

	/**
	 * Captures every row of an order, or returns null if the order does not exist.
	 *
	 * @param int $orderId Order to capture.
	 * @return Payload|null
	 * @throws \JsonException When the captured rows cannot be encoded.
	 */
	public function build( int $orderId ): ?Payload {
		global $wpdb;

		$tables = Hpos::sourceTables();

		$order = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $tables['order'], $orderId ),
			ARRAY_A
		);

		if ( ! $order ) {
			return null;
		}

		$addresses = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $tables['addresses'], $orderId ),
			ARRAY_A
		);

		$operational = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $tables['operational_data'], $orderId ),
			ARRAY_A
		);

		$meta = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $tables['meta'], $orderId ),
			ARRAY_A
		);

		$items = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $tables['items'], $orderId ),
			ARRAY_A
		);

		$itemMeta = array();
		$itemIds  = array_map( static fn ( array $i ): int => (int) $i['order_item_id'], $items );
		if ( array() !== $itemIds ) {
			$itemMeta = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE order_item_id IN (' . implode( ',', array_fill( 0, count( $itemIds ), '%d' ) ) . ')',
					...array_merge( array( $tables['item_meta'] ), $itemIds )
				),
				ARRAY_A
			);
		}

		$notes = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE comment_post_ID = %d AND comment_type = 'order_note'",
				$wpdb->comments,
				$orderId
			),
			ARRAY_A
		);

		$noteMeta = array();
		$noteIds  = array_map( static fn ( array $n ): int => (int) $n['comment_ID'], $notes );
		if ( array() !== $noteIds ) {
			$noteMeta = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE comment_id IN (' . implode( ',', array_fill( 0, count( $noteIds ), '%d' ) ) . ')',
					...array_merge( array( $wpdb->commentmeta ), $noteIds )
				),
				ARRAY_A
			);
		}

		$downloads = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE order_id = %d',
				$wpdb->prefix . 'woocommerce_downloadable_product_permissions',
				$orderId
			),
			ARRAY_A
		);

		return Payload::fromArray(
			array(
				'order'            => array( $order ),
				'addresses'        => $addresses,
				'operational_data' => $operational,
				'meta'             => $meta,
				'items'            => $items,
				'item_meta'        => $itemMeta,
				'notes'            => $notes,
				'note_meta'        => $noteMeta,
				'downloads'        => $downloads,
			)
		);
	}

	/**
	 * Short, human-readable list of what was in the order, denormalised into
	 * an indexed column so archive search can match on product names without
	 * decoding every payload.
	 *
	 * @param list<array<string, mixed>> $items Rows from wc_order_items.
	 * @return string
	 */
	public function itemSummary( array $items ): string {
		$names = array();
		foreach ( $items as $item ) {
			if ( 'line_item' !== ( $item['order_item_type'] ?? '' ) ) {
				continue;
			}
			$names[] = (string) ( $item['order_item_name'] ?? '' );
		}

		$names = array_filter( array_unique( $names ) );

		return mb_substr( implode( ' | ', $names ), 0, 2000 );
	}
}
