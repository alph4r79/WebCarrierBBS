<?php
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
$t = new TC();
$t->login('Oldie', 'oldie123');
$t->in('D');
$r = $t->in('1');
$o = plain($r['o']);
ok(preg_match('/1\. Oldie\s+\d/', $o) === 1, 'own entry in the hall of fame on a fresh start');
$t->api('bye');
summary();
