<?php

declare(strict_types=1);

namespace Coldstash\Archive;

/**
 * Read-only view of one archived order, built from its archive row and payload.
 *
 * Deliberately not a WC_Order: archived orders never enter wc_get_order(), so
 * third-party code can never receive a half-populated order object.
 */
final class ArchivedOrder {

	/**
	 * Use fromRow() instead.
	 *
	 * @param array<string, mixed> $row           Archive row without the payload column.
	 * @param Payload              $payload       Decoded payload.
	 * @param bool                 $checksumValid Whether the payload matches its stored checksum.
	 */
	private function __construct(
		private readonly array $row,
		private readonly Payload $payload,
		public readonly bool $checksumValid,
	) {
	}

	/**
	 * Builds the view from a full archive row, as returned by ArchiveRepository::find().
	 *
	 * @param array<string, mixed> $row Archive row including payload and payload_checksum.
	 * @return self
	 * @throws \JsonException When the payload is malformed or from a newer schema.
	 */
	public static function fromRow( array $row ): self {
		$payload = Payload::fromJson( (string) ( $row['payload'] ?? '' ) );
		$valid   = $payload->matches( (string) ( $row['payload_checksum'] ?? '' ) );

		unset( $row['payload'] );

		return new self( $row, $payload, $valid );
	}

	/**
	 * Original order ID.
	 *
	 * @return int
	 */
	public function id(): int {
		return (int) ( $this->row['id'] ?? 0 );
	}

	/**
	 * Order status without the "wc-" prefix.
	 *
	 * @return string
	 */
	public function status(): string {
		$status = (string) ( $this->row['status'] ?? '' );

		return str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Order currency code.
	 *
	 * @return string
	 */
	public function currency(): string {
		return (string) ( $this->row['currency'] ?? '' );
	}

	/**
	 * Order total.
	 *
	 * @return float
	 */
	public function total(): float {
		return (float) ( $this->row['total_amount'] ?? 0 );
	}

	/**
	 * Date the order was created, in GMT (Y-m-d H:i:s), or empty.
	 *
	 * @return string
	 */
	public function dateCreatedGmt(): string {
		return (string) ( $this->row['date_created_gmt'] ?? '' );
	}

	/**
	 * Date the order was archived, in GMT (Y-m-d H:i:s), or empty.
	 *
	 * @return string
	 */
	public function archivedAtGmt(): string {
		return (string) ( $this->row['archived_at'] ?? '' );
	}

	/**
	 * Payment method title as shown to the customer.
	 *
	 * @return string
	 */
	public function paymentMethodTitle(): string {
		$order = $this->payload->row( 'order' ) ?? array();

		return (string) ( $order['payment_method_title'] ?? $this->row['payment_method'] ?? '' );
	}

	/**
	 * Transaction ID from the payment gateway.
	 *
	 * @return string
	 */
	public function transactionId(): string {
		return (string) ( $this->row['transaction_id'] ?? '' );
	}

	/**
	 * Note the customer left at checkout.
	 *
	 * @return string
	 */
	public function customerNote(): string {
		$order = $this->payload->row( 'order' ) ?? array();

		return (string) ( $order['customer_note'] ?? '' );
	}

	/**
	 * Billing or shipping address fields, empty values removed.
	 *
	 * @param string $type "billing" or "shipping".
	 * @return array<string, string>
	 */
	public function address( string $type ): array {
		foreach ( $this->payload->rows( 'addresses' ) as $address ) {
			if ( ( $address['address_type'] ?? '' ) !== $type ) {
				continue;
			}

			$fields = array();
			foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ) as $key ) {
				$value = trim( (string) ( $address[ $key ] ?? '' ) );
				if ( '' !== $value ) {
					$fields[ $key ] = $value;
				}
			}

			return $fields;
		}

		return array();
	}

	/**
	 * Line items with quantity and line total.
	 *
	 * @return list<array{name:string, quantity:float, total:float}>
	 */
	public function items(): array {
		$meta = array();
		foreach ( $this->payload->rows( 'item_meta' ) as $row ) {
			$meta[ (int) ( $row['order_item_id'] ?? 0 ) ][ (string) ( $row['meta_key'] ?? '' ) ] = $row['meta_value'] ?? '';
		}

		$items = array();
		foreach ( $this->payload->rows( 'items' ) as $item ) {
			if ( 'line_item' !== ( $item['order_item_type'] ?? '' ) ) {
				continue;
			}

			$itemMeta = $meta[ (int) ( $item['order_item_id'] ?? 0 ) ] ?? array();
			$items[]  = array(
				'name'     => (string) ( $item['order_item_name'] ?? '' ),
				'quantity' => (float) ( $itemMeta['_qty'] ?? 0 ),
				'total'    => (float) ( $itemMeta['_line_total'] ?? 0 ),
			);
		}

		return $items;
	}

	/**
	 * Order notes, oldest first.
	 *
	 * @return list<array{date_gmt:string, content:string, customer:bool}>
	 */
	public function notes(): array {
		$customerNotes = array();
		foreach ( $this->payload->rows( 'note_meta' ) as $row ) {
			if ( 'is_customer_note' === ( $row['meta_key'] ?? '' ) && (bool) ( $row['meta_value'] ?? false ) ) {
				$customerNotes[ (int) ( $row['comment_id'] ?? 0 ) ] = true;
			}
		}

		$notes = array();
		foreach ( $this->payload->rows( 'notes' ) as $note ) {
			$notes[] = array(
				'date_gmt' => (string) ( $note['comment_date_gmt'] ?? '' ),
				'content'  => (string) ( $note['comment_content'] ?? '' ),
				'customer' => isset( $customerNotes[ (int) ( $note['comment_ID'] ?? 0 ) ] ),
			);
		}

		usort( $notes, static fn ( array $a, array $b ): int => strcmp( $a['date_gmt'], $b['date_gmt'] ) );

		return $notes;
	}
}
