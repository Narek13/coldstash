# CLAUDE.md — Coldstash – Archive Old Orders for WooCommerce

Context for future Claude sessions on this project.

## What this is
**Name (2026-10-26):** "Coldstash – Archive Old Orders for WooCommerce", wp.org slug and
text domain `coldstash`, code prefix `coldstash` / `Coldstash\` / `COLDSTASH_`. Renamed from
"Order Archive for WooCommerce" because that is an existing paid Woo Marketplace extension
(woocommerce.com/products/order-archive) — never reintroduce that name. The GitHub repo, local folder and
DDEV project were renamed to `coldstash` on 2026-10-28.

A WooCommerce extension that moves old orders out of the live order tables
into archive tables in the same database. Archived orders stay readable,
searchable and restorable. Nothing is ever deleted.

**Distribution:** free on WordPress.org (https://wordpress.org/plugins/coldstash,
approved 2026-10-28). wp.org forbids locking features in the free plugin, so any
paid features must ship as a separate add-on in its own repo.

## Current stage
**1.0.0 approved on WordPress.org.** Releases go out by pushing a `v*` tag — see
`RELEASING.md`. Read `docs/TECHNICAL-SPEC.md` for the product design,
`docs/architecture.md` for the code map, and `adr/` for the decisions and what
they cost.

## Structure
Deliberately mirrors **A11yFleet** (`~/Local Sites/a11yfleet/app/public/wp-content/plugins/A11yFleet`),
which is the reference for how Narek builds plugins: PSR-4 `src/`, a small
service-locator `Plugin`, `Activation/`, `Storage/Schema` with `dbDelta`,
`Rest/RestController`, `Settings/Settings` in its own namespace, `templates/`,
`adr/`, `bin/smoke-test.php`, and the same phpcs/phpstan/phpunit/DDEV/CI setup.

One deliberate divergence: A11yFleet builds its admin assets from
`src/Admin/resources` through webpack into a gitignored `/assets/`. This plugin's
admin is one server-rendered screen with ~40 lines of CSS, so `assets/` is
hand-authored and committed. Add the build step when the SPA lands.

## Decisions made
- **v1 scope:** archive + restore engine. Not retention/purge, not export-to-file.
- **HPOS only**, and idle when compatibility-mode sync is on — see `adr/0001`.
- **Analytics lookup tables are never touched** — the core differentiator, and
  the sharpest edge in the design. See `adr/0002` and `Admin\AnalyticsGuard`.
- **Hybrid archive format:** indexed columns + versioned checksummed JSON payload
  — see `adr/0003`.
- **Verify before delete**, original order IDs preserved on restore — `adr/0004`.
- Subscriptions / Bookings / Memberships orders are detected and **excluded** in v1.

## Open decisions
See spec §10: PHP minimum, and when the My Account integration lands.

## Conventions
- PHP 8.1+, PSR-4 autoloading, `declare(strict_types=1)`, `final` classes.
- **WooCommerce Marketplace standards:** PHPCS with `WooCommerce-Core` from
  `woocommerce/woocommerce-sniffs` (full WordPress standard + Squiz.Commenting +
  WooCommerce hook-doc sniffs + PHPCompatibilityWP), minus the sniffs that fight
  namespaced OO. So WordPress formatting (tabs, padded parentheses, `array()`,
  Yoda), not PSR-12. PHPStan level 5.
- PHPUnit 9.6 with Brain\Monkey for pure logic; the archive/restore round-trip
  is exercised in DDEV, not mocked.
- Action Scheduler (bundled with WooCommerce) for all batch work — no custom cron.
- Every SQL statement via `$wpdb->prepare`; table names come from the
  `Support\Hpos` allowlist and are never interpolated from input.

## Environment notes
- **DDEV** is the dev environment (`ddev start` provisions WP + WooCommerce +
  HPOS + seeded orders). See `SETUP.md`.
- The git repo (github.com/Narek13/coldstash) lives at
  `~/Local Sites/a11yfleet/app/public/wp-content/plugins/coldstash`,
  so it is also available on that Local site's WooCommerce for quick testing.
- DDEV project `coldstash`: https://coldstash.ddev.site, `admin` / `admin`.
- The shell Claude gets on this Mac has PHP 8.3 and Composer, so `composer test`
  (PHPCS, PHPStan, PHPUnit, smoke test) runs locally. CI runs the same on GitHub.

## How to help
Next milestone is M5 in the spec: a bulk-SQL seeder and a benchmark harness at
500k+ orders, measuring database size, backup time and meta-join queries before
and after. That is the evidence the product page will be built on, and the last
point to rethink if the numbers are weak. Keep `docs/TECHNICAL-SPEC.md` in sync when scope changes.
