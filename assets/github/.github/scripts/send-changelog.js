#!/usr/bin/env node
/**
 * Send one entry (object) or many entries (array, for backfill) to the site.
 *
 * Usage: node send-changelog.js entry.json
 * Env:   CHANGELOG_ENDPOINT  e.g. https://example.com/wp-json/changelog-sync/v1/entry
 *        CHANGELOG_SECRET    shared HMAC secret (same value as on the site)
 *
 * Signature: hex HMAC-SHA256 of "<timestamp>.<raw body>", sent in
 * X-Changelog-Signature with the timestamp in X-Changelog-Timestamp.
 */
'use strict';

const fs = require('fs');
const crypto = require('crypto');

const endpoint = process.env.CHANGELOG_ENDPOINT;
const secret = process.env.CHANGELOG_SECRET;

async function send(entry) {
  const body = JSON.stringify(entry);
  for (let attempt = 1; attempt <= 3; attempt++) {
    const ts = String(Math.floor(Date.now() / 1000));
    const sig = crypto.createHmac('sha256', secret).update(`${ts}.${body}`).digest('hex');
    let res, text;
    try {
      res = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'User-Agent': 'changelog-sync/1.0 (GitHub Actions)',
          'X-Changelog-Timestamp': ts,
          'X-Changelog-Signature': sig,
        },
        body,
      });
      text = await res.text();
    } catch (err) {
      text = err.message;
    }
    if (res && res.ok) {
      let out = {};
      try { out = JSON.parse(text); } catch { /* non-JSON success */ }
      console.log(`✓ ${entry.product} ${entry.version}: ${out.action || 'ok'} (post ${out.id || '?'}, ${out.status || ''}) ${out.link || ''}`);
      return;
    }
    // 4xx (except 429) will not fix itself: fail immediately.
    if (res && res.status < 500 && res.status !== 429) {
      throw new Error(`${entry.version}: HTTP ${res.status} ${text.slice(0, 300)}`);
    }
    console.error(`… ${entry.version}: attempt ${attempt} failed (${res ? res.status : 'network'}: ${String(text).slice(0, 120)})`);
    await new Promise((r) => setTimeout(r, attempt * 5000));
  }
  throw new Error(`${entry.version}: gave up after 3 attempts`);
}

(async () => {
  if (!endpoint || !secret) throw new Error('CHANGELOG_ENDPOINT and CHANGELOG_SECRET must be set');
  const data = JSON.parse(fs.readFileSync(process.argv[2] || 'entry.json', 'utf8'));
  const list = Array.isArray(data) ? data : [data];
  for (const entry of list) await send(entry);
})().catch((err) => { console.error(`::error::${err.message}`); process.exit(1); });
