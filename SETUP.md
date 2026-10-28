# Local setup

Two ways to run this: DDEV (self-contained, recommended) or dropping the plugin
into an existing WordPress install.

## DDEV

```bash
cd coldstash
ddev start
```

`ddev start` provisions everything on first run via the `post-start` hook:

1. Downloads and installs WordPress into `.ddev/wordpress` (gitignored).
2. Installs and activates WooCommerce, and skips the onboarding wizard.
3. Runs WooCommerce's database updater — this is what creates the HPOS tables.
4. Turns HPOS **on** and compatibility-mode sync **off**.
5. Activates this plugin.
6. Seeds 300 orders spread over 4 years (`SEED_ORDERS` / `SEED_YEARS` in
   `.ddev/config.yaml`).

The repo is mounted read-only at `wp-content/plugins/coldstash` (the DDEV project
name, same as the WordPress.org slug), so editing files here is immediately live in the
site at https://coldstash.ddev.site.

Then:

| Command | What it does |
|---|---|
| `ddev launch wp-admin` | wp-admin, `admin` / `admin` |
| `ddev oa preview` | what would be archived, and why the rest would not |
| `ddev oa run` | archive it (foreground) |
| `ddev oa status` | archive size and whether a background run is queued |
| `ddev oa restore --all` | put everything back |
| `ddev oa verify` | re-check every archived payload against its checksum |
| `ddev seed-orders 2000 6` | add 2,000 more orders over 6 years |
| `ddev composer install` | dev dependencies (PHPCS, PHPStan, PHPUnit) |

The dashboard is at **WooCommerce → Coldstash**.

Don't run Plugin Check against the mounted plugin in DDEV: it is the whole repo,
including `.ddev/wordpress` (WordPress + WooCommerce), so the scan never finishes. CI runs Plugin Check on the built release package instead; to
check it locally, run `bash bin/build-zip.sh` and check `dist/coldstash`.

### If the HPOS tables are missing

WooCommerce creates them from its installer. If `ddev oa preview` complains,
open wp-admin once and then re-run the provisioner:

```bash
ddev exec .ddev/commands/setup.sh
```

## Existing WordPress install

Copy or symlink this folder into `wp-content/plugins/` and activate it. No build
step is required — the plugin falls back to its own PSR-4 autoloader when
`vendor/` is absent.

It needs WooCommerce 9.0+ with HPOS enabled and sync disabled:

- **WooCommerce → Settings → Advanced → Features → High-Performance order storage**
- Uncheck *Enable compatibility mode*

Without both, the plugin activates but stays idle and tells you which one is wrong.

## Quality checks

```bash
composer install
composer smoke     # standalone: no WordPress, no database, ~1 second
composer phpcs
composer phpstan
composer phpunit
composer test      # all four
```

`bin/smoke-test.php` runs with no dependencies at all — `php bin/smoke-test.php`
works on a bare checkout.
