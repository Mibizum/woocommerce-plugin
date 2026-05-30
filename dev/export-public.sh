#!/usr/bin/env bash
# Prepare a clean export of the plugin for the public GitHub repository
# (github.com/Mibizum), with no monorepo history.
#
# This only STAGES the files. The actual git init / commit / push is done
# separately (publishing to the Mibizum org). The wiki/ folder is pushed to the
# repository's separate .wiki.git, and assets/ is uploaded to the WordPress.org
# SVN /assets directory (not the plugin trunk).
#
#   ./export-public.sh [target-dir]   (default: /tmp/mibizum-woocommerce-public)
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-/tmp/mibizum-woocommerce-public}"

rm -rf "$OUT"
mkdir -p "$OUT"
rsync -a \
  --exclude dist \
  --exclude node_modules \
  --exclude vendor \
  --exclude '.git' \
  --exclude '.DS_Store' \
  "$PLUGIN_DIR"/ "$OUT"/

echo "Clean export ready at: $OUT"
echo "Contents:"
( cd "$OUT" && find . -maxdepth 1 -mindepth 1 | sort )
echo
echo "Next (publishing, done with auto-mode OFF):"
echo "  cd $OUT && git init && git add -A && git commit -m 'feat: initial release'"
echo "  git remote add origin git@github.com:Mibizum/<repo>.git && git push -u origin main"
echo "  wiki/  -> push to the <repo>.wiki.git"
echo "  assets/ -> WordPress.org SVN /assets (not the plugin trunk)"
