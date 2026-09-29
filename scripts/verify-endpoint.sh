#!/usr/bin/env bash
# Prove the endpoint is reachable and the secret matches WITHOUT creating content.
#
# Usage:
#   verify-endpoint.sh https://site.com              # asks for the secret (typing is hidden)
#   verify-endpoint.sh https://site.com secret-file  # or reads it from a file (e.g. in automation)
#   CHANGELOG_SECRET=… verify-endpoint.sh https://site.com   # or from the environment
#
#   1) unsigned request   → expect 401 (endpoint reachable, auth enforced)
#   2) signed, empty body → expect 422 (signature accepted = secrets match; nothing saved)
#
# The secret never appears on a command line (not visible in `ps`), in shell history or in a file.
set -uo pipefail
SITE="${1:?usage: verify-endpoint.sh https://site.com [secret-file]}"; SITE="${SITE%/}"
E="${CHANGELOG_ENDPOINT:-$SITE/wp-json/changelog-sync/v1/entry}"

if [ -n "${2:-}" ]; then
  CHANGELOG_SECRET="$(cat "$2")"
elif [ -z "${CHANGELOG_SECRET:-}" ]; then
  if [ -t 0 ]; then
    printf 'Paste the secret (typing is hidden): ' >&2
    IFS= read -rs CHANGELOG_SECRET; echo >&2
  else
    IFS= read -r CHANGELOG_SECRET
  fi
fi
[ -n "${CHANGELOG_SECRET:-}" ] || { echo "No secret given."; exit 2; }
export CHANGELOG_SECRET

OUT="$(mktemp)"; trap 'rm -f "$OUT"' EXIT
code() { curl -s -o "$OUT" -w '%{http_code}' "$@"; }

C1=$(code -X POST "$E" -H 'Content-Type: application/json' -d '{}')
echo "unsigned: $C1 $(head -c 160 "$OUT")"

TS=$(date +%s); B='{"product":"verify","version":"0","date":"invalid","items":[]}'
# HMAC in Node, reading the secret from the environment (never from argv).
SIG=$(TS="$TS" B="$B" node -e 'process.stdout.write(require("crypto").createHmac("sha256", process.env.CHANGELOG_SECRET).update(process.env.TS + "." + process.env.B).digest("hex"))')
C2=$(code -X POST "$E" -H 'Content-Type: application/json' -H "X-Changelog-Timestamp: $TS" -H "X-Changelog-Signature: $SIG" -d "$B")
echo "signed:   $C2 $(head -c 160 "$OUT")"

if [ "$C1" = 401 ] && [ "$C2" = 422 ]; then echo "OK — reachable, auth enforced, secret matches"; exit 0; fi
case "$C1$C2" in
  *503*) echo "FAIL — secret not configured on the site";;
  401401) echo "FAIL — secret mismatch (or headers stripped by a proxy)";;
  *403*|*000*) echo "FAIL — blocked (WAF/Cloudflare/security plugin) or unreachable";;
  *404*) echo "FAIL — plugin inactive or REST route unavailable";;
  *) echo "FAIL — unexpected response";;
esac; exit 1
