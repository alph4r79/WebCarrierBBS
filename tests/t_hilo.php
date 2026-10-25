<?php
// Hi-Lo reads and writes the high score list of the board.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
setting('baud', '0');
q('DELETE FROM {nodes}');
q("DELETE FROM {door_scores} WHERE door='hilo'");
q("DELETE FROM {door_data} WHERE door='hilo'");
$u1 = mkuser('Hans', 'hans1234', 10);
$u2 = mkuser('Grete', 'grete123', 10);
$u3 = mkuser('Locki', 'locki123', 10);
$p = mkuser('Player', 'player12', 10);
$t0 = time() - 1000;
foreach ([[$u1, 4, '4 Versuche'], [$u2, 3, '3 Versuche'], [$u3, 1, '1 Versuch']] as $i => [$u, $v, $l]) {
    q("INSERT INTO {door_scores} (door, user_id, value, label, time) VALUES ('hilo',?,?,?,?)", [$u, $v, $l, $t0 + $i]);
}
q('UPDATE {users} SET locked=1 WHERE id=?', [$u3]);
// old best value in door_data that is worse than the list: must not overwrite it
q("INSERT INTO {door_data} (door, user_id, k, v) VALUES ('hilo',?,'best','6')", [$u2]);

$t = new TC();
$t->login('Player', 'player12');
$r = $t->in('D');
$box = plain($r['o']);
preg_match('/Hi-Lo\s+(\S+)\s+(\d+ Versuch\w*)/u', $box, $m);
ok(($m[1] ?? '') === 'Grete' && ($m[2] ?? '') === '3 Versuche', 'box above the doors: leader Grete, 3 tries (' . ($m[1] ?? '-') . ')');
$r = $t->in('1');
$hall = plain($r['o']);
preg_match_all('/(\d)\. (\S+)\s+(\d+)/', $hall, $hm, PREG_SET_ORDER);
ok(($hm[0][2] ?? '') === 'Grete' && ($hm[0][3] ?? '') === '3' && ($hm[1][2] ?? '') === 'Hans', 'hall of fame from the list: 1. Grete 3, 2. Hans (' . json_encode(array_map(static fn($x) => $x[2] . ' ' . $x[3], $hm)) . ')');
ok(!str_contains($hall, 'Locki'), 'locked user neither in the box nor in the hall of fame');
ok((int)val("SELECT value FROM {door_scores} WHERE door='hilo' AND user_id=?", [$u2]) === 3, 'a worse old best value of door_data did not replace the list entry');
$dataBefore = (int)val("SELECT COUNT(*) FROM {door_data} WHERE door='hilo'");

// play: binary search
$lo = 1;
$hi = 100;
$won = false;
$tries = 0;
while ($tries < 7) {
    $g = intdiv($lo + $hi, 2);
    $r = $t->in((string)$g);
    $tries++;
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
        break;
    }
}
ok($won, "won the game in $tries tries");
$row = q("SELECT value, label FROM {door_scores} WHERE door='hilo' AND user_id=?", [$p])->fetch();
ok($row && (int)$row['value'] === $tries && $row['label'] === ($tries === 1 ? '1 Versuch' : "$tries Versuche"), 'new record in the list: ' . json_encode($row));
ok((int)val("SELECT COUNT(*) FROM {door_data} WHERE door='hilo'") === $dataBefore, 'nothing written to door_data');
ok(str_contains($o, 'Neuer persönlicher Rekord'), 'message of a new personal best');
$t->in('N');
$t->api('bye');
summary();
