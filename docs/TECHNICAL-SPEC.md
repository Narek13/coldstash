# Coldstash – Archive Old Orders for WooCommerce — Technical Spec v0.1

**Status:** 1.0.0 released on WordPress.org.
**Target:** WordPress.org, free, HPOS-only.
**Author:** Narek Zakarian

---

## 1. What we're building and why

Move old orders out of WooCommerce's live order tables into archive tables in the same
database. Archived orders stay readable, searchable and restorable. Nothing is deleted.

### Evidence this is wanted

- **118 votes, status Open** on Woo's own feature board — the highest-voted request there
  ([auto-archive old orders](https://woocommerce.com/feature-request/auto-archive-old-orders/)),
  open since July 2023 with 37 comments and no core plan.
- Merchant-reported volumes in that thread: **125,000 orders since 2014**, **900,000+ orders**,
  one store with 100,000 orders reporting **8 million postmeta rows and a 3.5 GB database**.
- Stated requirements from merchants, in their words: archived orders must remain
  *"accessible for future reference"*, viewable *"as normal"* in the dashboard, and customers
  must *"access the archived orders at all times from their My Account page."*
- Existing options are small: the archiving plugins found each have around 100+ installs.

### The honest version of the value proposition

HPOS already fixed most of the *order list table* slowness — that was its whole point. So we
should not sell "your admin is slow." Under HPOS the residual, unfixed pain is:

1. **Total database size** — backups, hosting quotas, migration windows, staging clones.
2. **The satellite tables**, which HPOS did not shrink: `wc_orders_meta`,
   `woocommerce_order_items`, `woocommerce_order_itemmeta`, and order notes in
   `comments`/`commentmeta`. On a large store these hold 10–50× more rows than `wc_orders`.
3. **Anything that joins order meta** — exports, custom reports, third-party integrations.
4. **HPOS migration itself** is painful at 900k orders; archiving first makes it tractable.

That is a real, defensible pitch. Overclaiming on admin speed will get us bad reviews, and
under Woo's rules a rating below 3.5★ costs the Excellence badge.

---

## 2. Scope

### v1 does

- Archive orders in terminal statuses older than a configurable age, in background batches.
- Keep archived orders fully readable in wp-admin (read-only detail view) and searchable.
- Show archived orders in the customer's My Account order history (read-only, on by default).
- Restore any order, or all orders, byte-identical to its pre-archive state.
- Leave **WooCommerce Analytics untouched** so reported revenue never changes.
- WP-CLI for every operation.

### v1 does not

- Support legacy post-based order storage. HPOS is a hard requirement; the plugin refuses to
  activate its engine without it and says so plainly.
- Delete or purge anything, ever. No retention/GDPR erasure in v1 (that is the v2 upsell).
- Export to file, external storage, or a separate database.
- Support WooCommerce Subscriptions, Bookings or Memberships orders — these are **excluded from
  archiving** in v1 (see §5.3). Detected and skipped, not silently mishandled.
- Multisite network-level management. Works per-site; no network admin screen.

---

## 3. The central design decision: Analytics stays intact

Every "delete old orders" tool on the market breaks Analytics — the merchant archives 2019 and
their 2019 revenue report goes to zero. That is the #1 reason these plugins get one-star reviews.

WooCommerce Analytics reads from its **own lookup tables**, which are separate from order
storage: `wc_order_stats`, `wc_order_product_lookup`, `wc_order_coupon_lookup`,
`wc_order_tax_lookup`, `wc_customer_lookup`. These are compact — roughly one row per order plus
one per line item, with no meta — so they are not where the bloat lives.

**Decision: we never touch them.** Archiving an order leaves its Analytics rows exactly where
they are. Historical reports are bit-for-bit unchanged after archiving. This is the single
biggest differentiator and should lead the product page.

### The risk this creates

Woo's **"Regenerate Analytics data"** tool (WooCommerce → Status → Tools) rebuilds the lookup
tables by re-importing from the order tables. Run it after archiving and the archived orders'
stats are dropped. Same for the `wp wc cot`/Analytics import CLI paths and the
`OrdersScheduler` background import.

**Mitigations, all required in v1:**

- Hook the Analytics regeneration tool and interrupt it with a clear modal: "N orders are
  archived. Regenerating now will remove them from reports. Restore all first, or continue and
  accept the loss."
- Detect an Analytics import scheduled while orders are archived and surface a persistent admin
  notice plus a Site Health warning.
- Provide `wp coldstash restore --all` as the documented pre-regeneration step.
- Document it prominently. This is the sharp edge of the whole design; hiding it produces
  support tickets and refunds.

---

## 4. Architecture

### 4.1 What moves

| Source table | Archived? | Note |
|---|---|---|
| `wc_orders` | Yes | Root row |
| `wc_order_addresses` | Yes | 2 rows/order |
| `wc_order_operational_data` | Yes | 1 row/order |
| `wc_orders_meta` | Yes | Primary bloat source |
| `woocommerce_order_items` | Yes | Not an HPOS table — shared legacy table |
| `woocommerce_order_itemmeta` | Yes | Usually the largest table by row count |
| `comments` + `commentmeta` (`comment_type = 'order_note'`) | Yes | |
| `woocommerce_downloadable_product_permissions` | Yes, if no active downloads remain | |
| `wc_order_stats` and all Analytics lookups | **No** | See §3 |
| `wc_admin_notes`, `actionscheduler_*` | No | Not order data |

### 4.2 Archive storage: hybrid, not a pure mirror

A pure mirror of the six source tables breaks every time Woo adds a column — and it has, several
times (`type`, `parent_order_id`). A single JSON blob is schema-proof but unsearchable.

**We do both.** One root table with indexed columns for browsing and search, carrying a complete
versioned JSON payload for restore:

```
wp_coldstash_orders
  id                BIGINT UNSIGNED PRIMARY KEY   -- the original order ID, preserved
  parent_order_id   BIGINT UNSIGNED
  status            VARCHAR(20)
  type              VARCHAR(20)
  currency          CHAR(3)
  total_amount      DECIMAL(26,8)
  customer_id       BIGINT UNSIGNED   KEY
  billing_email     VARCHAR(320)      KEY
  billing_name      VARCHAR(200)      -- denormalised for search
  order_key         VARCHAR(100)      KEY
  date_created_gmt  DATETIME          KEY
  date_paid_gmt     DATETIME
  payment_method    VARCHAR(100)
  transaction_id    VARCHAR(100)      KEY
  item_summary      TEXT              -- denormalised product names for search
  payload           LONGTEXT          -- complete order, JSON
  payload_schema    SMALLINT UNSIGNED -- payload format version
  payload_checksum  CHAR(64)          -- SHA-256 of payload
  source_wc_version VARCHAR(20)
  archived_at       DATETIME          KEY
  batch_id          CHAR(36)          KEY
```

Indexed columns cover every archive browse and search case without decoding JSON. The payload
carries everything needed to reconstruct the order exactly, including columns that did not exist
when the plugin shipped. `payload_schema` lets a future version read old payloads.

Charset/collation must be copied from the source tables, not assumed — mismatched collation on
`billing_email` will break joins and comparisons on some hosts.

### 4.3 Archive pipeline

Action Scheduler (bundled with WooCommerce, no extra dependency) runs the work in batches.

```
select candidates  →  per order, in a transaction:
                        1. read all six source table row sets
                        2. build payload, compute checksum
                        3. INSERT into wp_coldstash_orders
                        4. re-read the archived row, verify checksum
                        5. only then DELETE from the six source tables
                      commit
                   →  next order
```

**Verify before delete, always.** Never delete a source row until the archived copy has been
read back and its checksum confirmed. A failed verification aborts that order, logs it, and
leaves the live data untouched.

Batch defaults: 50 orders per action, one action per minute, both configurable. Every step is
idempotent and resumable — a batch that dies mid-run leaves no partial order, because the whole
order is one transaction. Requires InnoDB; refuse to run on MyISAM order tables and say why.

A kill switch stops the queue immediately and is honoured between orders, not just between
batches.

### 4.4 Restore

Restore is the reverse: decode payload → INSERT into all six live tables inside one transaction,
preserving the original IDs → verify the order loads through `wc_get_order()` → delete the
archive row.

**Original IDs are preserved.** They have to be: merchants reference order numbers in accounting,
shipping and support. Restoring through `$order->save()` would mint a new ID, so restore writes
directly with `$wpdb` and explicit IDs.

**On ID reuse:** we only ever archive old orders, never recent ones, so the archived IDs are
always below `MAX(id)` in `wc_orders`. InnoDB's AUTO_INCREMENT counter is `MAX(id) + 1` — persisted
across restarts on MySQL 8.0+, recomputed from `MAX(id)` on 5.7 — and removing low IDs does not
move it. So archiving cannot cause ID reuse on either version. A pre-restore collision check runs
anyway, and a daily integrity scan flags any archived ID that has reappeared live.

### 4.5 Reading archived orders

Archived orders never enter `wc_get_order()`. That function returns `false` for them and we do
not filter it — silently faking a `WC_Order` from an archive row would leak a half-populated
object into third-party code, which is exactly how these plugins corrupt data.

Instead the plugin exposes its own read path:

- `Archive_Order_Reader::get( int $id ): ?Archived_Order` — an immutable value object built from
  the payload, with the same getters a template needs.
- A read-only admin screen rendering that object with a prominent "Archived — restore to edit"
  banner and a one-click Restore button.
- A My Account integration that appends archived orders to the customer's order list and renders
  a read-only view. Off switch in settings, default on, because merchants asked for exactly this.

---

## 5. Eligibility rules

### 5.1 Never archive

- Any order not in a terminal status. Default terminal set: `completed`, `cancelled`, `refunded`,
  `failed`. Configurable, but `pending`, `processing` and `on-hold` can never be added.
- Any order newer than the age threshold. Default 24 months, hard minimum 12.
- Any order with a child refund whose parent would not also be archived, and vice versa — parent
  and refunds always move together as one unit.
- Any order with unexpired downloadable product permissions.
- Any order flagged by the `coldstash_should_archive_order` filter returning false.

### 5.2 Legal retention

Retention periods differ by jurisdiction — 10 years in Germany, 6 in the UK, varies in the US.
We expose the threshold as a plain setting with a short explainer and **no default that could
breach a common retention rule**; 24 months is well inside all of them because archiving is not
deletion. Still worth saying explicitly in the docs: this plugin never deletes, so it does not
affect retention obligations.

### 5.3 Extension-owned orders

WooCommerce Subscriptions, Bookings and Memberships attach their own records to orders and
resolve them lazily. Archiving a subscription's parent or renewal order silently breaks renewals.

v1 detects these plugins and **excludes every order they touch** — subscription parents, renewals,
bookable orders, membership-granting orders. Skipped orders are reported in the preview with the
reason, so the merchant understands why their 900k orders yielded fewer candidates. Supporting
them properly is a v2 feature and probably the strongest upsell we have.

---

## 6. Admin UX

1. **Settings** (WooCommerce → Status → Order Archive, or its own submenu): age threshold,
   statuses, batch size and rate, My Account visibility, exclusion rules.
2. **Preview / dry run** — mandatory before the first archive run. Shows candidate count, row
   counts per table, estimated space reclaimed, and the full list of exclusions with reasons.
   No writes.
3. **Backup acknowledgement** — a checkbox the merchant must tick on first run. Not a dark
   pattern, a genuine one-time confirmation.
4. **Run / pause / resume** with live progress, orders done, ETA, and errors.
5. **Archive browser** — list table over the indexed columns: search by order number, email,
   name, transaction ID, product name; filter by date and status; bulk restore.
6. **Read-only order view** with restore button.
7. **Site Health checks** — HPOS active, InnoDB, Action Scheduler healthy, archive integrity,
   Analytics-regeneration warning.

Accessibility is a review criterion and a differentiator: every screen keyboard-navigable,
WCAG 2.2 AA, list tables using core `WP_List_Table` semantics, progress announced via
`aria-live`, no colour-only status indicators.

---

## 7. Integration surface

Declared HPOS compatibility, on `before_woocommerce_init`:

```php
add_action( 'before_woocommerce_init', static function (): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );
```

Runtime gate:

```php
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
    // Engine stays off; admin notice explains HPOS is required.
}
```

**Public API for other developers** (a Woo review plus, and cheap to add):

- Filters: `coldstash_should_archive_order`, `coldstash_candidate_exclusions`, `coldstash_payload`,
  `coldstash_restore_payload`, `coldstash_batch_size`
- Actions: `coldstash_order_archived`, `coldstash_order_restored`, `coldstash_run_completed`
- WP-CLI: `wp coldstash preview|run|status|restore <id>|restore --all|verify`

---

## 8. Review checklist

| Requirement | Plan |
|---|---|
| HPOS compatibility declared | §7 |
| PHP minimum | **Open decision** — 8.1 proposed, matching WooCommerce 11.5 (Jan 2027). Review floor is 7.4 |
| Latest two WP and Woo majors | CI matrix against both, refreshed each Woo release (~10/yr) |
| QIT: security, API, malware, activation, E2E | Run locally before every submission |
| No unprepared SQL | Unavoidable direct SQL here — every statement via `$wpdb->prepare`, table names from an allowlist, never interpolated from input. Expect reviewers to look hard at this |
| i18n | Full text domain coverage, no concatenated strings |
| Accessibility | WCAG 2.2 AA, §6 |
| Uninstall behaviour | Archive tables **never** dropped silently. Uninstall offers restore-all; dropping requires explicit opt-in |
| Documentation + support email | Required before submission |
| Review timeline | Docs say 30 days; observed cases 1–3.5 months. Plan for it |

---

## 9. Build plan

| Milestone | Content |
|---|---|
| **M0** | Spec agreed, repo scaffolded, CI, coding standards, PHPUnit + integration harness |
| **M1** | Schema, payload builder, eligibility engine, Action Scheduler batching, archive path. CLI only |
| **M2** | Restore, integrity verification, daily scan, kill switch, restore-all |
| **M3** | Admin: settings, preview, progress, archive browser, read-only view, Site Health |
| **M4** | My Account integration; Analytics-regeneration interception |
| **M5** | **Benchmark harness** — seed 500k+ orders, measure DB size, backup time, meta-join queries, HPOS migration time, before and after. If the numbers are unimpressive, we find out here and rethink before submitting |
| **M6** | QIT, docs, product page assets, submission |

M5 is deliberately late but non-negotiable: it is the evidence the product page will be built on,
and the point at which weak numbers can still change the plan cheaply.

---

## 10. Open decisions

1. **Name — decided 2026-10-26:** "Coldstash – Archive Old Orders for WooCommerce", slug
   `coldstash`. `Order Archive for WooCommerce` turned out to be an existing paid Woo Marketplace
   extension, and the wordpress.org review rejected it as generic and confusable.
2. **PHP minimum** — 8.1 vs 7.4.
3. **My Account integration in v1?** Merchants asked for it explicitly, and it is the largest
   single piece of v1. Cutting it to v1.1 halves M4.
