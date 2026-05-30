#!/usr/bin/env bash
# No-Docker local test site for Mibizum Search for WooCommerce.
#
# Builds an isolated WordPress on SQLite (no MySQL needed) using WP-CLI and the
# official sqlite-database-integration drop-in, installs WooCommerce, imports the
# sample products, symlinks this plugin, and (optionally) serves it with the PHP
# built in server.
#
# Requirements: php (>= 7.4) and wp-cli on PATH.
#
#   ./install-local.sh                 # build into ~/mibizum-wp-test on :8089
#   SANDBOX=/tmp/wp PORT=8090 ./install-local.sh
#   ./install-local.sh serve           # just (re)start the PHP server
#
set -euo pipefail

SANDBOX="${SANDBOX:-$HOME/mibizum-wp-test}"
PORT="${PORT:-8089}"
SITE_URL="http://localhost:${PORT}"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP="php -d memory_limit=1024M $(command -v wp)"

serve() {
	echo "Serving ${SITE_URL} (Ctrl+C to stop)"
	exec $WP server --host=localhost --port="$PORT" --path="$SANDBOX"
}

if [ "${1:-}" = "serve" ]; then
	serve
fi

echo "==> WordPress core into ${SANDBOX}"
mkdir -p "$SANDBOX"
$WP core download --path="$SANDBOX" --locale=en_US --force

echo "==> SQLite drop-in"
ZIP=/tmp/sqlite-di.zip
curl -sL -o "$ZIP" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
unzip -oq "$ZIP" -d "$SANDBOX/wp-content/plugins/"
cp "$SANDBOX/wp-content/plugins/sqlite-database-integration/db.copy" "$SANDBOX/wp-content/db.php"

echo "==> wp-config + install"
$WP config create --path="$SANDBOX" --dbname=mibizum_wc --dbuser=root --dbpass='' --skip-check --force
$WP core install --path="$SANDBOX" --url="$SITE_URL" --title="Mibizum WC Test" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email

echo "==> WooCommerce + sample data"
$WP plugin install woocommerce --activate --path="$SANDBOX"
$WP plugin install wordpress-importer --activate --path="$SANDBOX"
SAMPLE="$SANDBOX/wp-content/plugins/woocommerce/sample-data/sample_products.xml"
[ -f "$SAMPLE" ] && $WP import "$SAMPLE" --authors=create --path="$SANDBOX" || true

echo "==> Link + activate the plugin"
ln -sfn "$PLUGIN_DIR" "$SANDBOX/wp-content/plugins/mibizum-search"
$WP plugin activate mibizum-search --path="$SANDBOX"

echo ""
echo "Ready. Start the server with:  $0 serve"
echo "Admin: ${SITE_URL}/wp-admin  (admin / admin)"
echo "Configure under WooCommerce > Settings > Mibizum, or follow the wizard."
