// Screenshots of the new and changed backend pages, plus the live parts of them in a real browser.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
const path = require('path');
const { execFileSync } = require('child_process');
const { BASE, ok, summary, sleep, launch } = require('./pwlib');
const OUT = process.env.SHOTS;
const PHP = (code) => execFileSync('php', ['-c', process.env.PHPINI, '-r', 'require getenv("TCPATH"); ' + code], { env: process.env }).toString();

(async () => {
  const browser = await launch();
  for (const w of [375, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1 });
    const p = await ctx.newPage();
    const errors = [];
    p.on('pageerror', (e) => errors.push(String(e)));
    p.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });
    await p.goto(BASE + 'sysop/');
    await p.fill('input[name=handle]', 'Sysop');
    await p.fill('input[name=pass]', 'geheim123');
    await p.click('button[type=submit]');
    await p.waitForLoadState();

    // overview: daily backup starts and reports
    PHP("setting('auto_backup_last', '0'); setting('auto_backup_error', '');");
    await p.goto(BASE + 'sysop/?p=dash');
    const first = await p.locator('#autobackup').innerText();
    ok(/läuft/.test(first), `${w}px: overview shows "läuft" first`);
    await p.waitForFunction(() => !/läuft/.test(document.getElementById('autobackup').innerText), null, { timeout: 15000 });
    const done = await p.locator('#autobackup').innerText();
    ok(/erstellt/.test(done), `${w}px: then "${done.trim()}"`);
    await p.screenshot({ path: path.join(OUT, `dash-backup-${w}.png`), fullPage: false });
    // failed backup: yellow hint stays
    PHP("setting('auto_backup_error', json_encode(['time' => time(), 'msg' => 'cannot write the backup archive (disk full)']));");
    await p.goto(BASE + 'sysop/?p=dash');
    ok(await p.locator('#autobackup-err.flash.warn').count() === 1, `${w}px: failure hint on the overview`);
    await p.screenshot({ path: path.join(OUT, `dash-backup-failed-${w}.png`), fullPage: false });
    PHP("setting('auto_backup_error', '');");

    // backup page with the automatic backups
    await p.goto(BASE + 'sysop/?p=backup');
    await p.locator('h2', { hasText: 'Automatische Sicherungen' }).scrollIntoViewIfNeeded();
    await p.screenshot({ path: path.join(OUT, `backup-${w}.png`), fullPage: true });

    // BBS ad with preview, the preview follows the typing
    await p.goto(BASE + 'sysop/?p=bbsad');
    await p.screenshot({ path: path.join(OUT, `bbsad-${w}.png`), fullPage: true });
    await p.fill('#ad-text', 'Grüße von @BBSNAME:20R@!\nAm @DATE@ von @USER@');
    const prev = await p.locator('#ad-preview').innerText();
    ok(prev.indexOf('    Die Ölkanne! BBS!') >= 0 && prev.indexOf('von Sysop') >= 0, `${w}px: live preview fills macros with width (${JSON.stringify(prev.split('\n')[0])})`);

    // files with the checksum button, run it in the browser
    PHP("q(\"UPDATE {files} SET sha256='', sha256_orig=''\");");
    await p.goto(BASE + 'sysop/?p=files');
    await p.locator('#hash-run').scrollIntoViewIfNeeded();
    await p.screenshot({ path: path.join(OUT, `files-checksums-${w}.png`), fullPage: false });
    await p.click('#hash-run');
    await p.waitForFunction(() => /Alle Dateien/.test(document.getElementById('hash-progress').innerText), null, { timeout: 30000 });
    ok(true, `${w}px: button computes all checksums with progress`);
    await p.screenshot({ path: path.join(OUT, `files-checksums-done-${w}.png`), fullPage: false });

    // no horizontal scrolling of the page on the phone
    for (const pg of ['dash', 'backup', 'bbsad', 'files', 'settings']) {
      await p.goto(BASE + 'sysop/?p=' + pg);
      const over = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      ok(over <= 0, `${w}px: ${pg} without horizontal scrolling (${over})`);
    }
    ok(errors.length === 0, `${w}px: no script errors ${JSON.stringify(errors.slice(0, 2))}`);
    await ctx.close();
  }
  await browser.close();
  process.exit(summary() ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
