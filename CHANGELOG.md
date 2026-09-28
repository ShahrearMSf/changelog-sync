# Changelog

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
