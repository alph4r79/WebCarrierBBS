<?php
/**
 * Sysop backend: overview, settings, legal texts, oneliners, doors and log.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

function page_dash(array $admin): void
{
    $day = strtotime('today');
    $stats = [
        [DB::val('SELECT COUNT(*) FROM {users}'), t('Users')],
        [DB::val('SELECT COUNT(*) FROM {calls} WHERE time>=?', [$day]), t('Calls today')],
        [DB::val('SELECT COUNT(*) FROM {nodes} WHERE user_id>0'), t('Online now')],
        [DB::val('SELECT COUNT(*) FROM {messages} WHERE private=0'), t('Public messages')],
        [DB::val('SELECT COUNT(*) FROM {files} WHERE approved=1'), t('Files')],
        [DB::val('SELECT COUNT(*) FROM {files} WHERE approved=0'), t('Uploads waiting')],
    ];
    echo '<h1>' . h(t('Overview')) . '</h1>';
    $warn = [];
    if (trim(Settings::get('legal_impressum')) === '' || trim(Settings::get('legal_privacy')) === '') {
        $warn[] = t('Imprint or privacy policy is still empty. Fill both in before you open the board to the public.');
    }
    if (is_dir(CB_ROOT . '/install')) {
        $warn[] = t('The install folder is still on the server. You can delete it.');
    }
    if ((int)$stats[5][0] > 0) {
        $warn[] = t('{1} upload(s) waiting for your check.', $stats[5][0]);
    }
    foreach ($warn as $w) {
        echo '<div class="flash warn"><p>' . h($w) . '</p></div>';
    }
    echo '<div class="stats">';
    foreach ($stats as [$n, $label]) {
        echo '<div><b>' . (int)$n . '</b><span>' . h($label) . '</span></div>';
    }
    echo '</div>';

    echo '<h2>' . h(t('Nodes')) . '</h2><div class="tablewrap"><table><tr><th>' . h(t('Node')) . '</th><th>' . h(t('User')) .
        '</th><th>' . h(t('Activity')) . '</th><th>' . h(t('Since')) . '</th></tr>';
    $rows = DB::all('SELECT * FROM {nodes} ORDER BY node');
    if (!$rows) {
        echo '<tr><td colspan="4" class="note">' . h(t('All lines are free.')) . '</td></tr>';
    }
    foreach ($rows as $r) {
        echo '<tr><td>' . (int)$r['node'] . '</td><td>' . h($r['handle'] ?: t('(logging in)')) . '</td><td>' . h($r['activity']) .
            '</td><td>' . date('H:i', (int)$r['since']) . '</td></tr>';
    }
    echo '</table></div>';

    echo '<h2>' . h(t('Latest events')) . '</h2><div class="tablewrap"><table>';
    foreach (DB::all('SELECT * FROM {log} ORDER BY id DESC LIMIT 15') as $l) {
        echo '<tr><td class="num">' . date('d.m. H:i', (int)$l['time']) . '</td><td>' . h($l['handle']) . '</td><td>' . h($l['text']) . '</td></tr>';
    }
    echo '</table></div>';
}

function a_languages(): array
{
    $names = ['de' => 'Deutsch', 'en' => 'English'];
    $out = [];
    foreach (glob(CB_ROOT . '/lang/*.php') ?: [] as $f) {
        $c = basename($f, '.php');
        if (preg_match('/^[a-z]{2,5}$/', $c)) {
            $out[$c] = $names[$c] ?? $c;
        }
    }
    return $out;
}

function page_settings(array $admin): void
{
    $fields = [
        t('General') => [
            ['bbs_name', t('Name of the BBS'), 'text', ''],
            ['sysop_name', t('Sysop name shown to callers'), 'text', ''],
            ['bbs_location', t('Location'), 'text', ''],
            ['language', t('Language'), 'select', a_languages()],
            ['timezone', t('Time zone'), 'text', t('For example Europe/Berlin')],
        ],
        t('Access') => [
            ['max_nodes', t('Nodes (callers at the same time)'), 'number', t('When all nodes are busy, callers get "All lines are busy".')],
            ['idle_minutes', t('Hang up after minutes without input'), 'number', ''],
            ['allow_new', t('New users may register'), 'bool', ''],
            ['new_level', t('Level for new users'), 'level', ''],
            ['sysop_level', t('Level with sysop rights'), 'number', t('Users with this level or higher may enter this backend.')],
            ['logon_oneliners', t('Show oneliners after login'), 'bool', ''],
            ['max_msg_lines', t('Maximum lines per message'), 'number', ''],
        ],
        t('Terminal') => [
            ['baud', t('Default modem speed'), 'select', ['0' => t('Off (full speed)'), '300' => '300', '1200' => '1200', '2400' => '2400',
                '9600' => '9600', '14400' => '14400', '28800' => '28800', '57600' => '57600']],
            ['sound', t('Modem and bell sounds'), 'bool', ''],
            ['show_footer', t('Show imprint and privacy links below the terminal'), 'bool', t('Recommended in Germany: the imprint must be reachable directly.')],
            ['noindex', t('Ask search engines not to index the BBS'), 'bool', ''],
        ],
        t('Uploads') => [
            ['upload_max_kb', t('Maximum upload size in KB'), 'number', t('The PHP limit of your webspace is {1}.', cb_kb(Engine::uploadMaxBytes()))],
            ['upload_ext', t('Allowed file types'), 'text', t('Comma separated, for example zip,arj,lzh,txt')],
            ['upload_auto_approve', t('Uploads are online without your check'), 'bool', ''],
        ],
    ];
    if (a_post()) {
        foreach ($fields as $group) {
            foreach ($group as [$k, , $type]) {
                if ($type === 'bool') {
                    Settings::set($k, isset($_POST[$k]) ? '1' : '0');
                } elseif ($type === 'number' || $type === 'level') {
                    Settings::set($k, (string)a_int($k, 0, 99999));
                } else {
                    Settings::set($k, a_in($k, 200));
                }
            }
        }
        if (!in_array(Settings::get('timezone'), timezone_identifiers_list(), true)) {
            Settings::set('timezone', 'Europe/Berlin');
            a_flash(t('Unknown time zone, Europe/Berlin is used.'), 'warn');
        }
        // 0 = everybody is sysop, above the admin's level = admin locked out of the backend
        $sl = Settings::int('sysop_level', 255);
        if ($sl < 1 || $sl > (int)$admin['level']) {
            Settings::set('sysop_level', (string)(int)$admin['level']);
            a_flash(t('The sysop level must be between 1 and your own level ({1}). It was set to {1}.', (int)$admin['level']), 'warn');
        }
        if (Settings::int('new_level', 10) >= Settings::int('sysop_level', 255)) {
            $nl = min(10, Settings::int('sysop_level', 255) - 1);
            Settings::set('new_level', (string)$nl);
            a_flash(t('New users would get sysop rights with this level. The level for new users was set to {1}.', $nl), 'warn');
        }
        Settings::set('max_nodes', (string)max(1, min(99, Settings::int('max_nodes', 4))));
        Settings::set('idle_minutes', (string)max(1, min(120, Settings::int('idle_minutes', 5))));
        a_flash(t('Settings saved.'));
        a_go('settings');
    }
    echo '<h1>' . h(t('Settings')) . '</h1><form method="post" class="form">' . a_csrf();
    foreach ($fields as $title => $group) {
        echo '<section class="panel"><h2>' . h($title) . '</h2><div class="grid2">';
        foreach ($group as [$k, $label, $type, $extra]) {
            $v = Settings::get($k);
            if ($type === 'bool') {
                echo '<label class="inline"><input type="checkbox" name="' . h($k) . '"' . ($v === '1' ? ' checked' : '') . '> ' . h($label) . '</label>';
            } elseif ($type === 'select') {
                echo '<label>' . h($label) . '<select name="' . h($k) . '">';
                foreach ($extra as $ov => $ol) {
                    echo '<option value="' . h((string)$ov) . '"' . ((string)$ov === $v ? ' selected' : '') . '>' . h($ol) . '</option>';
                }
                echo '</select></label>';
            } elseif ($type === 'level') {
                echo '<label>' . h($label) . a_level_select($k, (int)$v) . '</label>';
            } else {
                echo '<label>' . h($label) . '<input type="' . ($type === 'number' ? 'number' : 'text') . '" name="' . h($k) . '" value="' . h($v) . '">' .
                    ($extra !== '' ? '<span class="hint">' . h($extra) . '</span>' : '') . '</label>';
            }
        }
        echo '</div></section>';
    }
    echo '<p><button class="btn" type="submit">' . h(t('Save settings')) . '</button></p></form>';
}

function page_legal(array $admin): void
{
    if (a_post()) {
        Settings::set('legal_impressum', a_in('legal_impressum', 20000));
        Settings::set('legal_privacy', a_in('legal_privacy', 60000));
        a_flash(t('Texts saved.'));
        a_go('legal');
    }
    echo '<h1>' . h(t('Imprint and privacy')) . '</h1>';
    echo '<p class="note">' . h(t('Callers see these texts in the terminal (menu commands LEGAL impressum and LEGAL privacy) and as plain web pages linked below the terminal. Plain text, no HTML.')) . '</p>';
    echo '<form method="post" class="form panel">' . a_csrf();
    echo '<label>' . h(t('Imprint')) . '<textarea name="legal_impressum" class="mono">' . h(Settings::get('legal_impressum')) . '</textarea></label>';
    echo '<label>' . h(t('Privacy policy')) . '<textarea name="legal_privacy" class="mono" style="min-height:360px">' . h(Settings::get('legal_privacy')) . '</textarea></label>';
    echo '<p><button class="btn" type="submit">' . h(t('Save texts')) . '</button></p></form>';
}

function page_oneliners(array $admin): void
{
    if (a_post() && isset($_POST['del'])) {
        DB::q('DELETE FROM {oneliners} WHERE id=?', [(int)$_POST['del']]);
        a_flash(t('Oneliner deleted.'));
        a_go('oneliners');
    }
    echo '<h1>' . h(t('Oneliners')) . '</h1><div class="tablewrap"><table><tr><th>' . h(t('Date')) . '</th><th>' . h(t('User')) .
        '</th><th>' . h(t('Text')) . '</th><th></th></tr>';
    foreach (DB::all('SELECT * FROM {oneliners} ORDER BY id DESC LIMIT 200') as $o) {
        echo '<tr><td class="num">' . date('d.m.y H:i', (int)$o['time']) . '</td><td>' . h($o['handle']) . '</td><td>' . h($o['text']) .
            '</td><td><form method="post"' . a_confirm(t('Delete this oneliner?')) . '>' . a_csrf() .
            '<button class="btn small danger ghost" name="del" value="' . (int)$o['id'] . '">' . h(t('Delete')) . '</button></form></td></tr>';
    }
    echo '</table></div>';
}

function page_doors(array $admin): void
{
    echo '<h1>' . h(t('Doors')) . '</h1>';
    echo '<p class="note">' . h(t('Doors are small programs in the doors folder. To offer a door, add a menu item with the command DOOR and the door id as data.')) . '</p>';
    echo '<div class="tablewrap"><table><tr><th>' . h(t('Id')) . '</th><th>' . h(t('Name')) . '</th><th>' . h(t('Description')) . '</th><th>' . h(t('In a menu')) . '</th></tr>';
    foreach (cb_doors() as $d) {
        $used = (int)DB::val("SELECT COUNT(*) FROM {menu_items} WHERE command='DOOR' AND data=?", [$d['id']]);
        echo '<tr><td><code>' . h($d['id']) . '</code></td><td>' . h($d['name']) . '</td><td>' . h($d['description'] ?? '') . '</td><td>' .
            ($used ? '<span class="tag ok">' . h(t('yes')) . '</span>' : '<span class="tag">' . h(t('no')) . '</span>') . '</td></tr>';
    }
    echo '</table></div>';
}

function page_log(array $admin): void
{
    if (a_post() && isset($_POST['clear'])) {
        DB::q('DELETE FROM {log}');
        a_flash(t('Log cleared.'));
        a_go('log');
    }
    echo '<h1>' . h(t('Log')) . '</h1>';
    echo '<form method="post"' . a_confirm(t('Clear the whole log?')) . '>' . a_csrf() . '<p><button class="btn small danger ghost" name="clear" value="1">' .
        h(t('Clear log')) . '</button></p></form>';
    echo '<div class="tablewrap"><table><tr><th>' . h(t('Date')) . '</th><th>' . h(t('User')) . '</th><th>' . h(t('Event')) . '</th></tr>';
    foreach (DB::all('SELECT * FROM {log} ORDER BY id DESC LIMIT 300') as $l) {
        echo '<tr><td class="num">' . date('d.m.y H:i:s', (int)$l['time']) . '</td><td>' . h($l['handle']) . '</td><td>' . h($l['text']) . '</td></tr>';
    }
    echo '</table></div>';
}
