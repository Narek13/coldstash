<?php

declare(strict_types=1);

namespace Coldstash\Tests\Unit;

use Coldstash\Archive\Payload;
use Coldstash\Privacy\OrderPersonalData;
use PHPUnit\Framework\TestCase;

/**
 * Archived orders must answer GDPR export and erasure requests the way live
 * orders do, without breaking restore.
 */
final class OrderPersonalDataTest extends TestCase {

	/**
	 * An archived order with billing and shipping addresses, gateway meta and one item.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		return array(
			'order'     => array(
				array(
					'id'             => 1234,
					'customer_id'    => 7,
					'billing_email'  => 'jane@example.com',
					'ip_address'     => '203.0.113.42',
					'user_agent'     => 'Mozilla/5.0',
					'transaction_id' => 'txn_123',
					'total_amount'   => '99.50000000',
				),
			),
			'addresses' => array(
				array(
					'order_id'     => 1234,
					'address_type' => 'billing',
					'first_name'   => 'Jane',
					'last_name'    => 'Doe',
					'address_1'    => '1 Main St',
					'city'         => 'Berlin',
					'country'      => 'DE',
					'email'        => 'jane@example.com',
					'phone'        => '+49 30 123',
				),
				array(
					'order_id'     => 1234,
					'address_type' => 'shipping',
					'first_name'   => 'Jane',
					'last_name'    => 'Doe',
					'city'         => 'Hamburg',
					'country'      => 'DE',
					'phone'        => '',
				),
			),
			'items'     => array(
				array(
					'order_item_id'   => 9,
					'order_item_name' => 'Espresso',
					'order_item_type' => 'line_item',
				),
				array(
					'order_item_id'   => 10,
					'order_item_name' => 'Flat rate',
					'order_item_type' => 'shipping',
				),
			),
			'item_meta' => array(
				array(
					'order_item_id' => 9,
					'meta_key'      => '_qty', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture row, not a query.
					'meta_value'    => '2', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fixture row, not a query.
				),
			),
			'meta'      => array(
				array(
					'order_id'   => 1234,
					'meta_key'   => 'Payer PayPal address', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture row, not a query.
					'meta_value' => 'jane@paypal.example', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fixture row, not a query.
				),
				array(
					'order_id'   => 1234,
					'meta_key'   => 'Transaction ID', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture row, not a query.
					'meta_value' => 'txn_123', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fixture row, not a query.
				),
				array(
					'order_id'   => 1234,
					'meta_key'   => '_custom', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture row, not a query.
					'meta_value' => 'keep me', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fixture row, not a query.
				),
			),
		);
	}

	/**
	 * Stand-in for wp_privacy_anonymize_data() with core's return values.
	 *
	 * @param string $type  Data type.
	 * @param string $value Original value.
	 * @return string
	 */
	public static function anonymizer( string $type, string $value ): string {
		return match ( $type ) {
			'email' => 'deleted@site.invalid',
			'ip' => '203.0.113.0',
			'text' => '[deleted]',
			default => '',
		};
	}

	/**
	 * The export carries the same personal fields WooCommerce exports for live orders.
	 */
	public function testExportListsPersonalFields(): void {
		$export = OrderPersonalData::export( $this->data() );

		self::assertSame( 'Espresso x 2', $export['items'] );
		self::assertSame( '203.0.113.42', $export['ip_address'] );
		self::assertSame( 'Mozilla/5.0', $export['user_agent'] );
		self::assertSame( 'Jane Doe, 1 Main St, Berlin, DE', $export['billing_address'] );
		self::assertSame( 'Jane Doe, Hamburg, DE', $export['shipping_address'] );
		self::assertSame( '+49 30 123', $export['billing_phone'] );
		self::assertSame( 'jane@example.com', $export['billing_email'] );
		self::assertSame( 'jane@paypal.example', $export['Payer PayPal address'] );
		self::assertArrayNotHasKey( 'shipping_phone', $export );
	}

	/**
	 * Personal fields are anonymized and the customer link removed.
	 */
	public function testAnonymizeRemovesPersonalData(): void {
		$data = OrderPersonalData::anonymize( $this->data(), array( self::class, 'anonymizer' ) );

		$order = $data['order'][0];
		self::assertSame( 'deleted@site.invalid', $order['billing_email'] );
		self::assertSame( '203.0.113.0', $order['ip_address'] );
		self::assertSame( '[deleted]', $order['user_agent'] );
		self::assertSame( '', $order['transaction_id'] );
		self::assertSame( 0, $order['customer_id'] );
		self::assertSame( '99.50000000', $order['total_amount'] );

		$billing = $data['addresses'][0];
		self::assertSame( '[deleted]', $billing['first_name'] );
		self::assertSame( '[deleted]', $billing['address_1'] );
		self::assertSame( '', $billing['country'] );
		self::assertSame( '', $billing['phone'] );
		self::assertSame( 'deleted@site.invalid', $billing['email'] );
		self::assertSame( '[deleted] [deleted]', OrderPersonalData::billingName( $data ) );
	}

	/**
	 * Gateway meta is anonymized or dropped, other meta is untouched, and the order is flagged.
	 */
	public function testAnonymizeHandlesMetaAndFlagsOrder(): void {
		$original = $this->data();
		self::assertFalse( OrderPersonalData::isAnonymized( $original ) );

		$data = OrderPersonalData::anonymize( $original, array( self::class, 'anonymizer' ) );
		$meta = array_column( $data['meta'], 'meta_value', 'meta_key' );

		self::assertSame( 'deleted@site.invalid', $meta['Payer PayPal address'] );
		self::assertArrayNotHasKey( 'Transaction ID', $meta );
		self::assertSame( 'keep me', $meta['_custom'] );
		self::assertSame( 'yes', $meta['_anonymized'] );
		self::assertTrue( OrderPersonalData::isAnonymized( $data ) );
	}

	/**
	 * Anonymizing twice does not add a second flag.
	 */
	public function testAnonymizeIsIdempotentOnTheFlag(): void {
		$once  = OrderPersonalData::anonymize( $this->data(), array( self::class, 'anonymizer' ) );
		$twice = OrderPersonalData::anonymize( $once, array( self::class, 'anonymizer' ) );

		self::assertCount( 1, array_keys( array_column( $twice['meta'], 'meta_key' ), '_anonymized', true ) );
	}

	/**
	 * The anonymized data still encodes to a payload that restore can verify.
	 */
	public function testAnonymizedPayloadStillVerifies(): void {
		$payload = Payload::fromArray( OrderPersonalData::anonymize( $this->data(), array( self::class, 'anonymizer' ) ) );

		self::assertTrue( Payload::fromJson( $payload->json )->matches( $payload->checksum() ) );
	}
}
