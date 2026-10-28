# Architecture

A map of the code. The *why* behind the big decisions is in [`../adr/`](../adr/);
the full product spec is in [`TECHNICAL-SPEC.md`](TECHNICAL-SPEC.md).

## Boot

`coldstash.php` defines the `COLDSTASH_*` constants,
registers the Composer autoloader (falling back to a small PSR-4 loader so the
plugin runs from a bare checkout), declares HPOS compatibility on
`before_woocommerce_init`, and boots `Coldstash\Plugin` on `plugins_loaded`.

`Plugin` is a small service locator, not a DI container — a WordPress plugin
should boot fast. Services are lazily constructed and shared. It gates itself:
no WooCommerce, or no HPOS, and the engine never registers.

## Layers

```
Support\Hpos          Which tables exist, whether HPOS is authoritative,
                      whether the storage engine supports transactions.
                      Also the table-name allowlist — no table name reaches
                      a query from anywhere else.

Settings\Settings     Immutable value object read from one option. Clamps
                      the age threshold and strips any status that must
                      never be archivable.

Archive\Eligibility   Pure function: one order row plus flags in, an
                      ExclusionReason or null out. No $wpdb, no WC objects,
                      so it is testable without WordPress.

Archive\CandidateQuery  The SQL side of eligibility: the expensive exclusion
                      checks (downloads, Subscriptions, Bookings,
                      Memberships) done once per batch rather than per order.

Archive\PayloadBuilder  Reads all six order tables plus notes and download
                      permissions into a Payload.

Archive\Payload       Versioned, checksummed JSON document. Immutable.

Archive\ArchiveService   Capture -> insert -> read back -> compare checksum ->
                      delete live rows, in one transaction.

Archive\RestoreService   The reverse, preserving the original order ID.

Archive\Scheduler     Action Scheduler batching. Honours the kill switch
                      between orders, not just between batches.

Storage\Schema        dbDelta table definitions.
Storage\ArchiveRepository   All reads and writes against the archive tables.

Admin\AdminMenu       The dashboard screen and its admin-post handlers.
Admin\Assets          Screen-scoped CSS/JS plus the REST root and nonce.
Admin\AnalyticsGuard  Warns before Woo's Analytics regeneration would drop
                      archived orders out of reports.

Rest\RestController   coldstash/v1. Everything the dashboard does, for a
                      future SPA and for external tooling.

Cli\Command           wp coldstash preview|run|status|restore|verify.
```

## Data flow, archiving one order

```
Scheduler::runBatch
  └─ CandidateQuery::candidates      SQL: terminal status, older than cut-off,
                                     no active downloads, no extension links
     └─ ArchiveService::archive
        ├─ PayloadBuilder::build     six tables + notes + downloads -> Payload
        ├─ START TRANSACTION
        ├─ ArchiveRepository::insert indexed columns + payload + checksum
        ├─ ArchiveRepository::storedPayload   read back from MySQL
        ├─ checksum compare          mismatch -> ROLLBACK, order untouched
        ├─ delete live rows          items, itemmeta, meta, operational,
        │                            addresses, notes, downloads, order
        └─ COMMIT
```

`wc_order_stats` and the other Analytics lookup tables are absent from that
list on purpose. See [ADR 0002](../adr/0002-analytics-tables-untouched.md).

## Extension points

Filters: `coldstash_should_archive_order`, `coldstash_candidate_exclusions`
Actions: `coldstash_order_archived`, `coldstash_order_restored`, `coldstash_run_completed`

## Testing

| Layer | How |
|---|---|
| Eligibility, Settings, Payload | `tests/phpunit/Unit`, Brain Monkey, no WordPress |
| Same, with zero dependencies | `bin/smoke-test.php` — runs on a bare checkout |
| Archive/restore round-trip | DDEV + `ddev oa run` / `ddev oa restore --all`; a database round-trip cannot be meaningfully mocked |
| Static analysis | PHPStan level 5, with WooCommerce stubbed in `tests/phpstan/bootstrap.php` |
