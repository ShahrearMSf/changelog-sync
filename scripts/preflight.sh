#!/usr/bin/env bash
# Check a product repo BEFORE its first release. Read-only: nothing is written or sent.
# Usage: preflight.sh <repo-dir> [release-tag] [readme-path-inside-repo]
#   preflight.sh ~/code/notificationx
#   preflight.sh ~/code/notificationx-pro v3.3.0 README.txt
# Exit 0 = ready (warnings allowed), 1 = something will fail.
set -euo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"
REPO="${1:?usage: preflight.sh <repo-dir> [release-tag] [readme-path]}"
TAG="${2:-}"; README="${3:-${CHANGELOG_README:-readme.txt}}"
cd "$REPO"
PARSER="$HERE/assets/github/.github/scripts/parse-changelog.js" TAG="$TAG" README="$README" node <<'JS'
const fs = require('fs');
const { parseReadme, resolveReadme } = require(process.env.PARSER);
let errors = 0, warns = 0;
const ok = (m) => console.log('  ✓ ' + m);
const warn = (m) => { warns++; console.log('  ! ' + m); };
const fail = (m) => { errors++; console.log('  ✗ ' + m); };
const cmp = (a, b) => {
  const x = a.match(/\d+/g) || [], y = b.match(/\d+/g) || [];
  for (let i = 0; i < Math.max(x.length, y.length); i++) {
    const d = (+x[i] || 0) - (+y[i] || 0);
    if (d) return d;
  }
  return 0;
};

console.log('Workflow files');
for (const f of ['.github/scripts/parse-changelog.js', '.github/scripts/send-changelog.js']) {
  fs.existsSync(f) ? ok(f) : warn(`${f} missing (copy from assets/github/)`);
}
const WF = '.github/workflows';
const wfFiles = (() => { try { return fs.readdirSync(WF).filter((n) => /\.ya?ml$/.test(n)); } catch { return []; } })();
const wfText = (n) => fs.readFileSync(`${WF}/${n}`, 'utf8');
const wfName = (txt) => { const m = txt.match(/^name:\s*(.+?)\s*$/m); return m ? m[1].replace(/^['"]|['"]$/g, '') : null; };
const plain = wfFiles.includes('publish-changelog.yml');
const after = wfFiles.includes('publish-changelog-after-deploy.yml');
const deploys = wfFiles.filter((n) => !/^publish-changelog/.test(n) && /action-wordpress-plugin-deploy|svn|deploy/i.test(wfText(n)) && /^\s*release:/m.test(wfText(n)));
if (plain && after) fail('both publish-changelog.yml and publish-changelog-after-deploy.yml are installed — keep only one (entries would be sent twice)');
else if (!plain && !after) warn('no changelog workflow installed (copy one from assets/github/.github/workflows/)');
if (after) {
  const m = wfText('publish-changelog-after-deploy.yml').match(/workflows:\s*\[\s*["']([^"']+)["']/);
  const want = m ? m[1] : null;
  const hit = wfFiles.find((n) => n !== 'publish-changelog-after-deploy.yml' && wfName(wfText(n)) === want);
  if (!want) fail('publish-changelog-after-deploy.yml: could not read workflows: ["…"]');
  else if (!hit) fail(`after-deploy waits for a workflow named "${want}", but none exists (names: ${wfFiles.map((n) => wfName(wfText(n))).filter(Boolean).join(', ') || 'none'})`);
  else if (!/^\s*release:/m.test(wfText(hit))) warn(`"${want}" (${hit}) is not triggered by release — the changelog only runs after release-triggered deploys`);
  else ok(`publish-changelog-after-deploy.yml → runs after "${want}" (${hit}) succeeds`);
  console.log('  i it must be on the default branch to trigger (GitHub runs workflow_run from there)');
} else if (plain) {
  ok('publish-changelog.yml (runs on release published)');
  if (deploys.length) console.log(`  i deploy workflow found (${deploys.join(', ')}): consider publish-changelog-after-deploy.yml so the changelog only publishes after a successful deploy`);
}

console.log('Readme');
const wanted = process.env.README;
// Case-sensitive exists, like the Linux runner.
const path = resolveReadme(wanted);
const exact = path === wanted;
if (!path) { fail(`${wanted} not found in any letter case`); report(); }
exact ? ok(`found ${path}`) : ok(`found ${path} (as "${wanted}"; letter case differs — handled automatically)`);
const src = fs.readFileSync(path, 'utf8');

let entries = [];
try { entries = parseReadme(src); } catch (e) { fail(e.message); report(); }
entries.length ? ok(`${entries.length} changelog entries`) : fail('no entries under == Changelog ==');
if (!entries.length) report();

console.log('Entries');
const top = entries[0];
if (top.dated) ok(`top entry ${top.version} dated ${top.date}`);
else if (top.date_raw) warn(`top entry ${top.version} has an unparsable date ("${top.date_raw}") — the release date will be used`);
else ok(`top entry ${top.version} (no date in readme — the release date will be used)`);
top.items.length ? ok(`top entry has ${top.items.length} items`) : fail(`top entry ${top.version} has no items`);

const bad = entries.filter((e) => e.date_raw && !e.dated);
bad.length ? warn(`unparsable dates: ${bad.map((e) => `${e.version} "${e.date_raw}"`).join(', ')}`) : ok('all dates parse');
const undated = entries.filter((e) => !e.date_raw);
if (undated.length) console.log(`  i no date in readme on: ${undated.map((e) => e.version).join(', ')} — the Action uses each version's GitHub Release date; for versions that never had a release set CHANGELOG_EXTRA_DATES, e.g. {"${undated[undated.length - 1].version}":"YYYY-MM-DD"}`);
const empty = entries.filter((e) => !e.items.length);
if (empty.length) warn(`no items on: ${empty.map((e) => e.version).join(', ')} (skipped by the site)`);

const seen = new Set(), dups = [];
for (const e of entries) { if (seen.has(e.version)) dups.push(e.version); seen.add(e.version); }
dups.length ? fail(`duplicate versions: ${dups.join(', ')}`) : ok('no duplicate versions');

const outOfOrder = [];
for (let i = 1; i < entries.length; i++) {
  if (cmp(entries[i - 1].version, entries[i].version) < 0) outOfOrder.push(`${entries[i - 1].version} above ${entries[i].version}`);
  else if (entries[i - 1].dated && entries[i].dated && entries[i - 1].date < entries[i].date) outOfOrder.push(`${entries[i - 1].version} dated before ${entries[i].version}`);
}
outOfOrder.length ? warn(`not newest-first (the site still sorts correctly, but check dates): ${outOfOrder.slice(0, 5).join('; ')}`) : ok('newest-first order, dates consistent');

const ambiguous = entries.filter((e) => /^(\d{1,2})[-/.](\d{1,2})[-/.]\d{4}$/.test(e.date_raw) && e.date_raw.split(/[-/.]/).slice(0, 2).every((n) => +n <= 12));
if (ambiguous.length && !process.env.CHANGELOG_DATE_ORDER) {
  console.log(`  i ${ambiguous.length} dates like ${ambiguous[0].date_raw} read as day/month (default). US style? set CHANGELOG_DATE_ORDER=mdy`);
}

console.log('Release');
const stable = (src.match(/^\s*Stable tag:\s*(\S+)/im) || [])[1];
if (stable) stable === top.version ? ok(`Stable tag ${stable} = top entry`) : warn(`Stable tag ${stable} ≠ top entry ${top.version}`);
const tag = (process.env.TAG || '').replace(/^v/i, '');
if (tag) {
  const e = entries.find((x) => x.version === tag);
  e ? ok(`tag ${process.env.TAG} → entry ${e.version} (${e.items.length} items)`) : fail(`tag ${process.env.TAG}: version ${tag} not in the changelog — add it before tagging`);
} else {
  console.log(`  i release tag should be v${top.version} or ${top.version}`);
}
report();

function report() {
  console.log(`\n${errors ? 'NOT READY' : 'READY'} — ${errors} error(s), ${warns} warning(s)`);
  process.exit(errors ? 1 : 0);
}
JS
