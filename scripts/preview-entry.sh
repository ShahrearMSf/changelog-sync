#!/usr/bin/env bash
# Show the payload a release would send (no network). Read-only on the repo.
# Usage: preview-entry.sh <readme.txt> <product-slug> [product-name] [--all | --version X]
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
README="$1"; PRODUCT="$2"; NAME="${3:-$2}"; shift $(( $# >= 3 ? 3 : 2 ))
CHANGELOG_PRODUCT="$PRODUCT" CHANGELOG_PRODUCT_NAME="$NAME" GITHUB_EVENT_PATH= \
  node "$HERE/assets/github/.github/scripts/parse-changelog.js" --readme "$README" "$@"
