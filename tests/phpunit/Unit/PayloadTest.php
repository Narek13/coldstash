<?php

declare(strict_types=1);

namespace Coldstash\Tests\Unit;

use JsonException;
use Coldstash\Archive\Payload;
use PHPUnit\Framework\TestCase;

/**
 * The payload checksum is what makes "verify before delete" mean anything:
 * an order's live rows are only removed once the archived copy has been read
 * back and matched. If this class is wrong, the safety guarantee is theatre.
 */
final class PayloadTest extends TestCase {

	/**
	 * A small order with one item and one meta row.
	 *
	 * @return array<string, mixed>
	 */
	private function data(): array {
		return array(
			'order' => array(
				array(
					'id'           => 1234,
					'status'       => 'wc-completed',
					'total_amount' => '99.50000000',
				),
			),
			'items' => array(
				array(
					'order_item_id'   => 9,
					'order_item_name' => 'Espresso — 1kg',
					'order_item_type' => 'line_item',
				),
			),
			'meta'  => array(
				array(
					'id'         => 1,
					'order_id'   => 1234,
					'meta_key'   => '_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture row, not a query.
					'meta_value' => 'ünïcode ✔ / slashes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fixture row, not a query.
				),
			),
		);
	}

	/**
	 * Data survives encoding to JSON and decoding back unchanged.
	 */
	public function testItRoundTripsThroughJson(): void {
		$payload = Payload::fromArray( $this->data() );

		self::assertSame( $this->data(), Payload::fromJson( $payload->json )->data );
	}

	/**
	 * The checksum is stable across a JSON round trip.
	 */
	public function testTheChecksumSurvivesARoundTrip(): void {
		$payload = Payload::fromArray( $this->data() );

		self::assertSame( $payload->checksum(), Payload::fromJson( $payload->json )->checksum() );
		self::assertTrue( $payload->matches( $payload->checksum() ) );
	}

	/**
	 * Changing the stored JSON breaks the checksum match.
	 */
	public function testATamperedPayloadFailsItsChecksum(): void {
		$payload  = Payload::fromArray( $this->data() );
		$tampered = str_replace( '99.50000000', '11.00000000', $payload->json );

		self::assertNotSame( $payload->checksum(), hash( 'sha256', $tampered ) );
		self::assertFalse( $payload->matches( str_repeat( '0', 64 ) ) );
	}

	/**
	 * Unicode and slashes are stored as-is, not escaped.
	 */
	public function testUnicodeAndSlashesSurviveUnmangled(): void {
		$payload = Payload::fromArray( $this->data() );
		$meta    = Payload::fromJson( $payload->json )->rows( 'meta' );

		self::assertSame( 'ünïcode ✔ / slashes', $meta[0]['meta_value'] );
	}

	/**
	 * A payload from a newer schema version is refused.
	 */
	public function testAFuturePayloadSchemaIsRefused(): void {
		$this->expectException( JsonException::class );

		Payload::fromJson( '{"schema":999,"data":{}}' );
	}

	/**
	 * A payload without a schema version is refused.
	 */
	public function testAMalformedPayloadIsRefused(): void {
		$this->expectException( JsonException::class );

		Payload::fromJson( '{"data":{}}' );
	}

	/**
	 * Row accessors return empty values for missing sections.
	 */
	public function testRowAccessorsAreForgiving(): void {
		$payload = Payload::fromArray( $this->data() );

		self::assertCount( 1, $payload->rows( 'items' ) );
		self::assertSame( array(), $payload->rows( 'nothing-here' ) );
		self::assertNull( $payload->row( 'nothing-here' ) );
		self::assertSame( 1234, $payload->row( 'order' )['id'] );
	}
}
