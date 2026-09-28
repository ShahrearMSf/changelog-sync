# Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `CHANGELOG_ENDPOINT and CHANGELOG_SECRET must be set` | Secrets missing, or org secret not granted to this repo | Add repo secrets / grant repo access to the org secret |
| Run shows **skipped** | Release is marked pre-release | Intended. Publish a full release or run manually |
| `Version X not found … and the release has no notes` | Tag ≠ readme version (e.g. tag `3.3.1-hotfix`, readme `3.3.1`) or readme not updated before tagging | Fix readme on the tagged commit, or run manually with the right `version` |
| `::warning:: … used the GitHub Release notes instead` | Same as above but release had notes | Entry was posted from notes; update readme + re-run to replace |
| `::warning::Could not parse date` | Unusual date format in header | Use `dd/mm/yyyy`; the entry falls back to release date / neighbour date |
| HTTP 401 `changelog_bad_signature` | Repo secret ≠ site secret (whitespace/newline when pasting) | Re-set both from one file: `gh secret set CHANGELOG_SECRET < file` |
| HTTP 401 `changelog_stale` | Proxy/WAF stripped custom headers, or server clock off by >5 min | Allow `X-Changelog-*` headers; fix NTP |
| HTTP 503 `changelog_disabled` | Secret not configured on the site | Changelog → Settings, or `wp-config.php` constant |
| HTTP 403 / HTML challenge page | Cloudflare / WAF / security plugin blocking GitHub runners | Allow `POST /wp-json/changelog-sync/v1/entry` by path |
| HTTP 404 `rest_no_route` | Plugin inactive, or REST API disabled / pretty permalinks off | Activate; test `https://site/?rest_route=/changelog-sync/v1/entry` and use that as endpoint |
| `skipped_locked` | Someone ticked "Keep my edits" | Untick in the entry's Release box, re-run |
| `skipped_trashed` | Entry is in trash | Restore it (or empty trash) and re-run |
| Entry created but not on page | Status draft, wrong `product` slug in shortcode, or page cache | Check Changelog list; slug matches `CHANGELOG_PRODUCT`; purge host/CDN cache |
| Wrong order | Wrong date in readme (dmy vs mdy) | Fix readme or set `CHANGELOG_DATE_ORDER`, re-run for that version |
| Duplicate-looking entries | Same version under two product slugs (e.g. repo renamed, default slug = repo name) | Set `CHANGELOG_PRODUCT` explicitly; trash the stray one |
| Manual "Run workflow" button missing | Workflow file not on the default branch | Merge to default branch |

## Quick diagnostics
```bash
gh run list -R OWNER/REPO --workflow publish-changelog.yml --limit 5
gh run view <id> -R OWNER/REPO --log | grep -E "✓|::warning|::error|error\]"
scripts/verify-endpoint.sh https://site.com /path/to/secret-file
```
