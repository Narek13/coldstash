<?php

declare(strict_types=1);

namespace Coldstash\Tests\Unit;

use JsonException;
use Coldstash\Archive\ArchivedOrder;
use Coldstash\Archive\Payload;
use PHPUnit\Framework\TestCase;

/**
 * The read-only order view is what a merchant sees instead of the edit screen,
 * so it has to read the payload the same way RestoreService writes it back.
 */
final class ArchivedOrderTest extends TestCase {

	/**
	 * An archive row as ArchiveRepository::find() returns it.
	 *
	 * @param string|null $checksum Stored checksum; null for the correct one.
	 * @return array<string, mixed>
	 */
	private function row( ?string $checksum = null ): array {
		$payload = Payload::fromArray(
			array(
				'order'     => array(
					array(
						'id'                   => 1234,
						'status'               => 'wc-completed',
						'payment_method_title' => 'Credit card',
						'customer_note'        => 'Leave at the door.',
					),
				),
				'addresses' => array(
					array(
						'address_type' => 'billing',
						'first_name'   => 'Ada',
						'last_name'    => 'Lovelace',
						'address_2'    => '',
						'city'         => 'London',
						'email'        => 'ada@example.com',
					),
				),
				'items'     => array(
					array(
						'order_item_id'   => 9,
						'order_item_name' => 'Espresso 1kg',
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
					array(
						'order_item_id' => 9,
						'meta_key'      => '_line_total', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture row, not a query.
						'meta_value'    => '39.80', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fixture row, not a query.
					),
				),
				'notes'     => array(
					array(
						'comment_ID'       => 2,
						'comment_date_gmt' => '2023-01-02 10:00:00',
						'comment_content'  => 'Shipped.',
					),
					array(
						'comment_ID'       => 1,
						'comment_date_gmt' => '2023-01-01 10:00:00',
						'comment_content'  => 'Payment received.',
					),
				),
				'note_meta' => array(
					array(
						'comment_id' => 2,
						'meta_key'   => 'is_customer_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture row, not a query.
						'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fixture row, not a query.
					),
				),
			)
		);

		return array(
			'id'               => 1234,
			'status'           => 'wc-completed',
			'currency'         => 'EUR',
			'total_amount'     => '44.80000000',
			'transaction_id'   => 'ch_123',
			'date_created_gmt' => '2023-01-01 09:00:00',
			'archived_at'      => '2026-10-01 00:00:00',
			'payload'          => $payload->json,
			'payload_checksum' => $checksum ?? $payload->checksum(),
		);
	}

	/**
	 * Indexed columns come through with the status prefix stripped.
	 */
	public function test_reads_indexed_columns(): void {
		$order = ArchivedOrder::fromRow( $this->row() );

		$this->assertSame( 1234, $order->id() );
		$this->assertSame( 'completed', $order->status() );
		$this->assertSame( 'EUR', $order->currency() );
		$this->assertSame( 44.8, $order->total() );
		$this->assertSame( 'ch_123', $order->transactionId() );
		$this->assertSame( 'Credit card', $order->paymentMethodTitle() );
		$this->assertSame( 'Leave at the door.', $order->customerNote() );
		$this->assertTrue( $order->checksumValid );
	}

	/**
	 * Address fields come from the matching address row, empty values dropped.
	 */
	public function test_address_drops_empty_fields(): void {
		$order = ArchivedOrder::fromRow( $this->row() );

		$this->assertSame(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'city'       => 'London',
				'email'      => 'ada@example.com',
			),
			$order->address( 'billing' )
		);
		$this->assertSame( array(), $order->address( 'shipping' ) );
	}

	/**
	 * Only line items are listed, with quantity and total from item meta.
	 */
	public function test_items_are_line_items_with_quantity_and_total(): void {
		$items = ArchivedOrder::fromRow( $this->row() )->items();

		$this->assertSame(
			array(
				array(
					'name'     => 'Espresso 1kg',
					'quantity' => 2.0,
					'total'    => 39.8,
				),
			),
			$items
		);
	}

	/**
	 * Notes are sorted oldest first and customer notes are flagged.
	 */
	public function test_notes_are_chronological_and_flag_customer_notes(): void {
		$notes = ArchivedOrder::fromRow( $this->row() )->notes();

		$this->assertSame( array( 'Payment received.', 'Shipped.' ), array_column( $notes, 'content' ) );
		$this->assertSame( array( false, true ), array_column( $notes, 'customer' ) );
	}

	/**
	 * A payload that no longer matches its checksum is still readable but flagged.
	 */
	public function test_flags_a_checksum_mismatch(): void {
		$order = ArchivedOrder::fromRow( $this->row( str_repeat( '0', 64 ) ) );

		$this->assertFalse( $order->checksumValid );
	}

	/**
	 * A malformed payload is refused rather than shown half-read.
	 */
	public function test_refuses_a_malformed_payload(): void {
		$row            = $this->row();
		$row['payload'] = '{"broken":';

		$this->expectException( JsonException::class );

		ArchivedOrder::fromRow( $row );
	}
}
