<?php
// Public boards (board.php), sitemap, robots, hidden handles, and the backend side: areas, settings, links.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
$B = $GLOBALS['BASE'];
array_map('unlink', glob(getenv('CB_ROOT') . '/data/tmp/rate/*') ?: []);
setting('bbs_url', '');
setting('public_index', '1');
setting('new_level', '10');
q('UPDATE {msg_areas} SET public=0');
q('UPDATE {users} SET web_anon=0');
$first = (int)val("SELECT id FROM {messages} WHERE subject='Hallo zusammen'");
$reply = (int)val("SELECT id FROM {messages} WHERE subject='Re: Hallo zusammen'");
$pipe = (int)val("SELECT id FROM {messages} WHERE subject LIKE 'Farben%'");
$secret = (int)val("SELECT id FROM {messages} WHERE subject='Geheim'");
$priv = (int)val('SELECT id FROM {messages} WHERE private=1');
$w = new TC();

function get(TC $w, string $url): array
{
    $r = $w->req($url);
    $r['text'] = html_entity_decode(strip_tags($r['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $r;
}
function noWarn(array $r): bool
{
    return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)/', $r['body']);
}

echo "== nothing public\n";
foreach (['board.php', 'board.php?a=1', "board.php?m=$first", 'board.php?sitemap=1'] as $u) {
    ok(get($w, $u)['code'] === 404, "$u: 404");
}

q('UPDATE {msg_areas} SET public=1 WHERE id IN (1, 2)');
echo "== overview\n";
$r = get($w, 'board.php');
ok($r['code'] === 200 && noWarn($r), 'overview 200');
ok(str_contains($r['text'], 'Allgemein') && str_contains($r['text'], 'Technik') && !str_contains($r['text'], 'Sysops'), 'only public boards');
ok(str_contains($r['text'], 'Plaudern über alles') && str_contains($r['text'], 'Nachrichten: 64') && str_contains($r['text'], 'Letzte Nachricht: Technikfrage 64'), 'description, count, last message');
ok(str_contains($r['body'], '<html lang="de">') && str_contains($r['body'], '<link rel="canonical" href="' . $B . 'board.php">'), 'lang and canonical');
ok(str_contains($r['body'], '<meta name="robots" content="index, follow">') && str_contains($r['body'], '<meta name="description"'), 'robots index, description');
ok(str_contains($r['body'], 'href="legal.php?p=impressum"') && str_contains($r['body'], 'href="legal.php?p=privacy"') && str_contains($r['body'], 'href="./"'), 'head and foot links');
ok(str_contains($r['head'], "Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"), 'same CSP as the legal pages');
ok(str_contains($r['head'], 'X-Content-Type-Options: nosniff') && str_contains($r['head'], 'Referrer-Policy: same-origin'), 'security headers');
ok(!preg_match('/^Set-Cookie:/mi', $r['head']), 'no cookie');
ok(!str_contains($r['body'], '<script'), 'no JavaScript');

echo "== board with pages\n";
$r = get($w, 'board.php?a=2');
ok($r['code'] === 200 && noWarn($r), 'board 200');
ok(substr_count($r['body'], 'class="subj"') === 30, '30 messages on page 1');
ok(strpos($r['text'], 'Technikfrage 64') < strpos($r['text'], 'Technikfrage 63'), 'newest first');
ok(str_contains($r['text'], 'Seite 1 von 3') && str_contains($r['body'], 'rel="next" href="board.php?a=2&amp;page=2"'), 'pager to page 2');
ok(str_contains($r['body'], 'href="./#area/2"') && str_contains($r['text'], 'Mitreden? Box anrufen'), 'call link with direct dial');
$r = get($w, 'board.php?a=2&page=3');
ok($r['code'] === 200 && substr_count($r['body'], 'class="subj"') === 4 && str_contains($r['text'], 'Technikfrage 1') && str_contains($r['body'], 'rel="prev" href="board.php?a=2&amp;page=2"'), 'last page with 4 messages');
ok(str_contains($r['body'], '<link rel="canonical" href="' . $B . 'board.php?a=2&amp;page=3">'), 'canonical with page');
foreach (['board.php?a=2&page=4', 'board.php?a=2&page=0', 'board.php?a=2&page=x', 'board.php?a=3', 'board.php?a=99', 'board.php?a=1x', 'board.php?a=-1', 'board.php?a[]=1'] as $u) {
    ok(get($w, $u)['code'] === 404, "$u: 404");
}
$r = get($w, 'board.php?a=1');
ok(preg_match('~href="board.php\?m=' . $first . '">Hallo zusammen</a><span class="from">Regina</span><span class="date"><time [^>]+>[0-9.]+ [0-9:]+</time></span><span class="rep" title="Antworten">1<~', $r['body']) === 1, 'row: subject, handle, date, replies');

echo "== message\n";
$r = get($w, "board.php?m=$first");
ok($r['code'] === 200 && noWarn($r), 'message 200');
ok(str_contains($r['text'], 'Hallo zusammen') && str_contains($r['text'], 'Hallo an alle!') && str_contains($r['text'], 'Regina aus Testhausen'), 'subject, text, signature');
ok(str_contains($r['body'], '<pre class="msgbody">') && str_contains($r['body'], '<time datetime='), 'body block and date');
ok(str_contains($r['text'], 'Antworten auf diese Nachricht') && str_contains($r['body'], "href=\"board.php?m=$reply\""), 'replies listed with link');
ok(str_contains($r['body'], "href=\"./#msg/$first\""), 'call link with direct dial');
ok(str_contains($r['body'], '<meta name="description" content="Hallo zusammen: Hallo an alle! Ich bin neu hier und freue mich.'), 'description from subject and text');
ok(str_contains($r['body'], '<title>Hallo zusammen · Allgemein · Die Ölkanne! BBS</title>'), 'title');
$r = get($w, "board.php?m=$reply");
ok(str_contains($r['text'], 'Antwort auf: Hallo zusammen') && str_contains($r['body'], "href=\"board.php?m=$first\""), 'reply links its original');
ok(str_contains($r['body'], '<span class="q">RE&gt; Hallo an alle!</span>'), 'quoted line marked');
$r = get($w, "board.php?m=$pipe");
ok(str_contains($r['body'], 'Pipe |12rot|07 und &lt;script&gt;alert(1)&lt;/script&gt; und &amp;amp; bleiben Text.'), 'pipe codes and HTML stay text');
ok(!str_contains($r['body'], '<script>') && !str_contains($r['body'], '<b>fett'), 'no HTML from the message');
ok(str_contains($r['body'], '<h1>Farben |14gelb|07 und &lt;b&gt;fett&lt;/b&gt;</h1>'), 'subject escaped');
ok(str_contains($r['body'], '<title>Farben gelb und &lt;b&gt;fett&lt;/b&gt; ·') && str_contains($r['body'], 'content="Farben gelb und &lt;b&gt;fett&lt;/b&gt;: Pipe rot und'), 'title and description without pipe codes');
ok(str_contains($r['body'], str_repeat('x', 79) . "\n" . str_repeat('x', 21)), 'long line wrapped at 79 like in the box');

echo "== never public\n";
$tmp = (int)val('SELECT MAX(id) FROM {messages}') + 1;
foreach (["board.php?m=$priv" => 'private mail', "board.php?m=$secret" => 'area not public', "board.php?m=$tmp" => 'unknown id',
             'board.php?m=abc' => 'letters', 'board.php?m=0' => 'zero', 'board.php?m=' . $first . 'x' => 'id with letters', 'board.php?m=' => 'empty',
             'board.php?m[]=' . $first => 'array'] as $u => $what) {
    ok(get($w, $u)['code'] === 404, "$what: 404");
}
// a public message deleted: gone at once
q("INSERT INTO {messages} (area_id, from_id, from_name, to_name, subject, body, posted, msgid) VALUES (1, 2, 'Regina', 'Alle', 'Gleich weg', 'x', ?, ?)", [time(), 'tmp.' . bin2hex(random_bytes(4))]);
$gone = (int)tdb()->lastInsertId();
ok(get($w, "board.php?m=$gone")['code'] === 200, 'new message visible');
q('DELETE FROM {messages} WHERE id=?', [$gone]);
ok(get($w, "board.php?m=$gone")['code'] === 404, 'deleted message: 404 at once');
// moved into a closed area
q('UPDATE {messages} SET area_id=3 WHERE id=?', [$pipe]);
ok(get($w, "board.php?m=$pipe")['code'] === 404, 'moved into a closed area: 404');
q('UPDATE {messages} SET area_id=1 WHERE id=?', [$pipe]);
// public flag set but read level above new users (changed outside the backend): not shown
q('UPDATE {msg_areas} SET read_level=20 WHERE id=2');
ok(get($w, 'board.php?a=2')['code'] === 404 && !str_contains(get($w, 'board.php')['text'], 'Technik'), 'read level above new users: not shown');
q('UPDATE {msg_areas} SET read_level=10 WHERE id=2');
// locked writer stays
q("UPDATE {users} SET locked=1 WHERE handle='Peter'");
ok(get($w, "board.php?m=$reply")['code'] === 200, 'message of a locked user stays');
q("UPDATE {users} SET locked=0 WHERE handle='Peter'");

echo "== hidden handle\n";
q("UPDATE {users} SET web_anon=1 WHERE handle='Peter'");
$r = get($w, "board.php?m=$reply");
ok(preg_match('/<p class="meta">Anrufer, /', $r['body']) === 1 && !str_contains($r['text'], 'Peter'), 'message: "Anrufer" instead of the handle');
ok(str_contains($r['text'], 'Regina schrieb:'), 'quotes stay as written');
$r = get($w, "board.php?m=$first");
ok(str_contains($r['text'], 'Re: Hallo zusammen Anrufer,') || preg_match('/Re: Hallo zusammen<\/a> <span class="meta">Anrufer,/', $r['body']) === 1, 'replies list: "Anrufer"');
$r = get($w, 'board.php?a=2');
ok(!str_contains($r['text'], 'Peter') && str_contains($r['text'], 'Anrufer'), 'board list: "Anrufer"');
$r = get($w, 'board.php');
ok(!str_contains($r['text'], 'Peter'), 'overview: "Anrufer"');
q("UPDATE {users} SET web_anon=0 WHERE handle='Peter'");

echo "== search engines\n";
$r = get($w, 'board.php?sitemap=1');
ok($r['code'] === 200 && str_contains($r['head'], 'Content-Type: application/xml'), 'sitemap 200 as XML');
$x = @simplexml_load_string($r['body']);
ok($x !== false, 'sitemap is valid XML');
$locs = $x ? array_map('strval', iterator_to_array($x->url->loc ?? [], false)) : [];
$locs = [];
foreach ($x->url as $u) {
    $locs[] = (string)$u->loc;
}
$pub = (int)val('SELECT COUNT(*) FROM {messages} WHERE private=0 AND area_id IN (1,2)');
ok(count($locs) === 1 + 2 + $pub, 'overview, 2 boards and all ' . $pub . ' public messages');
ok(in_array("{$B}board.php?m=$first", $locs, true) && !in_array("{$B}board.php?m=$priv", $locs, true)
    && !in_array("{$B}board.php?m=$secret", $locs, true), 'no private or closed messages');
setting('public_index', '0');
$r = get($w, "board.php?m=$first");
ok(str_contains($r['body'], '<meta name="robots" content="noindex, follow">'), 'switch off: noindex, follow');
ok(get($w, 'board.php?sitemap=1')['code'] === 404, 'switch off: no sitemap');
setting('public_index', '1');
ok(str_contains(get($w, 'board.php?a=1')['body'], 'content="index, follow"'), 'switch on again: index');
// at most 5000 messages
$pdo = tdb();
$pdo->beginTransaction();
$st = $pdo->prepare("INSERT INTO cb_messages (area_id, from_id, from_name, to_name, subject, body, posted, msgid) VALUES (2, 2, 'Regina', 'Alle', ?, 'x', ?, ?)");
for ($i = 0; $i < 5100; $i++) {
    $st->execute(["Masse $i", time(), 'mass.' . $i . '.' . bin2hex(random_bytes(3))]);
}
$pdo->commit();
$r = get($w, 'board.php?sitemap=1');
$x = simplexml_load_string($r['body']);
$msgs = 0;
$newest = false;
foreach ($x->url as $u) {
    $msgs += str_contains((string)$u->loc, '?m=') ? 1 : 0;
    $newest = $newest || str_ends_with((string)$u->loc, '?m=' . (int)val('SELECT MAX(id) FROM {messages}'));
}
ok($msgs === 5000 && $newest, 'sitemap: the newest 5000 messages');
q("DELETE FROM {messages} WHERE msgid LIKE 'mass.%'");

echo "== backend\n";
$s = backend('Sysop', 'geheim123');
$r = $s->req('sysop/index.php?p=msgareas');
ok(str_contains($r['body'], 'name="a[3][public]" value="1" disabled') && str_contains($r['body'], 'Nur für Bereiche, die neue User lesen dürfen (Leselevel 10 oder niedriger).'), 'checkbox greyed out for area 3 with the reason');
ok(str_contains($r['body'], 'name="a[1][public]" value="1" checked') && str_contains($r['body'], 'Im Web öffentlich lesbar'), 'checkbox for area 1');
ok(str_contains($r['body'], 'Die Nachrichten dieses Bretts erscheinen mit Handle, Datum und Text als Webseite, die jeder ohne Anruf lesen kann.'), 'explanation');
ok(str_contains($r['body'], 'data-copy="' . $B . '#area/2"') && str_contains($r['body'], 'Link kopieren'), 'copy link per area');
$rows = [];
foreach (q('SELECT * FROM {msg_areas} ORDER BY id')->fetchAll() as $a) {
    $rows['a[' . $a['id'] . '][name]'] = $a['name'];
    $rows['a[' . $a['id'] . '][description]'] = $a['description'];
    $rows['a[' . $a['id'] . '][read_level]'] = $a['read_level'];
    $rows['a[' . $a['id'] . '][write_level]'] = $a['write_level'];
    $rows['a[' . $a['id'] . '][sort]'] = $a['sort'];
    if ((int)$a['public'] === 1) {
        $rows['a[' . $a['id'] . '][public]'] = '1';
    }
}
$logBefore = (int)val('SELECT MAX(id) FROM {log}');
// raise the read level of area 2, its box stays ticked
$post = $rows;
$post['a[2][read_level]'] = '20';
$post['csrf'] = $s->csrf;
$r = $s->req('sysop/index.php?p=msgareas', null, $post);
ok((int)val('SELECT public FROM {msg_areas} WHERE id=2') === 0, 'read level raised: public off');
ok((bool)val("SELECT COUNT(*) FROM {log} WHERE id>? AND text='Area 2 no longer public on the web, new users cannot read it'", [$logBefore]), 'log entry for it');
// try to switch on area 3 (read 100) by a forged form
$post = $rows;
$post['a[2][read_level]'] = '10';
$post['a[3][public]'] = '1';
$post['csrf'] = $s->csrf;
$s->req('sysop/index.php?p=msgareas', null, $post);
ok((int)val('SELECT public FROM {msg_areas} WHERE id=3') === 0 && (int)val('SELECT public FROM {msg_areas} WHERE id=2') === 1, 'area 3 cannot be made public, area 2 public again');
ok((bool)val("SELECT COUNT(*) FROM {log} WHERE id>? AND text='Area 2 public on the web'", [$logBefore]), 'log entry for switching on');
// level of new users lowered in the settings: area 2 (read 10) ends
$r = $s->req('sysop/index.php?p=settings');
preg_match_all('/<(?:input|select)[^>]*name="([a-z_]+)"[^>]*>/', $r['body'], $m);
ok(str_contains($r['body'], 'name="bbs_url" value="' . $B . '"'), 'address of the board suggested from the request');
ok(str_contains($r['body'], 'Öffentliche Bretter für Suchmaschinen') && str_contains($r['body'], 'board.php?sitemap=1'), 'search engine switch and sitemap address');
function settingsForm(string $html): array
{
    $f = [];
    preg_match_all('/<input([^>]*)>/', $html, $m);
    foreach ($m[1] as $a) {
        if (!preg_match('/name="([a-z_]+)"/', $a, $n) || $n[1] === 'csrf') {
            continue;
        }
        if (str_contains($a, 'type="checkbox"')) {
            if (str_contains($a, ' checked')) {
                $f[$n[1]] = 'on';
            }
        } elseif (preg_match('/value="([^"]*)"/', $a, $v)) {
            $f[$n[1]] = html_entity_decode($v[1], ENT_QUOTES);
        }
    }
    preg_match_all('/<select name="([a-z_]+)">(.*?)<\/select>/s', $html, $m, PREG_SET_ORDER);
    foreach ($m as $sel) {
        if (preg_match('/<option value="([^"]*)" selected>/', $sel[2], $o)) {
            $f[$sel[1]] = html_entity_decode($o[1], ENT_QUOTES);
        }
    }
    return $f;
}
$form = settingsForm($r['body']);
$form['new_level'] = '5';
$form['bbs_url'] = 'http://box.example.org/';
$form['csrf'] = $s->csrf;
$logBefore = (int)val('SELECT MAX(id) FROM {log}');
$r = $s->req('sysop/index.php?p=settings', null, $form);
ok((int)val('SELECT public FROM {msg_areas} WHERE id=2') === 0 && (int)val('SELECT public FROM {msg_areas} WHERE id=1') === 0, 'new level lowered: public areas end');
ok((int)val("SELECT COUNT(*) FROM {log} WHERE id>? AND text LIKE 'Area % no longer public on the web, new users cannot read it'", [$logBefore]) === 2, 'log entries for both');
ok(val("SELECT value FROM {settings} WHERE name='bbs_url'") === '', 'http address refused, old (empty) one kept');
$form['new_level'] = '10';
$form['bbs_url'] = 'https://Box.Example.org/bbs';
$s->req('sysop/index.php?p=settings', null, $form);
ok(val("SELECT value FROM {settings} WHERE name='bbs_url'") === 'https://box.example.org/bbs/', 'https address stored with a slash');
ok((bool)val("SELECT COUNT(*) FROM {log} WHERE text='Address of the board set to https://box.example.org/bbs/'"), 'log entry for the address');
q('UPDATE {msg_areas} SET public=1 WHERE id IN (1, 2)');
$r = $s->req('sysop/index.php?p=messages&view=' . $first);
ok(str_contains($r['body'], 'data-copy="https://box.example.org/bbs/#msg/' . $first . '"') && str_contains($r['body'], 'href="../board.php?m=' . $first . '"'), 'message view: copy link with the address and web page');
$r = get($w, "board.php?m=$first");
ok(str_contains($r['body'], 'href="https://box.example.org/bbs/board.php?m=' . $first . '"'), 'canonical uses the address of the board');
foreach (['ftp://x.org/', 'https://x.org/?a=1', 'https://x.org/#x', 'javascript:alert(1)', 'https://user@x.org/', '' . $B . ''] as $bad) {
    $form['bbs_url'] = $bad;
    $s->req('sysop/index.php?p=settings', null, $form);
    $now = val("SELECT value FROM {settings} WHERE name='bbs_url'");
    ok($bad === '' . $B . '' ? $now === '' . $B . '' : $now !== $bad, 'address ' . $bad . ($bad === '' . $B . '' ? ' allowed (local)' : ' refused'));
}
setting('bbs_url', '');
$r = $s->req('sysop/index.php?p=msgareas');
ok(noWarn($r) && str_contains($r['body'], 'Öffentliche Bretter im Web:'), 'areas page with the address of the public boards');
// export of a user contains the setting
$r = $s->req('sysop/index.php?p=user&id=' . (int)val("SELECT id FROM {users} WHERE handle='Peter'") . '&export=1');

echo "== limit\n";
$codes = [];
for ($i = 0; $i < 125; $i++) {
    $codes[] = get($w, 'board.php?a=1')['code'];
}
ok(in_array(429, $codes, true) && $codes[0] === 200, 'many requests in a minute: 429');
$log = file_get_contents(getenv('CB_LOG'));
ok(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'no PHP warnings in the server log');
summary();
