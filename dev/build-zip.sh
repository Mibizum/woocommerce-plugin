#!/usr/bin/env bash
# Build the distributable plugin .zip for WordPress.org / manual install.
#
# The .zip contains only the runtime files, under a top level mibizum-search/
# folder so it unzips into the correct plugin directory. Dev tooling, the wiki,
# and the WordPress.org listing assets (assets/) are NOT shipped in the plugin
# package (assets/ go in the SVN /assets directory, not /trunk).
#
#   ./build-zip.sh   ->  dist/mibizum-search.zip
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="mibizum-search"
DIST="$PLUGIN_DIR/dist"
STAGE="$DIST/$SLUG"

rm -rf "$DIST"
mkdir -p "$STAGE/languages"

cp "$PLUGIN_DIR/$SLUG.php" "$PLUGIN_DIR/uninstall.php" "$PLUGIN_DIR/readme.txt" "$PLUGIN_DIR/LICENSE" "$STAGE/"
cp -R "$PLUGIN_DIR/src" "$STAGE/"
cp -R "$PLUGIN_DIR/templates" "$STAGE/"
# Ship compiled translations + template + sources (small, helps translators).
cp "$PLUGIN_DIR/languages/"*.mo "$STAGE/languages/" 2>/dev/null || true
cp "$PLUGIN_DIR/languages/"*.po "$STAGE/languages/" 2>/dev/null || true
cp "$PLUGIN_DIR/languages/"*.pot "$STAGE/languages/" 2>/dev/null || true

( cd "$DIST" && zip -rq "$SLUG.zip" "$SLUG" -x "*.DS_Store" )
echo "Built: $DIST/$SLUG.zip"
( cd "$DIST" && unzip -l "$SLUG.zip" | tail -n +2 | head -n -2 | awk '{print $4}' | sed '/^$/d' )
