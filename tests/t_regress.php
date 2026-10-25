<?php
// Regression: every menu command of the terminal and every backend page, no PHP warnings, no lost line.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
setting('baud', '0');
q('DELETE FROM {nodes}');
mkuser('Regina', 'regina12', 10);

function home(TC $t): bool
{
    for ($i = 0; $i < 12; $i++) {
        if (!empty($t->last['hang'])) {
            return false;
        }
        $a = $t->ask ?? [];
        $k = (string)($a['k'] ?? '');
        if (($a['t'] ?? '') === 'hot' && str_contains($k, '?') && str_contains($k, 'G')) {
            return true;
        }
        $v = match ($a['t'] ?? '') {
            'hot' => $k === '' ? "\r" : (str_contains($k, 'Q') ? 'Q' : (str_contains($k, 'N') ? 'N' : (str_contains($k, "\r") ? "\r" : $k[0]))),
            'line' => !empty($a['x']) ? "\x1b" : '',
            'edit' => "\x00",
            'upload' => 'Q',
            'chat' => '/q',
            default => "\r",
        };
        $t->in($v);
    }
    return false;
}

function warnings(TC $t, string $o): bool
{
    return (bool)preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)/', $o);
}

foreach ([['Regina', 'regina12'], ['Sysop', 'geheim123']] as [$h, $p]) {
    echo "== terminal as $h\n";
    $t = new TC();
    $t->login($h, $p);
    $main = (string)($t->ask['k'] ?? '');
    foreach (str_split(str_replace(['?', 'G'], '', $main)) as $key) {
        $r = $t->in($key);
        $bad = warnings($t, $r['o'] ?? '');
        // one level deeper in sub menus
        $sub = (string)($t->ask['k'] ?? '');
        if (($t->ask['t'] ?? '') === 'hot' && str_contains($sub, '?') && str_contains($sub, 'Q') && !str_contains($sub, 'G')) {
            foreach (str_split(str_replace(['?', 'Q', "\r"], '', $sub)) as $sk) {
                $r = $t->in($sk);
                $bad = $bad || warnings($t, $r['o'] ?? '');
                for ($i = 0; $i < 8; $i++) {
                    $k2 = (string)($t->ask['k'] ?? '');
                    if (!empty($t->last['hang']) || ($k2 === $sub && ($t->ask['t'] ?? '') === 'hot')) {
                        break;
                    }
                    $a = $t->ask ?? [];
                    $t->in(match ($a['t'] ?? '') {
                        'hot' => $k2 === '' ? "\r" : (str_contains($k2, 'Q') ? 'Q' : (str_contains($k2, 'N') ? 'N' : "\r")),
                        'line' => !empty($a['x']) ? "\x1b" : '', 'edit' => "\x00", 'upload' => 'Q', 'chat' => '/q', default => "\r",
                    });
                    $bad = $bad || warnings($t, $t->last['o'] ?? '');
                }
            }
        }
        $back = home($t);
        ok(!$bad && $back, "command $key: no warning, back in the main menu");
    }
    // a message written and read again
    $t->in('M');
    $t->in('E');
    $t->in('');
    $t->in('Regressionstest ' . $h);
    $r = $t->in("Text der Nachricht von $h\nzweite Zeile");
    home($t);
    ok((int)val('SELECT COUNT(*) FROM {messages} WHERE subject=?', ['Regressionstest ' . $h]) === 1, 'message saved through the editor');
    $t->in('G');
    $r = $t->in('J');
    ok(!empty($r['hang']), 'logoff');
}

echo "== backend pages\n";
global $pages;
$src = file_get_contents(getenv('CB_ROOT') . '/sysop/index.php');
preg_match_all("/'([a-z]+)' => \['?[^,]+', 'page_[a-z]+', '(mod|full)'\]/", $src, $m, PREG_SET_ORDER);
$s = backend('Sysop', 'geheim123');
$mo = backend('Moni', 'moni1234');
foreach ($m as [, $page, $role]) {
    if (in_array($page, ['status', 'chatpoll', 'autobackup', 'hashstep', 'wansidraw', 'logout'], true)) {
        continue;
    }
    $r = $s->req('sysop/index.php?p=' . $page);
    ok($r['code'] === 200 && !warnings($s, $r['body']) && str_contains($r['body'], '</html>'), "full: $page");
    if ($role === 'mod') {
        $r = $mo->req('sysop/index.php?p=' . $page . ($page === 'users' ? '&filter=pending' : ''));
        ok($r['code'] === 200 && !warnings($mo, $r['body']), "moderator: $page");
    }
}
foreach (['status', 'chatpoll', 'hashstep', 'autobackup'] as $json) {
    $r = $s->req('sysop/index.php?p=' . $json, null, ['csrf' => $s->csrf]);
    ok(is_array(json_decode($r['body'], true)), "full: $json answers with JSON");
}
summary();
