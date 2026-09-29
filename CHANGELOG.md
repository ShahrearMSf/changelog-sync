# Changelog

## 1.2.0 - 2026-09-29
- Added: readme headers without a date (`= 1.3.0 =`) get the date of that version's GitHub Release; the workflow collects release dates automatically
- Added: `CHANGELOG_EXTRA_DATES` variable for undated versions that never had a GitHub Release
- Fixed: `Fixed - text` / `Improvement - text` (dash separator) now gets its label, like `Fixed: text`
- Improved: preflight treats undated readmes as normal and explains where dates come from

## 1.1.1 - 2026-09-28
- Docs: README "Adding the changelog to a page" covers shortcode options, where to place it in Gutenberg, Classic, Elementor, other builders and PHP templates, Elementor caching, styling classes and manual edits

## 1.1.0 - 2026-09-28
- Added: `scripts/preflight.sh` checks a product repo before its first release (readme found, dates, duplicates, order, Stable tag, tag ↔ entry)
- Fixed: `readme.txt` is now found in any letter case (`README.txt` in Pro repos failed on Linux runners)
- Improved: GitHub Actions pinned to exact commits (checkout and setup-node v4.4.0)
- Improved: Settings screen recommends keeping the secret in `wp-config.php`; plugin 1.0.1 declares its license
- Added: GPL-2.0-or-later license

## 1.0.0 - 2026-09-28
- Initial release: GitHub Action + WordPress receiver plugin, helper scripts, references and Claude Code skill
