#!/usr/bin/env bash
# Provisioner run by the wpcli service in docker-compose.yml. Idempotent: safe to
# run again. Installs WooCommerce, imports the sample products, and activates the
# Mibizum Search plugin.
set -euo pipefail

cd /var/www/html
SITE_URL="${SITE_URL:-http://localhost:8088}"

echo "Waiting for the database and WordPress files..."
until wp core is-installed >/dev/null 2>&1 || wp db check >/dev/null 2>&1; do
	if [ -f wp-settings.php ] && wp db check >/dev/null 2>&1; then break; fi
	sleep 3
done

if ! wp core is-installed >/dev/null 2>&1; then
	echo "Installing WordPress..."
	wp core install \
		--url="$SITE_URL" \
		--title="Mibizum WC Test" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.com \
		--skip-email
fi

echo "Installing WooCommerce..."
wp plugin is-installed woocommerce >/dev/null 2>&1 || wp plugin install woocommerce
wp plugin activate woocommerce

echo "Importing sample products..."
wp plugin is-installed wordpress-importer >/dev/null 2>&1 || wp plugin install wordpress-importer
wp plugin activate wordpress-importer
SAMPLE="wp-content/plugins/woocommerce/sample-data/sample_products.xml"
if [ -f "$SAMPLE" ]; then
	wp import "$SAMPLE" --authors=create || true
fi

echo "Activating Mibizum Search..."
wp plugin activate mibizum-search

echo "Done. Visit ${SITE_URL}/wp-admin (admin / admin)."
echo "Configure under WooCommerce > Settings > Mibizum, or follow the setup wizard."
