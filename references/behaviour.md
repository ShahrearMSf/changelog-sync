# Behaviour + payload reference

## Source & trigger
- Trigger: `release: published` (pre-releases skipped) + `workflow_dispatch` (inputs: `version`, `status` default/publish/draft, `all` backfill).
- Source: `readme.txt` `== Changelog ==` section, stops at the next `== … ==` heading.
- File lookup: `CHANGELOG_README` (default `readme.txt`) matched in any letter case, so `README.txt` / `Readme.txt` work on the case-sensitive Linux runner.
- Version chosen: dispatch input → release tag (`v` stripped) → top entry.
- Version missing from readme → GitHub Release body used (headings + "Full Changelog" line dropped), with a warning.

## Parser accepts
- Headers: `= 3.3.1 - 14/09/2026 =`, `= v2.0.0 – September 5, 2026 =`, `= 1.9.9 =` (dash/en-dash/em-dash/pipe/colon).
- Date priority: date in the header → the version's GitHub Release date (collected by the workflow) → `CHANGELOG_EXTRA_DATES` → the newer entry's date. A single release run with no dates at all uses that release's publish date. So readmes that never write dates (`= 1.3.0 =`) still get real dates.
- Dates: `dd/mm/yyyy` (default), `mm/dd/yyyy` (`CHANGELOG_DATE_ORDER=mdy`, auto when unambiguous), `yyyy-mm-dd`, `14 September 2026`, `September 14, 2026`, dots/dashes as separators.
- Items: `*`, `-`, `•`, `+` bullets or plain lines; indented lines continue the previous item; `` `code` `` → `<code>`.
- Types (prefix `Type:` or `Type - `, dash needs spaces so `Fixed-width` stays text): Added/New, Fixed, Improved/Improvement, Updated/Update, Changed, Removed, Deprecated, Security, Tweak, Revamped, Compatibility, Dev. Anything else → untyped item.

## Payload (POST /wp-json/changelog-sync/v1/entry)
```json
{ "product": "notificationx", "product_name": "NotificationX", "status": "publish",
  "version": "3.3.1", "date": "2026-09-14",
  "items": [ { "type": "Improved", "text": "…" }, { "type": "", "text": "…" } ],
  "source": "readme.txt", "source_url": "https://github.com/…/releases/tag/v3.3.1" }
```
Headers: `X-Changelog-Timestamp` (unix), `X-Changelog-Signature` = hex HMAC-SHA256 of `"<timestamp>.<raw body>"`. ±300 s window.

## Site responses
| Situation | HTTP | `action` |
|---|---|---|
| No/old timestamp | 401 `changelog_stale` | — |
| Bad signature | 401 `changelog_bad_signature` | — |
| Secret not set (<32 chars) | 503 `changelog_disabled` | — |
| Bad product/version/date or no items | 422 | — |
| New version | 201 | `created` |
| Same content re-sent | 200 | `unchanged` (no cache purge) |
| Content changed | 200 | `updated` |
| Re-sent as draft after it went live | 200 | `updated`, **stays publish** |
| "Keep my edits" ticked | 200 | `skipped_locked` |
| Entry in trash | 200 | `skipped_trashed` |

## Storage
- CPT `changelog_entry` (not public, has admin UI + revisions), taxonomy `changelog_product`.
- Meta: `_cl_key` (`product|version`, upsert key), `_cl_version`, `_cl_date`, `_cl_sort` (`YYYY-MM-DD|00003.00003.00001.00000`), `_cl_hash`, `_cl_source`, `_cl_lock`.
- Option `changelog_sync_secret` (autoload off) unless `CHANGELOG_SYNC_SECRET` constant is defined.
- Text is stored verbatim (control chars stripped) and escaped on render — `<br>` shows as text.
- Future-dated entries are stored with today's post_date (no scheduled posts); sort still uses the real date.

## Shortcode
`[changelog product="slug[,slug]" per_page="10"]` — published only, `?cl-page=N` pagination, anchors `#v3-3-1`, product label shown when more than one product. CSS classes: `cl-list cl-entry cl-head cl-product cl-version cl-date cl-body cl-items cl-item cl-type-<type> cl-tag cl-pages`.

## Manual entries
wp-admin → Changelog → Add: title, list in content, **Release** box (version + date required), Products box. Sorts with the rest.
