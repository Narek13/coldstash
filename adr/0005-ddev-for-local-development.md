# 5. DDEV for local development

**Status:** accepted

## Context

This plugin cannot be meaningfully tested without WordPress, WooCommerce, HPOS
enabled, and a pile of orders with varied dates and statuses. Unit tests cover
the pure logic; everything interesting is a database round-trip.

## Decision

Use DDEV, matching the setup already used for A11yFleet: WordPress core lives in
`.ddev/wordpress` (gitignored), the plugin repo is mounted read-only into
`wp-content/plugins/<project>`, and a `post-start` hook provisions the site.

Beyond the A11yFleet setup, the hook also installs WooCommerce, enables HPOS,
disables compatibility-mode sync, and seeds orders.

## Consequences

- `ddev start` produces a store with orders ready to archive, with no manual steps.
- `ddev oa …` and `ddev seed-orders …` wrap the plugin's WP-CLI commands.
- Core is downloaded rather than committed, so the repo stays a plugin repo.
- Read-only mount means the plugin can never write into its own directory, which
  is how it will behave on a real managed host anyway.
