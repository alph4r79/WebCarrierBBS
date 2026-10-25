<?php
// Hi-Lo 1.3.1 on an older board: start, hall of fame, a won game, Escape where the board knows it.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
setting('baud', '0');
mkuser('Oldie', 'oldie123', 10);
$ver = (string)val("SELECT value FROM {settings} WHERE name='db_version'");
$t = new TC();
$r = $t->login('Oldie', 'oldie123');
$r = $t->in('D');
$keys = (string)($r['ask']['k'] ?? '');
$r = $t->in('1');
$o = plain($r['o']);
ok(str_contains($o, 'Ruhmeshalle') || str_contains($o, 'Hall of fame'), "db $ver: Hi-Lo starts with the hall of fame");
ok(!preg_match('/(Fehler|failed|error)/i', $o), "db $ver: no door error");
$lo = 1;
$hi = 100;
$won = false;
for ($i = 0; $i < 7; $i++) {
    $g = intdiv($lo + $hi, 2);
    $r = $t->in((string)$g);
    $o = plain($r['o']);
    if (str_contains($o, 'Richtig')) {
        $won = true;
        break;
    }
    if (str_contains($o, 'Höher')) {
        $lo = $g + 1;
    } elseif (str_contains($o, 'Niedriger')) {
        $hi = $g - 1;
    } else {
        echo "    unexpected: " . substr($o, -200) . "\n";
        break;
    }
}
ok($won && str_contains($o, 'Rekord'), "db $ver: game won, personal best");
$hasScores = (bool)val("SELECT COUNT(*) FROM " . (getenv('CB_MYSQL') ? 'information_schema.tables WHERE table_name=\'cb_door_scores\'' : "sqlite_master WHERE name='cb_door_scores'"));
$uid = (int)val("SELECT id FROM {users} WHERE handle='Oldie'");
if ($hasScores) {
    ok((int)val("SELECT COUNT(*) FROM {door_scores} WHERE door='hilo' AND user_id=?", [$uid]) === 1, "db $ver: best value in the high score list");
} else {
    ok(val("SELECT v FROM {door_data} WHERE door='hilo' AND user_id=? AND k='best'", [$uid]) !== false, "db $ver: no high score list, best value in door_data");
}
$r = $t->in('J');
$r = $t->in('50');
$r = $t->in("\x1b");
$o = plain($r['o']);
echo "    after Escape: " . trim(substr(preg_replace('/\s+/', ' ', $o), 0, 80)) . " ask=" . json_encode($r['ask']) . "\n";
ok(empty($r['hang']) && !preg_match('/(Fehler|failed)/i', $o), "db $ver: Escape does not break the door");
// the hall of fame again with the new entry. Boards before 1.10.0 do not pass Escape to the door as an
// abort, the game goes on there: start the door again in a new call instead.
if (str_contains($o, 'Nochmal spielen')) {
    $t->in('N');
    $t->in('1');
} else {
    $t->api('bye');
    $t = new TC();
    $t->login('Oldie', 'oldie123');
    $t->in('D');
    $t->in('1');
}
$o = plain($t->last['o']);
ok(str_contains($o, 'Oldie'), "db $ver: own entry in the hall of fame");
$t->api('bye');
summary();
