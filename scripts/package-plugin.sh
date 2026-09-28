#!/usr/bin/env bash
# Build changelog-sync.zip (for Plugins → Add New → Upload). Usage: package-plugin.sh [out-dir]
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$PWD}"; TMP="$(mktemp -d)"
mkdir -p "$TMP/changelog-sync" && cp "$HERE/assets/wordpress/changelog-sync.php" "$TMP/changelog-sync/"
php -l "$TMP/changelog-sync/changelog-sync.php" >/dev/null
(cd "$TMP" && zip -qr "$OUT/changelog-sync.zip" changelog-sync)
rm -rf "$TMP"; echo "$OUT/changelog-sync.zip"
