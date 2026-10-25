<?php
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
setting('idle_minutes', '1');
$t = new TC();
$t->login('Bert', 'bert1234');
$r = $t->resume();
ok(($r['resumed'] ?? null) === true, 'resume within the idle time');
sleep(62);
$r = $t->resume();
ok(($r['resumed'] ?? null) === false && str_contains(plain($r['o']), 'Dein Name'), 'idle time over during the reload: new call instead');
$t->api('bye');
setting('idle_minutes', '5');
summary();
