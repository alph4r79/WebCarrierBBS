// Browser tests: F5 in the middle of all kinds of prompts.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
const { BASE, ok, summary, sleep, Tab, sameScreen, stable, launch } = require('./pwlib');

async function f5(tab, label, check) {
  await tab.settle();
  const askBefore = JSON.stringify(tab.ask());
  const before = await stable(tab);
  const r = await tab.reload();
  ok(r && r.resumed === true, `${label}: resumed`);
  ok(JSON.stringify(r ? r.ask : null) === askBefore, `${label}: same prompt ${askBefore}`);
  await sameScreen(tab, before, label);
  if (check) { await check(r); }
  return r;
}

(async () => {
  const browser = await launch();
  const ctxA = await browser.newContext({ viewport: { width: 1100, height: 900 } });
  const a = new Tab(await ctxA.newPage());
  await a.open();

  console.log('== main menu');
  await a.login('Anna', 'anna1234');
  ok(!!(await a.token()), 'token in sessionStorage');
  await f5(a, 'main menu');
  ok(!!(await a.token()), 'token kept after F5');

  console.log('== reader in the middle of a message');
  await a.key('m');
  await a.key('r');
  // the long message stops with a more prompt
  await f5(a, 'reader (more prompt)');
  await a.key('Enter');
  ok((a.ask() || {}).t === 'hot', 'reader goes on after F5');
  await a.key('q');
  await a.key('q');
  await a.settle();

  console.log('== line with a mask');
  await a.key('s'); // settings
  const sk = (a.ask() || {}).k || '';
  console.log('    settings keys ' + JSON.stringify(sk));
  await a.key('p');
  await a.type('ann');
  await f5(a, 'masked line', async (r) => ok(r.ask && r.ask.t === 'line' && r.ask.p === true, 'still masked'));
  await a.line('anna1234');
  await a.settle();
  // abort the new password with an empty line, then back to the menu
  for (let i = 0; i < 4 && (a.ask() || {}).t === 'line'; i++) { await a.key('Enter'); }
  for (let i = 0; i < 4 && ((a.ask() || {}).k || '').indexOf('?') < 0; i++) { await a.key('q'); }

  console.log('== editor');
  await a.key('m');
  await a.key('e');
  await a.key('Enter'); // to all
  await a.line('Test nach F5');
  ok((a.ask() || {}).t === 'edit', 'editor open');
  await a.line('Erste Zeile im Editor.');
  await a.line('Zweite Zeile, die bleiben muss.');
  await a.type('halb getippt');
  await a.settle();
  const askEd = JSON.stringify(a.ask());
  const r = await a.reload();
  ok(r && r.resumed === true && JSON.stringify(r.ask) === askEd, 'editor: resumed with the same editor');
  const draft = await a.page.evaluate(() => sessionStorage.getItem('webcarrierbbs.ed'));
  ok(draft && draft.indexOf('Zweite Zeile') > 0, 'editor: finished lines kept');
  await a.line('/S');
  await a.settle();
  const posted = a.answers.slice(-3).map((x) => x.o || '').join('');
  ok(!(await a.page.evaluate(() => sessionStorage.getItem('webcarrierbbs.ed'))), 'editor: draft gone after /S');
  await a.settle();
  for (let i = 0; i < 4 && ((a.ask() || {}).k || '').indexOf('?') < 0; i++) { await a.key('Enter'); }
  await a.key('q');

  console.log('== yn and pause');
  await a.key('g');
  await f5(a, 'yn (log off?)');
  await a.key('n');
  await a.key('y'); // own statistics, ends with a pause
  await f5(a, 'pause');
  await a.key('Enter');
  ok(((a.ask() || {}).k || '').indexOf('?') >= 0, 'back in the menu after the pause');

  console.log('== meeting point with a second caller');
  const ctxB = await browser.newContext({ viewport: { width: 1100, height: 900 } });
  const b = new Tab(await ctxB.newPage());
  await b.open();
  await b.login('Bert', 'bert1234');
  await b.key('t');
  await a.key('t');
  await a.line('Hallo Bert');
  await sleep(2500);
  await b.settle();
  const bBefore = b.answers.length;
  await f5(a, 'meeting point');
  await sleep(3500);
  await b.settle();
  const bOut = b.answers.slice(bBefore).map((x) => x.o || '').join('');
  ok(!/verl.sst|left/.test(bOut), 'the other caller sees no leaving');
  await a.line('Wieder da');
  await sleep(3500);
  await b.settle();
  ok(b.answers.slice(bBefore).map((x) => x.o || '').join('').indexOf('Wieder da') >= 0, 'line after F5 reaches the other caller');
  await a.line('/q');
  await b.line('/q');

  console.log('== Hi-Lo in the middle of a game');
  await a.key('d');
  await a.key('1');
  await a.line('50');
  await f5(a, 'Hi-Lo guess', async (r) => ok(r.ask && r.ask.t === 'line' && r.ask.x === true, 'line still allows Escape'));
  await a.key('Escape');
  const giveup = a.answers.slice(-2).map((x) => x.o || '').join('');
  ok(/Aufgegeben/.test(Buffer.from(giveup, 'latin1').toString('latin1')), 'Escape after F5 gives up the game');
  await a.key('n');
  await a.key('q');

  console.log('== door with wait() and line() with Escape');
  await a.key('d');
  await a.key('w');
  ok((a.ask() || {}).t === 'wait' || (a.ask() || {}).t === 'line', 'test door started');
  // reload during the wait
  const rw = await a.reload();
  ok(rw && rw.resumed === true, 'F5 during wait(): resumed');
  await sleep(3500);
  await a.settle();
  ok((a.ask() || {}).t === 'line' && (a.ask() || {}).x === true, 'wait() went on at the same place, then the line');
  await f5(a, 'door line with Escape');
  await a.key('Escape');
  const ab = a.answers.slice(-2).map((x) => x.o || '').join('');
  ok(ab.indexOf('ABBRUCH') >= 0, 'Escape reaches the door as cancelled()');
  await a.settle();
  for (let i = 0; i < 3 && ((a.ask() || {}).k || '').indexOf('?') < 0; i++) { await a.key('Enter'); }
  await a.key('q');

  console.log('== chat with the sysop from the backend');
  const ctxS = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const s = await ctxS.newPage();
  await s.goto(BASE + 'sysop/');
  await s.fill('input[name=handle]', 'Sysop');
  await s.fill('input[name=pass]', 'geheim123');
  await s.click('button[type=submit]');
  await s.waitForLoadState();
  const node = await a.page.evaluate(() => 0);
  const btn = s.locator('button[name=chat_start]').first();
  await btn.click();
  await s.waitForLoadState();
  // the caller joins with the next poll (every 10 s in a menu)
  for (let i = 0; i < 30 && (a.ask() || {}).t !== 'chat'; i++) { await sleep(500); }
  ok((a.ask() || {}).t === 'chat', 'caller is in the chat');
  await a.line('Hallo Sysop');
  await sleep(2500);
  await f5(a, 'chat with the sysop');
  await sleep(4000);
  const chatText = await s.locator('#chat-lines').innerText();
  ok(chatText.indexOf('Hallo Sysop') >= 0, 'backend got the line');
  ok(!/nicht mehr online|no longer online|beendet|ended/.test(chatText), 'backend sees no abort during the reload');
  await s.fill('#chat-in', 'Antwort vom Sysop');
  await s.press('#chat-in', 'Enter');
  await sleep(3500);
  await a.settle();
  ok(a.answers.slice(-6).map((x) => x.o || '').join('').indexOf('Antwort vom Sysop') >= 0, 'caller gets the answer after F5');
  await a.line('/q');
  await a.settle();

  console.log('== sysop menu in the terminal');
  const ctxC = await browser.newContext({ viewport: { width: 1100, height: 900 } });
  const c = new Tab(await ctxC.newPage());
  await c.open();
  await c.login('Sysop', 'geheim123');
  await c.key('!');
  await f5(c, 'sysop menu');
  await c.key('q');

  console.log('== hang up removes the token');
  await a.key('g');
  await a.key('j');
  await a.settle();
  ok(!(await a.token()), 'token gone after logoff');

  console.log('== new tab is a new call');
  await c.settle();
  const tok = await c.token();
  const c2 = new Tab(await ctxC.newPage());
  await c2.open();
  ok(!(await c2.token()), 'new tab has no token');
  await c2.page.close();

  const errs = [].concat(a.errors, b.errors, c.errors).filter((e) => !/favicon/.test(e));
  ok(errs.length === 0, 'no errors in the console (CSP and scripts) ' + JSON.stringify(errs.slice(0, 3)));
  await browser.close();
  process.exit(summary() ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
