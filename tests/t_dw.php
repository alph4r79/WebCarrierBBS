<?php
// Direct dial links (#msg/<id>, #area/<id>) through the terminal API, key L, list of an area,
// line in the editor, setting "hide handle on the web", note at the registration.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
setting('baud', '0');
setting('bbs_url', '');
q('DELETE FROM {nodes}');
q('UPDATE {msg_areas} SET public=0');
q('UPDATE {users} SET last_call=0, calls=0');
q('DELETE FROM {lastread}');
$base = $GLOBALS['BASE'];
$first = (int)val("SELECT id FROM {messages} WHERE subject='Hallo zusammen'");
$secret = (int)val("SELECT id FROM {messages} WHERE subject='Geheim'");
$priv = (int)val("SELECT id FROM {messages} WHERE private=1 AND subject='Private Post'");
// other suites create Regina again with a new id: the private mail of the seed goes to the current one
q("UPDATE {messages} SET to_id=(SELECT id FROM {users} WHERE handle_lc='regina'), is_read=0 WHERE id=?", [$priv]);
$max = (int)val('SELECT MAX(id) FROM {messages}');

/** Start a call with a target, log in and go on through the greeting until something of interest shows up. */
function dwcall(TC $t, string $dw, string $user, string $pass): string
{
    $t->api('start', '', $dw === '' ? [] : ['dw' => $dw]);
    $t->in($user);
    $r = $t->in($pass);
    $all = plain($r['o'] ?? '');
    for ($i = 0; $i < 6; $i++) {
        if (!empty($t->last['hang']) || atMenu($t) || preg_match('/Direktwahl|kann dein Zugang|gibt es nicht mehr/u', $all)) {
            break;
        }
        $k = (string)($t->ask['k'] ?? '');
        $r = $t->in($k === '' ? "\r" : (str_contains($k, "\r") ? "\r" : $k[0]));
        $all .= plain($r['o'] ?? '');
    }
    return $all;
}

function atMenu(TC $t): bool
{
    return ($t->ask['t'] ?? '') === 'hot' && str_contains((string)($t->ask['k'] ?? ''), 'G') && str_contains(plain($t->last['o'] ?? ''), 'Restzeit');
}

echo "== message, first call (no new since box)\n";
$t = new TC();
$o = dwcall($t, "msg/$first", 'Regina', 'regina12');
ok(str_contains($o, "Direktwahl: Nachricht $first in Allgemein"), 'line above the message');
ok(str_contains($o, 'Hallo an alle!') && str_contains($o, 'Betr: Hallo zusammen'), 'reader shows exactly this message');
ok(str_contains((string)$t->ask['k'], 'L'), 'key L offered for a public message');
$lr = (int)val('SELECT COUNT(*) FROM {lastread} WHERE user_id=(SELECT id FROM {users} WHERE handle=?)', ['Regina']);
ok($lr === 0, 'direct dial does not move the read pointer');
$r = $t->in('L');
ok(str_contains(plain($r['o']), "Link: {$base}#msg/$first"), 'L shows the direct dial address');
ok(($r['copy'] ?? '') === "{$base}#msg/$first", 'answer carries the address for the clipboard');
$r = $t->in('?');
ok(str_contains(plain($r['o']), 'L Link'), 'help lists L');
$r = $t->in('Q');
ok(atMenu($t), 'Q leads to the main menu');
// reload after the jump: same screen, no second jump
$rt = $t->rt;
$r = $t->resume($rt);
ok(!empty($r['resumed']) && !str_contains(plain($r['o']), 'Hallo an alle!'), 'F5 after the jump: line resumed, no second jump');
ok(atMenu($t) || ($t->ask['t'] ?? '') === 'hot', 'F5: prompt of the main menu again');
$t->in('G');
$t->in('J');

echo "== message, second call (with the new since box)\n";
q("UPDATE {users} SET last_call=? WHERE handle='Regina'", [time() - 86400 * 50]);
$t = new TC();
$o = dwcall($t, "msg/$first", 'Regina', 'regina12');
ok(str_contains($o, 'Neues seit') || str_contains($o, 'seit deinem letzten Anruf'), 'new since box shown before');
ok(str_contains($o, "Direktwahl: Nachricht $first in Allgemein"), 'then the message');
$t->in('Q');
ok(atMenu($t), 'Q to the main menu');
// a new call from the same tab after F5 without a target: no jump
$t2 = new TC($t->jar);
$o = dwcall($t2, '', 'Regina', 'regina12');
ok(!str_contains($o, 'Direktwahl') && atMenu($t2), 'next call without target goes to the main menu');
$t2->in('G');
$t2->in('J');

echo "== area\n";
$t = new TC();
$o = dwcall($t, 'area/2', 'Regina', 'regina12');
ok(str_contains($o, 'Direktwahl: Technik'), 'line above the list');
ok(str_contains($o, 'Seite 1/5') && str_contains($o, 'Technikfrage 64') && !str_contains($o, 'Technikfrage 49 '), 'list newest first, 15 per page');
ok(($t->ask['t'] ?? '') === 'line', 'list asks for a number');
$r = $t->in('N');
ok(str_contains(plain($r['o']), 'Seite 2/5') && str_contains(plain($r['o']), 'Technikfrage 49') && !str_contains(plain($r['o']), 'Direktwahl'), 'N: next page, the line only once');
$r = $t->in('L');
ok(str_contains(plain($r['o']), "Link: {$base}#area/2") && ($r['copy'] ?? '') === "{$base}#area/2", 'L: link of the area');
$r = $t->in('64');
ok(str_contains(plain($r['o']), 'Technikfrage 64'), 'number opens the reader');
$r = $t->in('Q');
ok(atMenu($t), 'Q from the reader to the main menu');
$t->in('G');
$t->in('J');

echo "== rights and missing targets\n";
foreach ([["msg/$secret", 'Diese Nachricht kann dein Zugang nicht öffnen.'], ['area/3', 'Diesen Bereich kann dein Zugang nicht öffnen.'],
             ['msg/' . ($max + 100), 'Diese Nachricht gibt es nicht mehr.'], ["msg/$priv", 'Diese Nachricht gibt es nicht mehr.'],
             ['area/99', 'Diesen Bereich gibt es nicht mehr.']] as [$dw, $text]) {
    $t = new TC();
    $o = dwcall($t, $dw, 'Regina', 'regina12');
    ok(str_contains($o, $text) && !str_contains($o, 'Psst') && !str_contains($o, 'Nur für Sysops'), "$dw: note \"$text\"");
    $r = $t->in("\r");
    ok(atMenu($t), "$dw: then the main menu");
    $t->in('G');
    $t->in('J');
}
// deleted message: target of an existing id, deleted before the call
$tmp = tdb()->query("INSERT INTO cb_messages (area_id, from_id, from_name, to_name, subject, body, posted, msgid) VALUES (1, 2, 'Regina', 'Alle', 'Weg', 'x', " . time() . ", 'tmp." . bin2hex(random_bytes(4)) . "')") ? (int)tdb()->lastInsertId() : 0;
q('DELETE FROM {messages} WHERE id=?', [$tmp]);
$t = new TC();
$o = dwcall($t, "msg/$tmp", 'Regina', 'regina12');
ok(str_contains($o, 'Diese Nachricht gibt es nicht mehr.'), 'deleted message: note');
$t->in("\r");
$t->in('G');
$t->in('J');
// sysop may read area 3
$t = new TC();
$o = dwcall($t, "msg/$secret", 'Sysop', 'geheim123');
ok(str_contains($o, "Direktwahl: Nachricht $secret in Sysops"), 'sysop reaches the sysop area');
$t->in('Q');
$t->in('G');
$t->in('J');

echo "== broken targets are ignored\n";
foreach (['msg/', 'msg/12a', 'area/-1', 'foo/3', 'msg/0', "msg/1\nx", 'msg/1234567890', '../msg/1'] as $dw) {
    $t = new TC();
    $o = dwcall($t, $dw, 'Regina', 'regina12');
    ok(!str_contains($o, 'Direktwahl') && !str_contains($o, 'Zugang') && atMenu($t), 'ignored: ' . json_encode($dw));
    $t->in('G');
    $t->in('J');
}

echo "== F5 before the jump keeps the target once\n";
q("UPDATE {users} SET last_call=? WHERE handle='Peter'", [time() - 86400 * 50]);
$t = new TC();
$t->api('start', '', ['dw' => "msg/$first"]);
$t->in('Peter');
$r = $t->in('peter123');
$r = $t->resume();
ok(!empty($r['resumed']), 'reload during the greeting resumes');
$o = '';
for ($i = 0; $i < 5 && !str_contains($o, 'Direktwahl'); $i++) {
    $k = (string)($t->ask['k'] ?? '');
    $o .= plain($t->in($k === '' || str_contains($k, "\r") ? "\r" : $k[0])['o']);
}
ok(str_contains($o, "Direktwahl: Nachricht $first"), 'target reached after the reload');
$t->in('Q');
$r = $t->resume();
ok(!str_contains(plain($r['o']), 'Hallo an alle!') && atMenu($t), 'second reload: no second jump');
$t->in('G');
$t->in('J');

echo "== waiting for validation\n";
$t = new TC();
$o = dwcall($t, "msg/$first", 'Paula', 'paula123');
ok(str_contains($o, 'Diese Nachricht kann dein Zugang nicht öffnen.') && !str_contains($o, 'Hallo an alle'), 'pending user: note on the waiting screen');
$r = $t->in("\r");
ok(!empty($r['hang']), 'then the line is hung up as before');

echo "== public areas: editor line, registration note, setting\n";
q('UPDATE {msg_areas} SET public=1 WHERE id IN (1, 2)');
setting('allow_new', '1');
setting('new_validate', '0');
q("DELETE FROM {users} WHERE handle_lc='neuling'");
$t = new TC();
$t->api('start', '', ['dw' => 'area/1']);
$t->in('NEU');
$t->in('Neuling');
$t->in('Neustadt');
$t->in('neuling1');
$r = $t->in('neuling1');
$o = plain($r['o']);
// questionnaire may be off: then the summary comes at once
ok(str_contains($o, 'Einige Bretter dieser Box sind auch im Web lesbar.') && str_contains($o, 'Was du dort schreibst, kann jeder sehen.'), 'registration: note before the confirmation');
$r = $t->in('J');
$o = plain($r['o']);
for ($i = 0; $i < 6 && !str_contains($o, 'Direktwahl'); $i++) {
    $k = (string)($t->ask['k'] ?? '');
    $o .= plain($t->in($k === '' || str_contains($k, "\r") ? "\r" : $k[0])['o']);
}
ok(str_contains($o, 'Direktwahl: Allgemein'), 'new user lands in the area after the greeting');
// write in the public area: line in the editor
$t->in('Q');
q("UPDATE {users} SET level=10 WHERE handle_lc='neuling'");
$t->in('M');
$r = $t->in('E');
$o = plain($r['o']);
for ($i = 0; $i < 4 && ($t->ask['t'] ?? '') !== 'edit'; $i++) {
    $r = $t->in($i === 0 ? '' : 'Hallo Web');
    $o .= plain($r['o']);
}
ok(str_contains($o, 'Dieses Brett ist auch im Web öffentlich lesbar.'), 'editor in a public area shows the line');
$t->in("\x00");
$t->in("\r");
$t->in('Q');
// setting W
$r = $t->in('S');
ok(str_contains(plain($r['o']), 'Im Web ohne Handle zeigen') && str_contains((string)$t->ask['k'], 'W'), 'settings: entry W while public areas exist');
$r = $t->in('W');
ok((int)val("SELECT web_anon FROM {users} WHERE handle_lc='neuling'") === 1, 'W switches it on');
$r = $t->in('W');
ok((int)val("SELECT web_anon FROM {users} WHERE handle_lc='neuling'") === 0, 'W switches it off again');
$t->in('Q');
$t->in('G');
$t->in('J');
q('UPDATE {msg_areas} SET public=0');
$t = new TC();
dwcall($t, '', 'Regina', 'regina12');
$r = $t->in('S');
ok(!str_contains(plain($r['o']), 'Im Web ohne Handle') && !str_contains((string)$t->ask['k'], 'W'), 'without public areas the entry is hidden');
$t->in('Q');
// editor in a non public area: no line
$t->in('M');
$r = $t->in('E');
$o = plain($r['o']);
for ($i = 0; $i < 4 && ($t->ask['t'] ?? '') !== 'edit'; $i++) {
    $r = $t->in($i === 0 ? '' : 'Hallo Box');
    $o .= plain($r['o']);
}
ok(!str_contains($o, 'öffentlich lesbar'), 'editor in a closed area: no line');
$t->in("\x00");
$t->in("\r");

echo "== list from the read prompt, L on private mail\n";
$r = $t->in('R');
ok(str_contains(plain($r['o']), '[L]iste'), 'read prompt offers the list');
$r = $t->in('L');
ok(str_contains(plain($r['o']), 'Nummer zum Lesen') && !str_contains(plain($r['o']), 'Direktwahl'), 'L opens the list of the current area');
$t->in('');
ok(($t->ask['t'] ?? '') === 'hot' && str_contains((string)$t->ask['k'], 'E'), 'Enter in the list: back to the menu');
$t->in('Q');
$t->in('G');
$t->in('J');
$t = new TC();
dwcall($t, '', 'Regina', 'regina12');
$t->in('M');
$r = $t->in('M');
ok(str_contains(plain($r['o']), 'Psst') && !str_contains((string)$t->ask['k'], 'L'), 'private mail: no key L');
$t->in('Q');
$t->in('Q');
$t->in('G');
$t->in('J');

/** Hot key of a command in the main menu. */
function DB_key(string $cmd): string
{
    return (string)val("SELECT i.hotkey FROM {menu_items} i JOIN {menus} m ON m.id=i.menu_id WHERE m.name='main' AND i.command=?", [$cmd]);
}

$log = file_get_contents(getenv('CB_LOG'));
ok(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'no PHP warnings in the server log');
summary();
