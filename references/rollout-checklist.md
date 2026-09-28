# Rollout checklist — real site

## What to ask for

| # | Need | From | Why |
|---|---|---|---|
| 1 | Website URL + where the changelog page lives (new page / existing page / per-product pages) | Marketing / web owner | Shortcode placement |
| 2 | **One** of: admin login, SFTP/hosting access, or a person who can install a plugin + edit `wp-config.php` | Web owner / DevOps | Install plugin, set secret |
| 3 | Is there Cloudflare / a WAF / security plugin (Wordfence, BetterShield…) in front? | DevOps | Must allow `POST /wp-json/changelog-sync/v1/entry` |
| 4 | Page cache in use (WP Rocket, LiteSpeed, W3TC, Super Cache, host cache, Cloudflare APO)? | DevOps | Plugin purges the common ones; host/CDN cache may need a purge rule |
| 5 | List of product repos (Free + Pro each) and the path of `readme.txt` in each | Dev leads | Workflow + `CHANGELOG_README` |
| 6 | Admin on each repo **or** org owner to add secrets/variables (org-level secret preferred) | Repo/org owner | Secrets can't be set by write-only members |
| 7 | Product slugs + display names (e.g. `notificationx` / "NotificationX", `notificationx-pro` / "NotificationX Pro") | Marketing | Grouping + labels |
| 8 | Go live immediately or draft for review? | Marketing | `CHANGELOG_STATUS` |
| 9 | How far back to backfill (all versions / last N) | Marketing | Backfill scope |
| 10 | Design: fit the site theme? (plugin ships minimal CSS, classes `cl-*`) | Design | Optional restyle |

## Who does what

| Step | Owner |
|---|---|
| Install plugin, set secret (wp-config preferred), create page, allow endpoint in WAF | Web owner / DevOps |
| Add workflow files to each repo (PR into default branch) | Dev (per repo policy) |
| Add org/repo secrets + variables | Org/repo admin |
| Backfill run, first-release check | QA |
| Changelog wording (unchanged — still `readme.txt`) | Devs, as today |

## Go-live steps

1. Test site first: same plugin on a public test WP site + a private test repo; run a release, a pre-release and a backfill.
2. Run `scripts/preflight.sh` on each product repo — all must say `READY`.
3. Install plugin on production, set secret, run `scripts/verify-endpoint.sh` (expect 401 then 422).
4. Create the page **as draft/private** first.
5. Merge workflow into one product repo, set secrets/vars, run backfill with status `draft`.
6. Review drafts in wp-admin (Changelog menu) → bulk-publish → publish the page.
7. Roll out to the remaining repos; backfill each.
8. Watch the next real release end-to-end.

## Rollback
- Stop sending: delete/rename the repo secret or disable the workflow.
- Stop receiving: remove the secret on the site (endpoint returns 503) or deactivate the plugin.
- Entries are ordinary posts (Changelog menu) — trash to hide; trashed entries are never re-created.
