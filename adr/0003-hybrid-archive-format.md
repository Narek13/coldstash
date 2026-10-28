# 3. Hybrid archive: indexed columns plus a JSON payload

**Status:** accepted

## Context

An order's data is spread across six tables, plus notes in `wp_comments` and
download permissions. The archive has to store all of it, let the merchant
search it, and restore it exactly.

Two obvious formats, each with one fatal flaw:

- **Mirror tables** (a copy of each source table): searchable and cheap to
  restore, but they break whenever WooCommerce adds a column — and it has,
  repeatedly (`type`, `parent_order_id`).
- **A single JSON blob:** immune to schema drift, but unsearchable without
  decoding every row.

## Decision

Do both. One archive table carries indexed columns for everything the merchant
browses or searches by — order number, email, name, order key, transaction ID,
dates, a denormalised product summary — alongside a complete JSON payload with a
`schema` version and a SHA-256 checksum.

## Consequences

- Archive browse and search never decode a payload; they hit indexes.
- A column added by a later WooCommerce release round-trips untouched, because
  the payload captured whatever columns existed at capture time.
- A column *removed* later makes restore fail loudly rather than silently drop
  data. That is the behaviour we want.
- Some duplication: indexed values also live inside the payload. Worth it.
- `payload_schema` gives a future version a migration path for old payloads.
