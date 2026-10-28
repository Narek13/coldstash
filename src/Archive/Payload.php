<?php

declare(strict_types=1);

namespace Coldstash\Archive;

use JsonException;

/**
 * The complete captured state of one order, as a versioned JSON document.
 *
 * The checksum is taken over the encoded string rather than the array, so it
 * is deterministic by construction — no need to canonicalise key order. It is
 * the thing that makes "verify before delete" meaningful: we write the payload,
 * read it back, and only remove the live rows if the bytes match.
 */
final class Payload {

	public const SCHEMA_VERSION = 1;

	/**
	 * Use fromArray() or fromJson() instead.
	 *
	 * @param int                  $schemaVersion Payload schema version.
	 * @param array<string, mixed> $data          Decoded order data.
	 * @param string               $json          Encoded document the checksum is taken over.
	 */
	private function __construct(
		public readonly int $schemaVersion,
		public readonly array $data,
		public readonly string $json,
	) {
	}

	/**
	 * Encodes captured order data into a versioned payload.
	 *
	 * @param array<string, mixed> $data          Order rows keyed by source (order, items, meta, ...).
	 * @param int                  $schemaVersion Schema version to stamp on the document.
	 * @return self
	 * @throws JsonException When the data cannot be encoded.
	 */
	public static function fromArray( array $data, int $schemaVersion = self::SCHEMA_VERSION ): self {
		$document = array(
			'schema' => $schemaVersion,
			'data'   => $data,
		);

		$json = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- must run without WordPress.
			$document,
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);

		return new self( $schemaVersion, $data, $json );
	}

	/**
	 * Decodes a stored payload, refusing malformed or future-schema documents.
	 *
	 * @param string $json Stored payload document.
	 * @return self
	 * @throws JsonException When the JSON is invalid, malformed, or from a newer schema.
	 */
	public static function fromJson( string $json ): self {
		/**
		 * Decoded document.
		 *
		 * @var array<string, mixed> $document
		 */
		$document = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );

		$schema = (int) ( $document['schema'] ?? 0 );
		$data   = $document['data'] ?? null;

		if ( $schema < 1 || ! is_array( $data ) ) {
			throw new JsonException( 'Archive payload is malformed or has no schema version.' );
		}

		if ( $schema > self::SCHEMA_VERSION ) {
			throw new JsonException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not HTML; must run without WordPress.
				"Archive payload schema {$schema} is newer than this plugin understands."
			);
		}

		return new self( $schema, $data, $json );
	}

	/**
	 * SHA-256 of the encoded document.
	 *
	 * @return string
	 */
	public function checksum(): string {
		return hash( 'sha256', $this->json );
	}

	/**
	 * Whether a stored checksum matches this payload, compared in constant time.
	 *
	 * @param string $checksum Checksum recorded when the payload was written.
	 * @return bool
	 */
	public function matches( string $checksum ): bool {
		return hash_equals( $this->checksum(), $checksum );
	}

	/**
	 * All rows stored under a key, skipping anything that is not a row.
	 *
	 * @param string $key Data key, e.g. "items" or "meta".
	 * @return list<array<string, mixed>>
	 */
	public function rows( string $key ): array {
		$rows = $this->data[ $key ] ?? array();

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * The first row stored under a key.
	 *
	 * @param string $key Data key, e.g. "order".
	 * @return array<string, mixed>|null
	 */
	public function row( string $key ): ?array {
		$rows = $this->rows( $key );

		return $rows[0] ?? null;
	}
}
