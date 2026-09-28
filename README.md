# Changelog Sync

Publish your WordPress plugin's changelog to your website automatically. When a GitHub Release
is published, the matching entry from `readme.txt` appears on the site, newest first. Nobody
has to copy and paste it.

It ships as a **Claude Code skill** (`/changelog-sync`) that holds the tested code, the rollout
process, and the checks. You can also use the code without Claude.

```
GitHub Release published (pre-releases skipped)
   │  Action reads "= 3.3.1 - 14/09/2026 =" block from readme.txt
   ▼
POST /wp-json/changelog-sync/v1/entry   (HMAC-SHA256 signed, 5-min replay window)
   │  upsert by (product, version)
   ▼
WordPress: one "changelog_entry" post per version
   │
   ▼
[changelog] shortcode → newest first (release date, then version number)
```

## Features

- **No extra writing.** It reads the same `readme.txt` changelog you already keep for WordPress.org. If a version is missing there, it falls back to the GitHub Release notes.
- **Newest first, always.** Order comes from the release date plus the version number, so loading old versions later never breaks it.
- **Safe to re-run.** Sending the same version again reports `unchanged`, and edited text becomes `updated`. It never creates duplicates.
- **Live or draft.** Choose per repo or per run. A re-sent draft never takes a live entry offline.
- **Editors stay in control.** Tick "Keep my edits" and GitHub won't overwrite that entry. Trashed entries are never re-created.
- **Load old versions in one run.** A manual run with `all` sends every version in `readme.txt`.
- **Secure.** Requests are HMAC-signed with a timestamp. Text is escaped when the page renders, and the endpoint is off until a secret is set.
- **Cache-aware.** It purges WP Rocket, LiteSpeed, W3TC, WP Super Cache and Breeze after each change.
- **No dependencies.** The WordPress side is one PHP file (PHP 7.4+). The GitHub side is Node 20 with no npm install.

## Repository layout

```
SKILL.md                         Claude Code skill: rules + step-by-step process
assets/
  github/.github/workflows/publish-changelog.yml   → copy into each product repo
  github/.github/scripts/parse-changelog.js        → copy into each product repo
  github/.github/scripts/send-changelog.js         → copy into each product repo
  wordpress/changelog-sync.php                     → install on the website
references/
  rollout-checklist.md           what to ask for, who does what, go-live, rollback
  behaviour.md                   every case handled, payload format, shortcode options
  troubleshooting.md             symptom → cause → fix
scripts/
  package-plugin.sh              build changelog-sync.zip for wp-admin upload
  verify-endpoint.sh             check site + secret without creating content (401 then 422 = OK)
  preview-entry.sh               show what a release would send (no network)
```

## Install the skill

```bash
# per user
git clone https://github.com/ShahrearMSf/changelog-sync ~/.claude/skills/changelog-sync
# or per project
git clone https://github.com/ShahrearMSf/changelog-sync <project>/.claude/skills/changelog-sync
```

Then ask Claude Code something like "set up changelog sync for our plugin", or run `/changelog-sync`.

## Quick start (without Claude)

**1. Website (once per site)**
1. `scripts/package-plugin.sh`, then upload `changelog-sync.zip` under Plugins → Add New → Upload. You can also drop the PHP file into `mu-plugins/`.
2. Set the secret. The preferred way is `define( 'CHANGELOG_SYNC_SECRET', '<openssl rand -hex 32>' );` in `wp-config.php`. The alternative is wp-admin → Changelog → Settings.
3. Add `[changelog]` or `[changelog product="my-plugin" per_page="10"]` to a page.
4. If a firewall or security plugin sits in front, allow `POST /wp-json/changelog-sync/v1/entry`.
5. `scripts/verify-endpoint.sh https://example.com secret.txt` should report `OK`.

**2. Each product repo**
1. Copy `assets/github/.github/` into the repo's default branch.
2. Add the secrets `CHANGELOG_ENDPOINT` (shown in Changelog → Settings) and `CHANGELOG_SECRET`.
3. Add the variables `CHANGELOG_PRODUCT` (slug) and `CHANGELOG_PRODUCT_NAME` (label). Optional: `CHANGELOG_README`, `CHANGELOG_STATUS` (`publish`|`draft`), `CHANGELOG_DATE_ORDER` (`dmy`|`mdy`).
4. Go to Actions → **Publish changelog** → Run workflow, and tick **all** to load old versions.

**3. Every release after that:** update `readme.txt` as usual and publish the GitHub Release. The entry is live within about a minute.

## Supported changelog format

```
== Changelog ==

= 3.3.1 - 14/09/2026 =
- Added: Something new
* Fixed: Bullets can be -, *, • or none
Improved: `code` becomes <code>

= 3.3.0 - September 3, 2026 =
...
```

Supported date formats are `dd/mm/yyyy` (the default), `mm/dd/yyyy`, `yyyy-mm-dd` and `14 September 2026` / `September 14, 2026`. See `references/behaviour.md` for the full rules.

## Tested

End to end, from a private GitHub repo to a remote WordPress host. The test covered release publish, pre-release skip, manual backfill, re-send, draft, "Keep my edits", trash and HTML/XSS text. It also parsed real-world changelogs with 34 and 83 versions without error.
