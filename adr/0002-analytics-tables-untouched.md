# 2. Never touch the Analytics lookup tables

**Status:** accepted

## Context

Every "delete old orders" tool on the market breaks WooCommerce Analytics: the
merchant clears 2019 and their 2019 revenue report goes to zero. It is the most
common one-star review in this category.

WooCommerce Analytics does not read the order tables. It reads its own lookup
tables — `wc_order_stats`, `wc_order_product_lookup`, `wc_order_coupon_lookup`,
`wc_order_tax_lookup`, `wc_customer_lookup` — which are compact: roughly one row
per order plus one per line item, with no meta. They are not where the bloat is.

## Decision

Archiving never touches them. An archived order keeps its Analytics rows exactly
where they are, so historical reports are identical before and after.

## Consequences

- The headline promise of the product: archive freely, your reports do not move.
- The archive and the lookup tables can disagree. Woo's "Regenerate Analytics
  data" tool rebuilds the lookups *from* the order tables, which would drop every
  archived order out of reports. `Admin\AnalyticsGuard` annotates that tool and
  warns on the Status screen; `wp coldstash restore --all` is the documented
  way to regenerate safely.
- This is the sharpest edge in the design. It must be prominent in the docs and
  on the product page, not buried — a merchant who discovers it after the fact
  has lost their reports and will say so publicly.
