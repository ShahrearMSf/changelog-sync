#!/usr/bin/env node
/**
 * Parse the "== Changelog ==" section of a WordPress readme.txt.
 *
 * Usage:
 *   node parse-changelog.js [--readme readme.txt] [--version 3.3.1] [--all]
 *
 * Version selection (single-entry mode):
 *   1. --version flag (manual workflow run)
 *   2. the release tag from GITHUB_EVENT_PATH (v3.3.1 -> 3.3.1)
 *   3. the top entry of the changelog
 * If the requested version is missing from readme.txt, the GitHub Release
 * body is used as a fallback source. Otherwise the script fails loudly.
 *
 * Env: CHANGELOG_PRODUCT, CHANGELOG_PRODUCT_NAME, CHANGELOG_STATUS,
 *      CHANGELOG_DATE_ORDER (dmy|mdy, default dmy), GITHUB_REPOSITORY,
 *      CHANGELOG_RELEASE_DATES_FILE (JSON {"1.2.0":"2026-08-26"} built from GitHub Releases),
 *      CHANGELOG_EXTRA_DATES (JSON, same shape, for versions that never had a GitHub Release).
 * Dates are only used for readme headers that have no date of their own.
 */
'use strict';

const fs = require('fs');

const KNOWN_TYPES = [
  'Added', 'New', 'Fixed', 'Improved', 'Improvement', 'Updated', 'Update', 'Changed',
  'Removed', 'Deprecated', 'Security', 'Tweak', 'Tweaked', 'Revamped', 'Compatibility', 'Dev',
];
// "Fixed: text" or "Fixed - text" (dash needs surrounding space, so "Fixed-width …" stays plain text).
const TYPE_RE = new RegExp('^(' + KNOWN_TYPES.join('|') + ')\\s*(?::\\s*|[-–—]\\s+)', 'i');
const HEADER_RE = /^=\s*v?(\d[\w.\-+]*)\s*(?:[-–—|:]\s*(.*?))?\s*=\s*$/;
const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

function args(argv) {
  const out = { readme: process.env.CHANGELOG_README || 'readme.txt', version: '', all: false };
  for (let i = 2; i < argv.length; i++) {
    if (argv[i] === '--all') out.all = true;
    else if (argv[i] === '--readme') out.readme = argv[++i];
    else if (argv[i] === '--version') out.version = (argv[++i] || '').trim();
  }
  return out;
}

function pad(n) { return String(n).padStart(2, '0'); }

function isoDate(y, m, d) {
  const dt = new Date(Date.UTC(y, m - 1, d));
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) return null;
  return `${y}-${pad(m)}-${pad(d)}`;
}

/** Accepts 14/09/2026, 09/14/2026, 14.09.2026, 2026-09-14, "14 September 2026", "September 14, 2026". */
function parseDate(raw) {
  if (!raw) return null;
  const s = raw.trim();
  let m;
  if ((m = s.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/))) return isoDate(+m[1], +m[2], +m[3]);
  if ((m = s.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/))) {
    let a = +m[1], b = +m[2];
    const order = (process.env.CHANGELOG_DATE_ORDER || 'dmy').toLowerCase();
    // Unambiguous when one side is > 12; otherwise follow the configured order.
    if (a > 12) return isoDate(+m[3], b, a);
    if (b > 12) return isoDate(+m[3], a, b);
    return order === 'mdy' ? isoDate(+m[3], a, b) : isoDate(+m[3], b, a);
  }
  const month = (w) => MONTHS.indexOf(w.slice(0, 3).toLowerCase()) + 1;
  if ((m = s.match(/^(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]+),?\s+(\d{4})$/)) && month(m[2])) return isoDate(+m[3], month(m[2]), +m[1]);
  if ((m = s.match(/^([A-Za-z]+)\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/)) && month(m[1])) return isoDate(+m[3], month(m[1]), +m[2]);
  return null;
}

function toItem(text) {
  const m = text.match(TYPE_RE);
  if (!m) return { type: '', text };
  const t = m[1].toLowerCase();
  const canon = { new: 'Added', improvement: 'Improved', update: 'Updated', tweaked: 'Tweak' }[t]
    || m[1][0].toUpperCase() + m[1].slice(1).toLowerCase();
  return { type: canon, text: text.slice(m[0].length).trim() };
}

/** Bulleted (*, -, •) or plain lines; indented lines continue the previous item. */
function parseItems(lines) {
  const items = [];
  for (const line of lines) {
    if (!line.trim()) continue;
    const bullet = line.match(/^\s*[*\-•+]\s+(.*)$/);
    if (!bullet && /^\s{2,}\S/.test(line) && items.length) {
      items[items.length - 1].text += ' ' + line.trim();
      continue;
    }
    const text = (bullet ? bullet[1] : line).trim();
    if (text) items.push(toItem(text));
  }
  return items.filter((i) => i.text);
}

function parseReadme(src, knownDates = {}) {
  const lines = src.replace(/\r\n?/g, '\n').split('\n');
  const start = lines.findIndex((l) => /^==\s*Changelog\s*==\s*$/i.test(l.trim()));
  if (start === -1) throw new Error('No "== Changelog ==" section found');
  const entries = [];
  let cur = null;
  for (let i = start + 1; i < lines.length; i++) {
    const line = lines[i];
    if (/^==[^=].*==\s*$/.test(line.trim())) break; // next top-level section
    const h = line.trim().match(HEADER_RE);
    if (h) {
      cur = { version: h[1], date: parseDate(h[2]), date_raw: (h[2] || '').trim(), body: [] };
      entries.push(cur);
    } else if (cur) {
      cur.body.push(line);
    }
  }
  // An undated entry borrows the date of the newer entry above it, so a backfill
  // never floats an old version to the top (the version tie-break keeps it below).
  // Date priority: readme header → knownDates (GitHub Release / CHANGELOG_EXTRA_DATES) →
  // the newer entry above it (so a backfill never floats an old version to the top).
  let newer = null;
  return entries.map((e) => {
    const known = !e.date && knownDates[e.version] ? knownDates[e.version] : null;
    const date = e.date || known || newer;
    const date_source = e.date ? 'readme' : known ? 'release' : newer ? 'borrowed' : 'none';
    if (e.date || known) newer = e.date || known;
    return { version: e.version, date, date_raw: e.date_raw, dated: !!e.date, date_source, items: parseItems(e.body) };
  });
}

/**
 * Find the readme regardless of letter case (Pro repos often ship README.txt,
 * and Linux runners are case-sensitive). Returns the real path or null.
 */
function resolveReadme(wanted) {
  const path = require('path');
  const dir = path.dirname(wanted);
  const base = path.basename(wanted);
  let names;
  try { names = fs.readdirSync(dir); } catch { return null; }
  // Directory listing gives the real name even on case-insensitive disks (macOS).
  if (names.includes(base)) return wanted;
  const hit = names.find((n) => n.toLowerCase() === base.toLowerCase());
  return hit ? path.join(dir, hit) : null;
}

/** Release dates (file) + manual extra dates (env). Release dates win; bad values are ignored. */
function knownDates() {
  const load = (label, raw) => {
    if (!raw || !raw.trim()) return {};
    try {
      const obj = JSON.parse(raw);
      const out = {};
      for (const [k, v] of Object.entries(obj || {})) {
        if (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v)) out[String(k).replace(/^v/i, '')] = v;
      }
      return out;
    } catch {
      console.error(`::warning::${label} is not valid JSON; ignored`);
      return {};
    }
  };
  const file = process.env.CHANGELOG_RELEASE_DATES_FILE;
  const fromFile = file && fs.existsSync(file) ? load(file, fs.readFileSync(file, 'utf8')) : {};
  return { ...load('CHANGELOG_EXTRA_DATES', process.env.CHANGELOG_EXTRA_DATES), ...fromFile };
}

function readEvent() {
  const p = process.env.GITHUB_EVENT_PATH;
  if (!p || !fs.existsSync(p)) return {};
  try { return JSON.parse(fs.readFileSync(p, 'utf8')); } catch { return {}; }
}

function main() {
  const opt = args(process.argv);
  const event = readEvent();
  const release = event.release || null;
  const repo = process.env.GITHUB_REPOSITORY || '';
  const product = (process.env.CHANGELOG_PRODUCT || repo.split('/').pop() || '').trim();
  if (!product) throw new Error('CHANGELOG_PRODUCT is empty (set the repo variable)');

  const base = {
    product,
    product_name: process.env.CHANGELOG_PRODUCT_NAME || product,
    status: (process.env.CHANGELOG_STATUS || 'publish').toLowerCase() === 'draft' ? 'draft' : 'publish',
  };

  const readmePath = resolveReadme(opt.readme);
  if (!readmePath) {
    console.error(`::warning::${opt.readme} not found (any letter case) — set the CHANGELOG_README variable if it lives elsewhere`);
  } else if (readmePath !== opt.readme) {
    opt.readme = readmePath;
  }
  const entries = readmePath ? parseReadme(fs.readFileSync(readmePath, 'utf8'), knownDates()) : [];
  const releaseDate = release && release.published_at ? release.published_at.slice(0, 10) : null;
  const today = new Date().toISOString().slice(0, 10);

  const finish = (e, source) => {
    if (!e.items.length) throw new Error(`Changelog entry ${e.version} has no items`);
    if (e.date_raw && !e.dated) console.error(`::warning::Could not parse date "${e.date_raw}" for ${e.version}; using fallback`);
    return {
      ...base,
      version: e.version,
      // Undated single entry: the release's own publish date beats a borrowed one.
      date: (e.date_source === 'readme' || e.date_source === 'release') ? e.date : ((!opt.all && releaseDate) || e.date || today),
      items: e.items,
      source,
      source_url: release ? release.html_url : (repo ? `https://github.com/${repo}` : ''),
    };
  };

  if (opt.all) {
    if (!entries.length) throw new Error(`No changelog entries in ${opt.readme}`);
    process.stdout.write(JSON.stringify(entries.map((e) => finish(e, 'readme.txt')), null, 2) + '\n');
    return;
  }

  const tag = release && release.tag_name ? release.tag_name.replace(/^v/i, '') : '';
  const wanted = opt.version || tag;
  let entry = wanted ? entries.find((e) => e.version === wanted) : entries[0];
  let source = 'readme.txt';

  if (!entry && release && release.body && release.body.trim()) {
    // Fallback: the release notes themselves (strip markdown headings).
    const lines = release.body.split(/\r?\n/).filter((l) => !/^\s*(#|\*\*Full Changelog\*\*)/.test(l));
    entry = { version: wanted, date: releaseDate, date_raw: '', items: parseItems(lines) };
    source = 'github-release';
  }
  if (!entry) {
    throw new Error(wanted
      ? `Version ${wanted} not found in ${opt.readme} and the release has no notes`
      : `No changelog entries in ${opt.readme}`);
  }
  if (source === 'github-release') {
    console.error(`::warning::${wanted} not found in ${opt.readme}; used the GitHub Release notes instead`);
  }
  process.stdout.write(JSON.stringify(finish(entry, source), null, 2) + '\n');
}

if (require.main === module) {
  try { main(); } catch (err) { console.error(`::error::${err.message}`); process.exit(1); }
}

module.exports = { parseReadme, parseDate, parseItems, resolveReadme };
