<?php
// API level tests of resuming a line after a reload.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
setting('baud', '0');
setting('max_nodes', '4');
setting('idle_minutes', '5');
q('DELETE FROM {nodes}');
mkuser('Anna', 'anna1234', 10);
mkuser('Bert', 'bert1234', 10);
q('UPDATE {calls} SET ended=time WHERE ended=0');

echo "== resume in the main menu\n";
$t = new TC();
$r = $t->login('Anna', 'anna1234');
ok(is_string($t->rt) && strlen($t->rt) === 32, 'token of 128 bit after the login');
$uid = (int)val("SELECT id FROM {users} WHERE handle='Anna'");
$calls = (int)val('SELECT calls FROM {users} WHERE id=?', [$uid]);
$ncalls = (int)val('SELECT COUNT(*) FROM {calls}');
$askBefore = $t->ask;
$screen = plain($r['o']);
$res = $t->resume();
ok(($res['resumed'] ?? null) === true, 'resumed');
ok($res['ask'] == $askBefore, 'same prompt (' . json_encode($res['ask']) . ')');
ok(str_ends_with(rtrim(plain($res['o'])), rtrim(substr($screen, -200))), 'same screen at the end');
ok(str_contains($res['o'], "\e[2J"), 'screen cleared first');
ok((int)val('SELECT calls FROM {users} WHERE id=?', [$uid]) === $calls, 'calls of the user not counted up');
ok((int)val('SELECT COUNT(*) FROM {calls}') === $ncalls, 'no new row in calls');
ok((int)val("SELECT COUNT(*) FROM {log} WHERE text LIKE 'Line resumed on node %' AND user_id=?", [$uid]) === 1, 'log line "Line resumed"');
ok(!str_contains(plain($res['o']), 'Willkommen, Anna'), 'no logon screens');
ok(isset($res['il']) && $res['il'] >= 0, 'seconds since the last key sent (' . ($res['il'] ?? '-') . ')');
// the line goes on normally
$r = $t->in('W');
ok(str_contains(plain($r['o']), 'Anna'), 'input after the resume works (who is online)');
$r = $t->in("\r");

echo "== token checks\n";
$res = $t->resume('0123456789abcdef0123456789abcdef');
ok(($res['resumed'] ?? null) === false, 'wrong token: no resume');
ok(str_contains(plain($res['o']), 'Dein Name'), 'wrong token: a new call starts (login prompt)');
ok((int)val('SELECT COUNT(*) FROM {calls} WHERE user_id=? AND ended=0', [$uid]) === 0, 'old call ended');
$t2 = new TC();
$t2->login('Anna', 'anna1234');
$tokA = $t2->rt;
$t3 = new TC();
$res = $t3->resume($tokA);
ok(($res['resumed'] ?? null) === false, 'token from another session refused');
$res = $t2->resume();
ok(($res['resumed'] ?? null) === true, 'the owner can still resume after that');
$t4 = new TC();
$t4->login('Bert', 'bert1234');
$res = $t4->resume($tokA);
ok(($res['resumed'] ?? null) === false, 'token of another user refused');
$t4->api('bye');
$t2->api('bye');

echo "== kicked and expired nodes\n";
$k = new TC();
$k->login('Anna', 'anna1234');
$node = (int)val('SELECT node FROM {nodes} WHERE user_id=?', [$uid]);
q('UPDATE {nodes} SET kicked=1 WHERE node=?', [$node]);
$res = $k->resume();
ok(($res['resumed'] ?? null) === false && str_contains(plain($res['o']), 'Dein Name'), 'disconnected node not resumed, new call');
$k->api('bye');
$k = new TC();
$k->login('Anna', 'anna1234');
q('UPDATE {nodes} SET seen=?, away=? WHERE user_id=?', [time() - 110, time() - 110, $uid]);
$res = $k->resume();
ok(($res['resumed'] ?? null) === true, 'away 110 s ago: resumed');
q('UPDATE {nodes} SET seen=?, away=? WHERE user_id=?', [time() - 125, time() - 125, $uid]);
$res = $k->resume();
ok(($res['resumed'] ?? null) === false, 'away 125 s ago: not resumed');
$k->api('bye');
// no away beacon (crash): 120 s after the last sign of life
$k = new TC();
$k->login('Anna', 'anna1234');
q('UPDATE {nodes} SET seen=?, away=0 WHERE user_id=?', [time() - 115, $uid]);
$res = $k->resume();
ok(($res['resumed'] ?? null) === true, 'no beacon, last seen 115 s ago: resumed');
q('UPDATE {nodes} SET seen=?, away=0 WHERE user_id=?', [time() - 121, $uid]);
$res = $k->resume();
ok(($res['resumed'] ?? null) === false, 'no beacon, last seen 121 s ago: not resumed');
$k->api('bye');

echo "== away beacon and cleanup\n";
$k = new TC();
$k->login('Anna', 'anna1234');
$k->api('away');
ok((int)val('SELECT away FROM {nodes} WHERE user_id=?', [$uid]) > 0, 'away marks the node');
$res = $k->resume();
ok(($res['resumed'] ?? null) === true && (int)val('SELECT away FROM {nodes} WHERE user_id=?', [$uid]) === 0, 'resume clears away');
$k->api('away');
$callId = (int)val('SELECT MAX(id) FROM {calls} WHERE user_id=?', [$uid]);
q('UPDATE {calls} SET time=time-300 WHERE id=?', [$callId]);
q('UPDATE {nodes} SET away=? WHERE user_id=?', [time() - 130, $uid]);
$o = new TC();
$o->api('start'); // any request runs the cleanup
ok((int)val('SELECT COUNT(*) FROM {nodes} WHERE user_id=?', [$uid]) === 0, 'node freed 120 s after the tab was left');
ok((int)val('SELECT ended FROM {calls} WHERE id=?', [$callId]) > 0, 'call ended (calls.ended)');
$o->api('bye');
$res = $k->resume();
ok(($res['resumed'] ?? null) === false, 'resume after the cleanup is a new call');
$k->api('bye');

echo "== before the login\n";
$p = new TC();
$p->api('start');
$p->in('Anna');
$res = $p->resume('');
ok(($res['resumed'] ?? null) === false && str_contains(plain($res['o']), 'Dein Name'), 'no resume before the login');
$p->api('bye');

echo "== hang up deletes the token\n";
$h = new TC();
$h->login('Anna', 'anna1234');
$tok = $h->rt;
$h->in('G');
$r = $h->in('J');
ok(!empty($r['hang']), 'logoff hangs up');
$res = $h->resume($tok);
ok(($res['resumed'] ?? null) === false, 'token useless after the logoff');
$h->api('bye');

echo "== idle time and time limit go on\n";
$h = new TC();
$h->login('Anna', 'anna1234');
sleep(3);
$res = $h->resume();
ok(($res['il'] ?? 0) >= 3, 'idle seconds keep counting over the reload (' . ($res['il'] ?? '-') . ')');
$h->api('bye');
setting('idle_minutes', '1');
$h = new TC();
$h->login('Anna', 'anna1234');
// last key 61 s ago: the line would have been cut, so a new call
$sess = session_save_path();
q('UPDATE {nodes} SET seen=? WHERE user_id=?', [time(), $uid]);
setting('idle_minutes', '5');
$h->api('bye');
// time limit: 30 minutes for level 10, 1795 s used before the call
q('UPDATE {users} SET time_today=1795, today=? WHERE id=?', [date('Y-m-d'), $uid]);
$h = new TC();
$r = $h->login('Anna', 'anna1234');
$res = $h->resume();
ok(($res['resumed'] ?? null) === true, 'time left: resumed');
sleep(6);
$res = $h->resume();
ok(($res['resumed'] ?? null) === false, 'time over during the reload: no resume, new call');
q('UPDATE {users} SET time_today=0 WHERE id=?', [$uid]);
$h->api('bye');

echo "== full board with the old node of the same session\n";
setting('max_nodes', '1');
q('DELETE FROM {nodes}');
$f = new TC();
$f->login('Anna', 'anna1234');
$fCall = (int)val('SELECT MAX(id) FROM {calls} WHERE user_id=?', [$uid]);
$f->api('away');
$x = new TC();
$r = $x->api('start');
ok(str_contains(plain($r['o']), 'belegt') || !empty($r['hang']), 'another session: all lines busy');
$r = $f->api('start');
ok(str_contains(plain($r['o']), 'Dein Name'), 'same session, new tab: old node freed at once, new call');
ok((int)val('SELECT ended FROM {calls} WHERE id=?', [$fCall]) > 0, 'old call ended');
$f->api('bye');
setting('max_nodes', '4');

echo "== screen buffer over 64 KB\n";
q('DELETE FROM {nodes}');
$s = new TC();
$s->login('Anna', 'anna1234');
$node = (int)val('SELECT node FROM {nodes} WHERE user_id=?', [$uid]);
for ($i = 0; $i < 1100; $i++) {
    q("INSERT INTO {node_msgs} (node, kind, from_node, from_handle, text, time) VALUES (?, 'msg', 0, 'Sysop', ?, ?)", [$node, sprintf('Zeile %04d ', $i) . str_repeat('x', 50), time()]);
}
$r = $s->api('poll');
$len = strlen(mb_convert_encoding((string)val('SELECT screen FROM {nodes} WHERE node=?', [$node]), 'ISO-8859-1', 'UTF-8'));
ok($len <= 65536 && $len > 60000, "buffer cut to 64 KB ($len bytes)");
$res = $s->resume();
$pl = plain($res['o']);
ok(str_contains($pl, 'Zeile 1099') && !str_contains($pl, 'Zeile 0000'), 'resume shows the newest part, the oldest is gone');
ok(str_ends_with(rtrim($pl), rtrim(substr(plain($t->last['o'] ?? ''), -1))) || str_contains(substr($pl, -200), 'Restzeit'), 'prompt again after the poll output');
$s->api('bye');

summary();
