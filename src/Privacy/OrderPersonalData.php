<?php

declare(strict_types=1);

namespace Coldstash\Privacy;

/**
 * Reads and anonymizes the personal data inside an archived order's payload.
 *
 * Mirrors the fields WooCommerce exports and erases for live orders
 * (WC_Privacy_Exporters / WC_Privacy_Erasers), mapped onto the raw HPOS rows
 * the payload holds. Free of WordPress so it can be unit-tested; the caller
 * passes wp_privacy_anonymize_data() in as the anonymizer.
 */
final class OrderPersonalData {

	public const ANONYMIZED_META_KEY = '_anonymized';

	/**
	 * Order-row columns to anonymize, with their wp_privacy_anonymize_data() type.
	 */
	private const ORDER_COLUMNS = array(
		'ip_address'     => 'ip',
		'user_agent'     => 'text',
		'billing_email'  => 'email',
		'transaction_id' => 'numeric_id',
	);

	/**
	 * Address-row columns to anonymize, for both billing and shipping rows.
	 */
	private const ADDRESS_COLUMNS = array(
		'first_name' => 'text',
		'last_name'  => 'text',
		'company'    => 'text',
		'address_1'  => 'text',
		'address_2'  => 'text',
		'city'       => 'text',
		'postcode'   => 'text',
		'state'      => 'address_state',
		'country'    => 'address_country',
		'phone'      => 'phone',
		'email'      => 'email',
	);

	/**
	 * Gateway meta that WooCommerce treats as personal data.
	 */
	private const META_KEYS = array(
		'Payer first name'     => 'text',
		'Payer last name'      => 'text',
		'Payer PayPal address' => 'email',
		'Transaction ID'       => 'numeric_id',
	);

	/**
	 * Personal data of one archived order, keyed by field.
	 *
	 * Keys: items, ip_address, user_agent, billing_address, shipping_address,
	 * billing_phone, billing_email, shipping_phone, plus any gateway meta key
	 * from META_KEYS. Empty values are left out.
	 *
	 * @param array<string, mixed> $data Decoded payload data.
	 * @return array<string, string>
	 */
	public static function export( array $data ): array {
		$order    = self::rows( $data, 'order' )[0] ?? array();
		$billing  = self::address( $data, 'billing' );
		$shipping = self::address( $data, 'shipping' );

		$values = array(
			'items'            => self::items( $data ),
			'ip_address'       => (string) ( $order['ip_address'] ?? '' ),
			'user_agent'       => (string) ( $order['user_agent'] ?? '' ),
			'billing_address'  => self::formatAddress( $billing ),
			'shipping_address' => self::formatAddress( $shipping ),
			'billing_phone'    => (string) ( $billing['phone'] ?? '' ),
			'billing_email'    => (string) ( $order['billing_email'] ?? $billing['email'] ?? '' ),
			'shipping_phone'   => (string) ( $shipping['phone'] ?? '' ),
		);

		foreach ( self::rows( $data, 'meta' ) as $meta ) {
			$key = (string) ( $meta['meta_key'] ?? '' );
			if ( isset( self::META_KEYS[ $key ] ) ) {
				$values[ $key ] = (string) ( $meta['meta_value'] ?? '' );
			}
		}

		return array_filter( $values, static fn ( string $value ): bool => '' !== $value );
	}

	/**
	 * Returns the payload data with personal fields anonymized and the order
	 * flagged with `_anonymized = yes`, as WooCommerce does for live orders.
	 *
	 * @param array<string, mixed>            $data      Decoded payload data.
	 * @param callable(string, string):string $anonymize Receives a data type and a value; returns the replacement.
	 * @return array<string, mixed>
	 */
	public static function anonymize( array $data, callable $anonymize ): array {
		$orderId = 0;

		$orders = self::rows( $data, 'order' );
		foreach ( $orders as $i => $order ) {
			$orderId = (int) ( $order['id'] ?? 0 );
			foreach ( self::ORDER_COLUMNS as $column => $type ) {
				$orders[ $i ] = self::anonymizeColumn( $orders[ $i ], $column, $type, $anonymize );
			}
			if ( ! empty( $order['customer_id'] ) ) {
				$orders[ $i ]['customer_id'] = 0;
			}
		}
		$data['order'] = $orders;

		$addresses = self::rows( $data, 'addresses' );
		foreach ( $addresses as $i => $address ) {
			foreach ( self::ADDRESS_COLUMNS as $column => $type ) {
				$addresses[ $i ] = self::anonymizeColumn( $addresses[ $i ], $column, $type, $anonymize );
			}
		}
		$data['addresses'] = $addresses;

		$meta = array();
		foreach ( self::rows( $data, 'meta' ) as $row ) {
			$key   = (string) ( $row['meta_key'] ?? '' );
			$value = (string) ( $row['meta_value'] ?? '' );

			if ( ! isset( self::META_KEYS[ $key ] ) || '' === $value ) {
				$meta[] = $row;
				continue;
			}

			$replacement = (string) $anonymize( self::META_KEYS[ $key ], $value );
			if ( '' !== $replacement ) {
				$row['meta_value'] = $replacement; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- payload row, not a query.
				$meta[]            = $row;
			}
		}

		if ( ! self::isAnonymized( array( 'meta' => $meta ) ) ) {
			$meta[] = array(
				'order_id'   => $orderId,
				'meta_key'   => self::ANONYMIZED_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- payload row, not a query.
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- payload row, not a query.
			);
		}
		$data['meta'] = $meta;

		return $data;
	}

	/**
	 * Whether the order has already been anonymized.
	 *
	 * @param array<string, mixed> $data Decoded payload data.
	 * @return bool
	 */
	public static function isAnonymized( array $data ): bool {
		foreach ( self::rows( $data, 'meta' ) as $row ) {
			if ( self::ANONYMIZED_META_KEY === ( $row['meta_key'] ?? '' ) && 'yes' === ( $row['meta_value'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Billing name as stored in the indexed billing_name column.
	 *
	 * @param array<string, mixed> $data Decoded payload data.
	 * @return string
	 */
	public static function billingName( array $data ): string {
		$billing = self::address( $data, 'billing' );

		return trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) );
	}

	/**
	 * Replaces one non-empty column with its anonymized value.
	 *
	 * @param array<string, mixed>            $row       Table row.
	 * @param string                          $column    Column name.
	 * @param string                          $type      wp_privacy_anonymize_data() type.
	 * @param callable(string, string):string $anonymize Anonymizer.
	 * @return array<string, mixed>
	 */
	private static function anonymizeColumn( array $row, string $column, string $type, callable $anonymize ): array {
		if ( empty( $row[ $column ] ) ) {
			return $row;
		}

		$row[ $column ] = (string) $anonymize( $type, (string) $row[ $column ] );

		return $row;
	}

	/**
	 * "Name x qty" for each line item, as WooCommerce exports them.
	 *
	 * @param array<string, mixed> $data Decoded payload data.
	 * @return string
	 */
	private static function items( array $data ): string {
		$quantities = array();
		foreach ( self::rows( $data, 'item_meta' ) as $meta ) {
			if ( '_qty' === ( $meta['meta_key'] ?? '' ) ) {
				$quantities[ (int) ( $meta['order_item_id'] ?? 0 ) ] = (string) ( $meta['meta_value'] ?? '' );
			}
		}

		$names = array();
		foreach ( self::rows( $data, 'items' ) as $item ) {
			if ( 'line_item' !== ( $item['order_item_type'] ?? '' ) ) {
				continue;
			}
			$name = (string) ( $item['order_item_name'] ?? '' );
			$qty  = $quantities[ (int) ( $item['order_item_id'] ?? 0 ) ] ?? '';

			$names[] = '' === $qty ? $name : $name . ' x ' . $qty;
		}

		return implode( ', ', $names );
	}

	/**
	 * The address row of one type, or an empty array.
	 *
	 * @param array<string, mixed> $data Decoded payload data.
	 * @param string               $type "billing" or "shipping".
	 * @return array<string, mixed>
	 */
	private static function address( array $data, string $type ): array {
		foreach ( self::rows( $data, 'addresses' ) as $row ) {
			if ( ( $row['address_type'] ?? '' ) === $type ) {
				return $row;
			}
		}

		return array();
	}

	/**
	 * One-line address, comma separated.
	 *
	 * @param array<string, mixed> $address Address row.
	 * @return string
	 */
	private static function formatAddress( array $address ): string {
		$parts = array(
			trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) ),
			$address['company'] ?? '',
			$address['address_1'] ?? '',
			$address['address_2'] ?? '',
			$address['city'] ?? '',
			$address['state'] ?? '',
			$address['postcode'] ?? '',
			$address['country'] ?? '',
		);

		return implode( ', ', array_filter( array_map( 'strval', $parts ), static fn ( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Rows stored under a payload key.
	 *
	 * @param array<string, mixed> $data Decoded payload data.
	 * @param string               $key  Data key.
	 * @return list<array<string, mixed>>
	 */
	private static function rows( array $data, string $key ): array {
		$rows = $data[ $key ] ?? array();

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}
}
