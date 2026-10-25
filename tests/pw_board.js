// Public boards and the area page of the backend in a real browser: no CSP violation, no console error,
// no horizontal scrolling of the page, screenshots at 375 and 1280 px.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
const path = require('path');
const { BASE, ok, summary, launch } = require('./pwlib');
const OUT = process.env.CB_SHOTS;
const MSG = process.env.CB_MSG || '2';
const PIPE = process.env.CB_PIPE || '4';

const SPY = () => {
  window.__csp = [];
  document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(e.violatedDirective + ' ' + e.blockedURI));
};

(async () => {
  const browser = await launch();
  for (const w of [375, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 375 ? 812 : 900 } });
    await ctx.addInitScript(SPY);
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });
    page.on('pageerror', (e) => errors.push(String(e)));
    const pages = [['board-overview', 'board.php'], ['board-area', 'board.php?a=2'], ['board-message', 'board.php?m=' + MSG],
      ['board-pipes', 'board.php?m=' + PIPE]];
    for (const [name, url] of pages) {
      await page.goto(BASE + url);
      await page.waitForLoadState('networkidle');
      const csp = await page.evaluate(() => window.__csp);
      const scroll = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      ok(csp.length === 0, `${w}px ${name}: no CSP violation ${csp.join(' ')}`);
      ok(scroll <= 0, `${w}px ${name}: no horizontal scrolling of the page (${scroll})`);
      if (name === 'board-pipes') {
        const pre = await page.evaluate(() => { const p = document.querySelector('.msgbody'); return [p.scrollWidth, p.clientWidth]; });
        ok(pre[0] >= pre[1], `${w}px: text block keeps 80 columns, scrolls inside (${pre[0]}/${pre[1]})`);
        continue;
      }
      await page.screenshot({ path: path.join(OUT, `${name}-${w}.png`), fullPage: true });
    }
    // backend: message areas
    await page.goto(BASE + 'sysop/index.php');
    await page.fill('input[name=handle]', 'Sysop');
    await page.fill('input[name=pass]', 'geheim123');
    await Promise.all([page.waitForNavigation(), page.click('form:has(input[name=handle]) button[type=submit]')]);
    await page.goto(BASE + 'sysop/index.php?p=msgareas');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, `backend-msgareas-${w}.png`), fullPage: true });
    ok((await page.locator('input[name="a[3][public]"]').isDisabled()), `${w}px backend: box of the closed area is greyed out`);
    // copy button with clipboard permission
    await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE.replace(/\/$/, '') });
    const btn = page.locator('button[data-copy]').first();
    await btn.click();
    await page.waitForTimeout(300);
    ok((await btn.textContent()) === 'Link kopiert', `${w}px backend: copy button says "Link kopiert"`);
    ok((await page.evaluate(() => navigator.clipboard.readText())) === BASE + '#area/1', `${w}px backend: area link on the clipboard`);
    await page.goto(BASE + 'sysop/index.php?p=messages&view=' + MSG);
    await page.screenshot({ path: path.join(OUT, `backend-message-${w}.png`), fullPage: false });
    await page.locator('button[data-copy]').click();
    await page.waitForTimeout(300);
    ok((await page.evaluate(() => navigator.clipboard.readText())) === BASE + '#msg/' + MSG, `${w}px backend: message link on the clipboard`);
    await page.goto(BASE + 'sysop/index.php?p=settings');
    ok((await page.locator('input[name=bbs_url]').inputValue()) === BASE, `${w}px backend: address of the board suggested`);
    ok(errors.length === 0, `${w}px: no console errors ${errors.join(' | ')}`);
    await ctx.close();
  }
  await browser.close();
  process.exit(summary() ? 1 : 0);
})();
