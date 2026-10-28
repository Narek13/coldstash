#!/bin/bash
## Description: Install WordPress + WooCommerce with HPOS and activate Coldstash (post-start).
## Usage: setup
## Example: "ddev setup"

set -e

if [ ! -f .ddev/wordpress/wp-load.php ]; then
  wp core download
fi

if wp core is-installed 2>/dev/null; then
  echo "WordPress already installed."
else
  echo "Installing WordPress..."
  wp core install \
    --title="${WP_TITLE}" \
    --admin_user="${ADMIN_USER}" \
    --admin_password="${ADMIN_PASS}" \
    --url="${DDEV_PRIMARY_URL}" \
    --admin_email="admin@example.com" \
    --skip-email

  wp rewrite flush --hard
  wp plugin delete hello akismet 2>/dev/null || true
fi

# WooCommerce is a runtime peer, not a Composer dependency — install it here.
if ! wp plugin is-installed woocommerce 2>/dev/null; then
  echo "Installing WooCommerce..."
  wp plugin install woocommerce --activate
else
  wp plugin activate woocommerce || true
fi

# Skip the onboarding wizard so wp-admin is usable immediately.
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null || true
wp option update woocommerce_task_list_hidden yes >/dev/null || true

# Run any pending WooCommerce database updates. This is what creates the HPOS
# tables; enabling the feature before they exist gives you a broken store.
wp wc update 2>/dev/null || true

# HPOS on, legacy sync off. Sync left on would mirror every write back into
# wp_posts, so archiving would reclaim nothing — the plugin refuses to run in
# that state and says so.
wp option update woocommerce_custom_orders_table_enabled yes >/dev/null
wp option update woocommerce_custom_orders_table_data_sync_enabled no >/dev/null

if ! wp db query "SELECT 1 FROM $(wp db prefix --allow-root 2>/dev/null || echo wp_)wc_orders LIMIT 1" >/dev/null 2>&1; then
  echo "NOTE: the HPOS tables are not present yet."
  echo "      Open wp-admin once (WooCommerce runs its installer), then: ddev exec .ddev/commands/setup.sh"
fi

# The plugin is mounted read-only at wp-content/plugins/${DDEV_PROJECT}.
wp plugin activate "${DDEV_PROJECT}" || true

# Seed orders on first provision only, so restarts stay fast.
if [ "${SEED_ORDERS:-0}" -gt 0 ] && [ ! -f .ddev/.seeded ]; then
  echo "Seeding ${SEED_ORDERS} orders across ${SEED_YEARS} years..."
  wp eval-file "${DDEV_DOCROOT}/wp-content/plugins/${DDEV_PROJECT}/bin/seed-orders.php" "${SEED_ORDERS}" "${SEED_YEARS}" && touch .ddev/.seeded
fi

echo
echo "Coldstash dev site ready: ${DDEV_PRIMARY_URL}/wp-admin/ (${ADMIN_USER} / ${ADMIN_PASS})"
echo "  WooCommerce -> Coldstash      the dashboard"
echo "  ddev oa preview                   what would be archived"
echo "  ddev oa run                       archive it"
echo "  ddev oa restore --all             put everything back"
echo "  ddev seed-orders 1000 6           add more test orders"
