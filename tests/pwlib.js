/*
 * WebCarrier BBS tests: helpers for the browser tests with Playwright.
 * Playwright comes from require('playwright') or from the folder in CB_PLAYWRIGHT.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
function playwright() {
  for (const name of [process.env.CB_PLAYWRIGHT, 'playwright']) {
    if (!name) { continue; }
    try { return require(name); } catch (e) { /* next */ }
  }
  throw new Error('Playwright not found: npm install playwright, or set CB_PLAYWRIGHT to its folder');
}
const { chromium } = playwright();

const BASE = process.env.CB_BASE || 'http://127.0.0.1:8811/';
let passes = 0, fails = 0;
function ok(c, what) {
  if (c) { passes++; console.log('  ok   ' + what); } else { fails++; console.log('  FAIL ' + what); }
}
function summary() {
  console.log(`\n== ${passes} passed, ${fails} failed`);
  return fails;
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** A terminal tab: tracks the answers of api.php, so the tests know the current prompt. */
class Tab {
  constructor(page) {
    this.page = page;
    this.last = null;
    this.answers = [];
    this.pending = 0;
    this.lastAt = Date.now();
    this.errors = [];
    page.on('console', (m) => { if (m.type() === 'error') { this.errors.push(m.text()); } });
    page.on('pageerror', (e) => this.errors.push(String(e)));
    page.on('request', (r) => { if (r.url().endsWith('api.php')) { this.pending++; } });
    page.on('requestfailed', (r) => { if (r.url().endsWith('api.php')) { this.pending--; } });
    page.on('response', async (r) => {
      if (!r.url().endsWith('api.php')) { return; }
      let j = null;
      try { j = await r.json(); } catch (e) { j = null; }
      let body = null;
      try { body = JSON.parse(r.request().postData() || 'null'); } catch (e) { body = null; }
      this.pending--;
      this.lastAt = Date.now();
      if (j) {
        j._a = body ? body.a : '';
        this.answers.push(j);
        if (j._a !== 'poll' && j._a !== 'ping' && j._a !== 'away') { this.last = j; }
        else if (j._a === 'poll' && j.ask) { this.last = j; }
      }
    });
  }
  async open() {
    await this.page.goto(BASE);
    await this.page.waitForTimeout(300);
  }
  /** Wait until no request runs and nothing came for a moment. */
  async settle(ms = 500) {
    const t0 = Date.now();
    while (Date.now() - t0 < 15000) {
      if (this.pending <= 0 && Date.now() - this.lastAt > ms) { break; }
      await sleep(50);
    }
    await sleep(150);
  }
  async key(k) { await this.page.keyboard.press(k); await this.settle(); }
  async type(s) { await this.page.keyboard.type(s, { delay: 15 }); }
  async line(s) { await this.type(s); await this.key('Enter'); }
  ask() { return this.last ? this.last.ask : null; }
  async dial() {
    await this.page.keyboard.press('x');
    // modem sound off: 1.5 s, then CONNECT and the start request
    await sleep(1800);
    await this.settle();
  }
  async login(handle, pass) {
    await this.dial();
    await this.line(handle);
    await this.line(pass);
    // pauses, the question for new messages and the reader up to the main menu (it has G and ?)
    for (let i = 0; i < 12; i++) {
      const a = this.ask();
      const k = (a && a.k) || '';
      if (!a || (k.indexOf('?') >= 0 && k.indexOf('G') >= 0)) { break; }
      if (a.t === 'line') { await this.key('Enter'); continue; }
      await this.key(k === '' ? 'Enter' : (k.indexOf('Q') >= 0 ? 'q' : (k.indexOf('N') >= 0 ? 'n' : 'Enter')));
    }
  }
  async token() { return this.page.evaluate(() => sessionStorage.getItem('webcarrierbbs.rt')); }
  /** Pixels of the terminal canvas. */
  async pixels() {
    return this.page.evaluate(() => {
      const c = document.getElementById('term');
      const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
      let s = '';
      for (let i = 0; i < d.length; i += 4) { s += String.fromCharCode((d[i] >> 4) | (d[i + 1] & 0xf0)); }
      return s;
    });
  }
  /** F5: reload, wait for the resume. Returns the answer to resume. */
  async reload() {
    this.last = null;
    await this.page.reload();
    await this.settle(700);
    return this.answers.filter((a) => a._a === 'resume').pop() || null;
  }
}

function diff(a, b) {
  if (a.length !== b.length) { return Infinity; }
  let n = 0;
  for (let i = 0; i < a.length; i++) { if (a.charCodeAt(i) !== b.charCodeAt(i)) { n++; } }
  return n;
}

/** Hide the blinking cursor for a stable picture: compare twice and keep the smaller difference. */
async function sameScreen(tab, before, label) {
  let best = Infinity;
  for (let i = 0; i < 4; i++) {
    const now = await tab.pixels();
    best = Math.min(best, diff(before, now));
    if (best === 0) { break; }
    await sleep(300);
  }
  ok(best <= 400, `${label}: same screen after F5 (${best} pixels differ)`);
  return best;
}

/** Stable picture before a reload: the cursor blinks, take two and use one where both agree. */
async function stable(tab) {
  await sleep(200);
  return tab.pixels();
}

async function launch() {
  return chromium.launch({ headless: true });
}

module.exports = { BASE, ok, summary, sleep, Tab, diff, sameScreen, stable, launch };
