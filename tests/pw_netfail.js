// Browser test: after a line error the token stays and F5 resumes the call.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
const { ok, summary, sleep, Tab, launch } = require('./pwlib');
(async () => {
  const browser = await launch();
  const a = new Tab(await (await browser.newContext()).newPage());
  await a.open();
  await a.login('Anna', 'anna1234');
  // the next request to the box fails
  await a.page.route('**/api.php', (route) => route.abort('connectionfailed'));
  await a.page.keyboard.press('w');
  await sleep(1000);
  await a.page.unroute('**/api.php');
  ok(!!(await a.token()), 'line error: token kept');
  const r = await a.reload();
  ok(r && r.resumed === true, 'F5 after the line error resumes the call');
  await a.key('g');
  await a.key('j');
  await a.settle();
  ok(!(await a.token()), 'normal logoff still removes the token');
  // NO CARRIER and a key: new call, the old token is gone
  await a.page.keyboard.press('x');
  await sleep(2200);
  await a.settle();
  ok(!(await a.token()), 'dialing again starts without token');
  await browser.close();
  process.exit(summary() ? 1 : 0);
})();
