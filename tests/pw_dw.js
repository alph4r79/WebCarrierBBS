// Browser tests of direct dial links: hash read and removed at once, sent with the call, jump after the
// greeting, F5 without a second jump, key L with and without the clipboard, no CSP violation.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
const { BASE, ok, summary, sleep, Tab, launch } = require('./pwlib');
const MSG = parseInt(process.env.CB_MSG || '2', 10);

// records what the terminal prints and every CSP violation
const SPY = () => {
  window.__printed = [];
  window.__csp = [];
  document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(e.violatedDirective + ' ' + e.blockedURI));
  let ct;
  Object.defineProperty(window, 'CarrierTerm', {
    configurable: true,
    get() { return ct; },
    set(v) {
      const p = v.prototype.print;
      v.prototype.print = function (s) { window.__printed.push(String(s)); return p.apply(this, arguments); };
      ct = v;
    },
  });
};
const NOCLIP = () => {
  // a browser that does not allow writing to the clipboard
  Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: () => Promise.reject(new Error('denied')) } });
};

/** Bodies of all requests to api.php, in order. */
function bodies(page) {
  const list = [];
  page.on('request', (r) => {
    if (!r.url().endsWith('api.php')) { return; }
    try { list.push(JSON.parse(r.postData() || 'null') || {}); } catch (e) { list.push({}); }
  });
  return list;
}
const sent = (list, a) => list.filter((b) => b.a === a);

async function toTarget(tab) {
  for (let i = 0; i < 8; i++) {
    const out = tab.answers.map((a) => a.o || '').join('');
    if (/Direktwahl|kann dein Zugang|gibt es nicht mehr/.test(out)) { break; }
    const a = tab.ask();
    if (!a || a.t !== 'hot') { break; }
    if ((a.k || '').indexOf('?') >= 0 && (a.k || '').indexOf('G') >= 0) { break; }
    await tab.key('Enter');
  }
}

async function printed(page) { return page.evaluate(() => window.__printed.join('')); }

(async () => {
  const browser = await launch();

  console.log('== message link, clipboard allowed');
  const ctx = await browser.newContext();
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE.replace(/\/$/, '') });
  await ctx.addInitScript(SPY);
  const page = await ctx.newPage();
  const tab = new Tab(page);
  const req = bodies(page);
  await page.goto(BASE + '#msg/' + MSG);
  await sleep(300);
  ok(page.url() === BASE, 'hash removed from the address bar at once: ' + page.url());
  await tab.dial();
  const st = sent(req, 'start')[0];
  ok(st && st.dw === 'msg/' + MSG, 'start request carries the target: ' + JSON.stringify(st && st.dw));
  await tab.line('Regina');
  await tab.line('regina12');
  await toTarget(tab);
  let out = tab.answers.map((a) => a.o || '').join('').replace(/\x1b\[[0-9;]*m/g, '');
  ok(out.indexOf('Direktwahl: Nachricht ' + MSG) >= 0, 'jump to the message after the greeting');
  await tab.key('l');
  await sleep(600);
  const clip = await page.evaluate(() => navigator.clipboard.readText());
  ok(clip === BASE + '#msg/' + MSG, 'L: address on the clipboard: ' + clip);
  ok((await printed(page)).indexOf('Link kopiert.') >= 0, 'L: "Link kopiert." on the screen');
  ok((tab.ask() || {}).t === 'hot' && (tab.ask().k || '').indexOf('N') >= 0, 'reader prompt still waits');
  // F5 after the jump
  const r = await tab.reload();
  ok(r && r.resumed === true, 'F5 resumes the line');
  const rs = sent(req, 'resume').pop();
  ok(rs && !rs.dw, 'F5: no target sent again');
  ok((r.o || '').indexOf('Hallo an alle') >= 0 && (tab.ask() || {}).t === 'hot', 'F5: same screen and prompt, no new jump');
  await tab.key('q');
  const menu = tab.ask();
  ok(menu && (menu.k || '').indexOf('G') >= 0, 'Q: main menu');
  // a link of an area set in the same tab during the call: removed, not used
  await page.evaluate(() => { window.location.hash = '#area/2'; });
  await sleep(300);
  ok(page.url() === BASE, 'hash during a call removed');
  await tab.key('g');
  await tab.key('j');
  await sleep(500);
  await tab.dial();
  const st2 = sent(req, 'start').pop();
  ok(sent(req, 'start').length === 2 && st2 && !st2.dw, 'redial after hanging up: no target');
  ok((await page.evaluate(() => window.__csp)).length === 0, 'no CSP violation in the terminal');
  ok(tab.errors.length === 0, 'no console errors: ' + tab.errors.join(' | '));
  await ctx.close();

  console.log('== area link, clipboard not allowed');
  const ctx2 = await browser.newContext();
  await ctx2.addInitScript(SPY);
  await ctx2.addInitScript(NOCLIP);
  const page2 = await ctx2.newPage();
  const tab2 = new Tab(page2);
  await page2.goto(BASE + '#area/2');
  await sleep(300);
  ok(page2.url() === BASE, 'hash removed');
  await tab2.dial();
  await tab2.line('Regina');
  await tab2.line('regina12');
  await toTarget(tab2);
  out = tab2.answers.map((a) => a.o || '').join('').replace(/\x1b\[[0-9;]*m/g, '');
  ok(out.indexOf('Direktwahl: Technik') >= 0, 'jump to the list of the area');
  await tab2.line('L');
  await sleep(600);
  ok((await printed(page2)).indexOf('Link kopiert.') < 0, 'without permission: no "Link kopiert."');
  const last = tab2.answers.filter((a) => a._a === 'in').pop();
  ok(last && last.copy === BASE + '#area/2' && (last.o || '').indexOf('#area/2') >= 0, 'address shown on the screen');
  ok((tab2.ask() || {}).t === 'line', 'list prompt waits again');
  ok((await page2.evaluate(() => window.__csp)).length === 0, 'no CSP violation');
  ok(tab2.errors.length === 0, 'no console errors: ' + tab2.errors.join(' | '));
  await tab2.line('Q');
  await tab2.key('g');
  await tab2.key('j');
  await ctx2.close();

  console.log('== broken hashes');
  for (const h of ['#msg/12a', '#area/', '#foo', '#msg/1/2']) {
    const c = await browser.newContext();
    const p = await c.newPage();
    const t = new Tab(p);
    const rq = bodies(p);
    await p.goto(BASE + h);
    await sleep(200);
    ok(p.url() === BASE, h + ': removed from the address bar');
    await t.dial();
    const s = sent(rq, 'start')[0];
    ok(s && !s.dw, h + ': no target sent');
    await t.page.evaluate(() => navigator.sendBeacon('api.php', '{}'));
    await c.close();
  }
  await browser.close();
  process.exit(summary() ? 1 : 0);
})();
