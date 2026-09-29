#!/usr/bin/env bash
# Show the payload a release would send (no network). Read-only on the repo.
# Usage: preview-entry.sh <readme.txt> <product-slug> [product-name] [--all | --version X]
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
README="${1:?usage: preview-entry.sh <readme.txt> <product-slug> [product-name] [--all | --version X]}"
PRODUCT="${2:?product slug required}"; shift 2
# Product name is optional: take the next argument only if it is not a flag.
NAME="$PRODUCT"
if [ $# -gt 0 ] && [ "${1#--}" = "$1" ]; then NAME="$1"; shift; fi
CHANGELOG_PRODUCT="$PRODUCT" CHANGELOG_PRODUCT_NAME="$NAME" GITHUB_EVENT_PATH= \
  node "$HERE/assets/github/.github/scripts/parse-changelog.js" --readme "$README" "$@"
