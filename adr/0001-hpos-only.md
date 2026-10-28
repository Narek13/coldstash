# 1. HPOS only; legacy post storage unsupported

**Status:** accepted

## Context

WooCommerce stores orders two ways: the legacy `wp_posts`/`wp_postmeta` scheme,
and High-Performance Order Storage (`wc_orders` and friends). Both are live in
the wild. An archiver has to read and write whichever one the store uses.

Supporting both roughly doubles the storage layer: two candidate queries, two
capture paths, two restore paths, two sets of edge cases, and a payload format
that has to describe either shape.

## Decision

Support HPOS only. Detect legacy storage, keep the engine switched off, and say
why in an admin notice. Also refuse to run when HPOS is on but compatibility-mode
sync is still enabled — sync mirrors every write back into `wp_posts`, so
archiving would reclaim nothing and the merchant would rightly call it broken.

## Consequences

- Half the storage code, and the half that remains is the one WooCommerce is
  actually investing in.
- Stores on legacy storage cannot use the plugin. Some of them are exactly the
  old, bloated stores that need it most.
- The plugin becomes a mild nudge toward migrating, which the marketplace
  reviewers are likely to view favourably.
- Revisit if the install base says otherwise. The payload format is versioned,
  so adding a legacy capture path later does not invalidate existing archives.
