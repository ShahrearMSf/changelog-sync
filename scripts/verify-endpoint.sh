#!/usr/bin/env bash
# Prove the endpoint is reachable and the secret matches WITHOUT creating content.
# Usage: verify-endpoint.sh https://site.com /path/to/secret-file
#   1) unsigned request   → expect 401 (endpoint reachable, auth enforced)
#   2) signed, empty body → expect 422 (signature accepted = secrets match; nothing saved)
set -uo pipefail
SITE="${1%/}"; SECRET_FILE="$2"
E="${CHANGELOG_ENDPOINT:-$SITE/wp-json/changelog-sync/v1/entry}"
code() { curl -s -o /tmp/cls.$$ -w '%{http_code}' "$@"; }
C1=$(code -X POST "$E" -H 'Content-Type: application/json' -d '{}')
echo "unsigned: $C1 $(head -c 160 /tmp/cls.$$)"
TS=$(date +%s); B='{"product":"verify","version":"0","date":"invalid","items":[]}'
SIG=$(printf '%s' "$TS.$B" | openssl dgst -sha256 -hmac "$(cat "$SECRET_FILE")" -hex | awk '{print $NF}')
C2=$(code -X POST "$E" -H 'Content-Type: application/json' -H "X-Changelog-Timestamp: $TS" -H "X-Changelog-Signature: $SIG" -d "$B")
echo "signed:   $C2 $(head -c 160 /tmp/cls.$$)"; rm -f /tmp/cls.$$
if [ "$C1" = 401 ] && [ "$C2" = 422 ]; then echo "OK — reachable, auth enforced, secret matches"; exit 0; fi
case "$C1$C2" in
  *503*) echo "FAIL — secret not configured on the site";;
  401401) echo "FAIL — secret mismatch (or headers stripped by a proxy)";;
  *403*|*000*) echo "FAIL — blocked (WAF/Cloudflare/security plugin) or unreachable";;
  *404*) echo "FAIL — plugin inactive or REST route unavailable";;
  *) echo "FAIL — unexpected response";;
esac; exit 1
