# Coldstash – Archive Old Orders for WooCommerce

Move old WooCommerce orders out of the live order tables into archive tables in
the same database. Archived orders stay searchable and restorable, and
**WooCommerce Analytics reports are left untouched** — historical revenue
figures do not change when you archive.

Status: **1.0.0, not yet released**. See [`docs/TECHNICAL-SPEC.md`](docs/TECHNICAL-SPEC.md)
for the full design, and `CLAUDE.md` for the decisions behind it.

## Why

WooCommerce's own most-voted feature request — [auto-archive old orders][fr],
118 votes, open since 2023 — with merchants reporting 125,000 to 900,000+
orders, 8 million rows of order meta and 3.5 GB databases.

HPOS already fixed most of the slow-order-list problem. What it did not fix is
total database size and the satellite tables it never migrated:
`wc_orders_meta`, `woocommerce_order_items`, `woocommerce_order_itemmeta` and
order notes in `wp_comments`. On a large store those hold far more rows than
`wc_orders` itself. That is what this archives.

[fr]: https://woocommerce.com/feature-request/auto-archive-old-orders/

## Requirements

- WordPress 6.4+
- WooCommerce 9.0+ with **HPOS enabled and compatibility-mode sync off**
- PHP 8.1+
- InnoDB order tables (archiving runs each order in a transaction)

Legacy post-based order storage is not supported. The plugin activates, detects
it, and stays idle with an explanation rather than doing something unsafe.

## How it works

1. **Candidate query** finds orders in terminal statuses older than the age
   threshold, excluding anything with active downloads or attached to
   Subscriptions, Bookings or Memberships.
2. **Payload builder** captures every row belonging to the order — six order
   tables plus notes and download permissions — as a versioned JSON document.
3. **Archive** writes it to `wp_coldstash_orders`, reads it straight back
   out of MySQL, and compares checksums.
4. **Only then** are the live rows deleted, all inside one transaction.

Restore is the exact reverse, preserving the original order ID.

## Development

```bash
composer install
composer smoke   # standalone: no WordPress, no database
composer lint
composer test
```

`bin/smoke-test.php` exercises the eligibility rules and payload checksum in
about a second — the two pieces that must never be wrong and happen to be pure
PHP.

## Extending

Filters: `coldstash_should_archive_order`, `coldstash_candidate_exclusions`
Actions: `coldstash_order_archived`, `coldstash_order_restored`, `coldstash_run_completed`

WP-CLI:

```bash
wp coldstash preview
wp coldstash run [--batch=<n>] [--max=<n>]
wp coldstash status
wp coldstash restore <id> | --all
wp coldstash verify
```

## License

GPL-2.0-or-later
