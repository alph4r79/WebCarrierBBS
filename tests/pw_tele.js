// Browser test: a line in the meeting point reaches the other caller.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
const { ok, summary, sleep, Tab, launch } = require('./pwlib');
(async () => {
  const browser = await launch();
  const a = new Tab(await (await browser.newContext()).newPage());
  const b = new Tab(await (await browser.newContext()).newPage());
  await a.open(); await b.open();
  await a.login('Anna', 'anna1234');
  await b.login('Bert', 'bert1234');
  await b.key('t');
  await a.key('t');
  await a.line('Hallo Bert');
  await sleep(4000);
  const kinds = b.answers.map((x) => x._a + ':' + (x.o || '').length).join(' ');
  console.log('B answers: ' + kinds);
  ok(b.answers.map((x) => x.o || '').join('').indexOf('Hallo Bert') >= 0, 'line before F5 reaches B');
  const n = b.answers.length;
  await a.reload();
  await a.line('Wieder da');
  await sleep(4000);
  console.log('B answers after: ' + b.answers.slice(n).map((x) => x._a + ':' + (x.o || '').length).join(' '));
  console.log('A last: ' + JSON.stringify(a.last && a.last.ask));
  ok(b.answers.slice(n).map((x) => x.o || '').join('').indexOf('Wieder da') >= 0, 'line after F5 reaches B');
  await browser.close();
  process.exit(summary() ? 1 : 0);
})();
