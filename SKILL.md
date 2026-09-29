---
name: changelog-sync
description: Set up, roll out, verify or troubleshoot "Changelog Sync" — the GitHub Action + WordPress plugin that publishes a product's readme.txt changelog entry to a WordPress website when a GitHub Release is published, listed newest-first via the [changelog] shortcode. Use when asked to connect a product repo to a website changelog, onboard a new product/site, backfill old versions, check why a changelog entry didn't appear, or explain what's needed for a real site.
---

# Changelog Sync

GitHub Release published → Action reads that version's block from `readme.txt` → HMAC-signed POST
→ WordPress stores one `changelog_entry` post per (product, version) → `[changelog]` lists them
newest first (release date, then version number).

Everything in `assets/` is the **tested** code (E2E verified 2026-09-28: private repo →
remote WP host, release/backfill/pre-release-skip/re-send/draft/lock/trash/XSS). Copy it as-is;
change behaviour in `assets/` first, then re-test.

```
assets/github/.github/workflows/publish-changelog.yml   → product repo
assets/github/.github/scripts/parse-changelog.js        → product repo
assets/github/.github/scripts/send-changelog.js         → product repo
assets/wordpress/changelog-sync.php                     → website (plugin or mu-plugin)
scripts/package-plugin.sh      build changelog-sync.zip for wp-admin upload
scripts/verify-endpoint.sh     prove endpoint + secret without creating content
scripts/preview-entry.sh       show the exact payload a release would send (no network)
scripts/preflight.sh           check a product repo before its first release (read-only)
references/rollout-checklist.md   what to ask for, who does what, go-live steps
references/behaviour.md           every case the site handles + payload format
references/troubleshooting.md     symptom → cause → fix
```

## Hard rules

- **Never write to a product repo, create releases, or set repo secrets without explicit
  per-task permission.** Company/product repos in particular: prepare the files, the user (or repo
  owner) commits. Never push to `master`/`main`.
- **Secrets never appear** in chat, gists, PRs, commits, logs or screenshots. Pipe them
  (`gh secret set NAME < file`), keep temp files `umask 077` in the scratchpad, delete after.
  Refer to a secret by its last 4 chars only.
- Never store site passwords in memory; ask again next time.
- Test on a **test** site/repo first. Auto mode blocks public tunnels to Local sites — use a
  public test WP site for the GitHub leg, not a tunnel.

## Process

### 1. Collect requirements
Use `references/rollout-checklist.md` → "What to ask for". Minimum: site URL + admin access
(or someone who can install a plugin), list of product repos, where the page lives, publish vs
draft, and whether a WAF/cache sits in front.

### 2. Preflight + preview before touching anything
```bash
scripts/preflight.sh /path/to/product-repo            # READY / NOT READY + reasons
scripts/preflight.sh /path/to/product-repo v3.3.1     # also checks the tag has an entry
```
It checks: workflow files present, readme found (any letter case), dates parse, no duplicate
versions, newest-first order, `Stable tag` = top entry, tag ↔ entry. Fix every ✗ before releasing.

```bash
scripts/preview-entry.sh /path/to/product/readme.txt notificationx "NotificationX"          # top entry
scripts/preview-entry.sh /path/to/product/readme.txt notificationx "NotificationX" --all    # backfill
```
Check: every version has a real date (no `::warning::`), items look right, `date` order is
sane. Date `01/02/2026` is ambiguous — default `dmy`; set `CHANGELOG_DATE_ORDER=mdy` if the
product writes US dates.

### 3. Website (once per site)
1. `scripts/package-plugin.sh` → upload `changelog-sync.zip` (Plugins → Add New → Upload) or
   drop `changelog-sync.php` into `wp-content/mu-plugins/`.
2. Secret: `define( 'CHANGELOG_SYNC_SECRET', '<64 hex>' );` in `wp-config.php` **(preferred)**,
   or wp-admin → Changelog → Settings → Save (blank = generate; shown once).
3. Create the page: `[changelog]` (all products) or
   `[changelog product="notificationx" per_page="10"]`. Multiple: `product="notificationx,notificationx-pro"`.
   Gutenberg: Shortcode block · Classic: paste · Elementor: **Shortcode** widget (stays fresh even with
   Element Caching on — leave the widget's Advanced → Cache Settings at default) · PHP: `do_shortcode()`.
4. WAF / security plugin / Cloudflare: allow `POST /wp-json/changelog-sync/v1/entry`
   (GitHub runner IPs are not fixed — allow by path, the HMAC is the auth).
5. `scripts/verify-endpoint.sh https://site.com <secret-file>` → must print `401` then `422`.

### 4. Each product repo (with permission)
1. Copy `assets/github/.github/**` into the repo (default branch, so manual runs appear).
2. Secrets: `CHANGELOG_ENDPOINT` (from Changelog → Settings), `CHANGELOG_SECRET`.
   Many repos → one **org-level** secret pair scoped to those repos.
3. Variables: `CHANGELOG_PRODUCT` (slug — Free and Pro need different slugs),
   `CHANGELOG_PRODUCT_NAME`; optional `CHANGELOG_README` (only if the readme is not at the repo root — letter case
   `readme.txt` / `README.txt` is matched automatically),
   `CHANGELOG_STATUS=draft`, `CHANGELOG_DATE_ORDER=mdy`, `CHANGELOG_EXTRA_DATES` (JSON, for
   undated readme versions that never had a GitHub Release — e.g. the 1.0.0 that went straight to WP.org;
   WP.org's `added` date from `api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=<slug>`).
4. Backfill: Actions → Publish changelog → Run workflow → tick **all**. Safe to repeat.

### 5. Go-live verification
- Actions run is green and logs `✓ <product> <version>: created (post N, publish)`.
- Logged-out page (`?nocache=1`) shows the version at the top with the right date.
- Next real release: tag must equal the readme version (`v3.3.2` ↔ `= 3.3.2 - dd/mm/yyyy =`).
  If not found, it falls back to the Release notes and warns — fix the readme, re-run.

### 6. Troubleshooting
`references/troubleshooting.md`. First look: the run's **Summary** tab shows the exact JSON
payload; the send step prints the site's response (`action`, post id, status).

## Release-day flow (for the team)
1. Update `readme.txt` changelog (as today) → merge → tag + publish the GitHub Release.
2. Entry appears on the site within ~1 min. Nothing else to do.
3. Fix wording: edit `readme.txt` + run the workflow for that version, **or** edit the entry in
   wp-admin and tick "Keep my edits" so GitHub won't overwrite it.
