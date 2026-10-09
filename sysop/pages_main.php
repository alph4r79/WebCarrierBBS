<?php
/**
 * Sysop backend: overview, settings, legal texts, oneliners, doors and log.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Text for an update error code from core/update.php. */
function a_update_reason(string $code, array $args): string
{
    $a = (string)($args[0] ?? '');
    return match ($code) {
        'locked' => t('Another update is running right now.'),
        'none' => t('There is no update to install.'),
        'blocked' => t('This update can only be installed by hand.'),
        'key' => t('The key to check the update signature is missing.'),
        'write' => t('{1} is not writable.', $a),
        'space' => t('There is not enough free space in data/tmp.'),
        'backup' => t('The backup before the update failed: {1}', $a),
        'download' => t('The update could not be downloaded.'),
        'size' => t('The downloaded file has the wrong size.'),
        'sha256' => t('The checksum of the downloaded file is wrong.'),
        'signature' => t('The signature of the update is invalid.'),
        'zip' => t('The update archive is damaged or contains invalid paths.'),
        'structure' => t('The update archive does not contain WebCarrier BBS.'),
        'version' => t('The archive contains version {1} instead of the announced one.', $a),
        'copy' => t('{1} could not be written.', $a),
        default => t('Unexpected error: {1}', $a),
    };
}

/** One sentence why an update can only be installed by hand. */
function a_update_blocker(string $code, string $arg): string
{
    return match ($code) {
        'php' => t('This version needs PHP {1} or newer, your webspace has PHP {2}.', $arg, PHP_VERSION),
        'manual' => t('This version has to be installed by hand.'),
        'zip' => t('The PHP extension ZipArchive is missing.'),
        'sodium' => t('The PHP extension sodium is missing, so the signature of the update cannot be checked.'),
        'git' => t('This installation is a Git checkout, update it with Git.'),
        default => t('{1} is not writable for PHP.', $arg),
    };
}

function a_dismissed(): array
{
    $d = json_decode(Settings::get('notices_dismissed'), true);
    return is_array($d) ? $d : [];
}

/**
 * Current notices, most important first. Each: id, kind (security, task, update, info, news),
 * title, text, optional items (list of lines) and actions: ['link', label, url] or ['post', label, field, value, confirm].
 */
function a_dash_notices(): array
{
    $n = [];
    if (is_dir(CB_ROOT . '/install')) {
        $n[] = ['id' => 'install', 'kind' => 'security', 'title' => t('The install folder is still on the server'),
            'text' => t('Delete the folder install from your webspace. As long as it exists, it is a needless risk.')];
    }
    $stale = json_decode(Settings::get('update_stale'), true);
    if (is_array($stale)) {
        $n[] = ['id' => 'stale', 'kind' => 'security', 'title' => t('An aborted update was cleaned up'),
            'text' => t('The maintenance mode of an update was older than 15 minutes and has been ended, the old files were restored.') .
                (!empty($stale['restore_failed']) ? ' ' . t('Not restored: {1}', implode(', ', $stale['restore_failed'])) : '')];
    }
    if (trim(Settings::get('legal_impressum')) === '' || trim(Settings::get('legal_privacy')) === '') {
        $n[] = ['id' => 'legal', 'kind' => 'task', 'title' => t('Imprint or privacy policy is empty'),
            'text' => t('Fill in both before you open the board to the public.'), 'actions' => [['link', t('Imprint and privacy'), a_url('legal')]]];
    }
    $waiting = (int)DB::val('SELECT COUNT(*) FROM {users} WHERE pending=1');
    if ($waiting > 0) {
        $n[] = ['id' => 'validate', 'kind' => 'task', 'title' => t('{1} new user(s) waiting for validation', $waiting),
            'text' => t('They can log in, but cannot use the board until you validate them.'),
            'actions' => [['link', t('Check new users'), a_url('users', ['filter' => 'pending'])]]];
    }
    $pending = (int)DB::val('SELECT COUNT(*) FROM {files} WHERE approved=0');
    if ($pending > 0) {
        $n[] = ['id' => 'uploads', 'kind' => 'task', 'title' => t('{1} upload(s) waiting for your check', $pending),
            'text' => t('Uploads are only visible to callers after you approved them.'), 'actions' => [['link', t('Check uploads'), a_url('files')]]];
    }
    $broken = cb_door_registry()['errors'];
    if ($broken) {
        $n[] = ['id' => 'doors', 'kind' => 'task', 'title' => t('{1} door file(s) could not be loaded', count($broken)),
            'text' => t('Callers do not see these doors. The doors page shows the reason.'), 'items' => array_keys($broken),
            'actions' => [['link', t('Doors'), a_url('doors')]]];
    }
    $res = json_decode(Settings::get('update_result'), true);
    if (is_array($res)) {
        if (!empty($res['ok'])) {
            $n[] = ['id' => 'result', 'kind' => 'update', 'title' => t('Update to version {1} finished', (string)$res['version']),
                'text' => t('WebCarrier BBS was updated from version {1}. A backup from before the update is in data/backups.', (string)($res['from'] ?? ''))];
        } else {
            $n[] = ['id' => 'result', 'kind' => 'update', 'title' => t('The update to version {1} failed', (string)$res['version']),
                'text' => a_update_reason((string)$res['reason'], (array)($res['args'] ?? [])) . ' ' .
                    (empty($res['restore_failed']) ? t('The old state was restored.') : t('Not restored: {1}', implode(', ', $res['restore_failed'])))];
        }
    }
    $up = cb_update_available();
    if ($up) {
        $lang = Lang::$code;
        $items = $up['changes'][$lang] ?? [];
        $items = $items ?: ($up['changes']['de'] ?? []) ?: ($up['changes']['en'] ?? []);
        $blockers = cb_update_blockers($up);
        $notice = ['id' => 'update', 'kind' => 'update', 'title' => t('Version {1} is available', $up['version']),
            'text' => t('Released on {1}. You are running version {2}.', date('d.m.Y', (int)strtotime($up['date'])), CB_VERSION), 'items' => $items];
        if ($blockers) {
            $notice['text'] .= ' ' . a_update_blocker($blockers[0][0], (string)$blockers[0][1]);
            $notice['actions'] = [['link', t('How to update by hand'), CB_SOURCE_URL . '/blob/main/docs/SYSOP_GUIDE.md#update-von-hand']];
        } else {
            $notice['actions'] = [
                ['post', t('Update now'), 'update_now', $up['version'], t('Update to version {1} now? A backup is made first, callers are disconnected during the update.', $up['version'])],
                ['post', t('Skip this version'), 'skip', $up['version'], ''],
            ];
        }
        $n[] = $notice;
    }
    $last = Settings::int('last_backup', 0);
    if ($last < time() - 30 * 86400) {
        $n[] = ['id' => 'backup-' . $last, 'kind' => 'info', 'title' => $last ? t('The last backup is older than 30 days') : t('There is no backup yet'),
            'text' => t('A backup contains the database, the configuration and the screens.'), 'actions' => [['link', t('Backup'), a_url('backup')]]];
    }
    if (Settings::get('update_check', '0') !== '1') {
        $n[] = ['id' => 'update-check-off', 'kind' => 'info', 'title' => t('The update check is switched off'),
            'text' => t('The board does not look for new versions, you can switch this on in the settings. Only the address webcarrier-bbs.de is requested, no data of the board is sent.'),
            'actions' => [['post', t('Switch on'), 'enable_check', '1', '']]];
    } else {
        foreach (cb_update_data()['notices'] ?? [] as $x) {
            $n[] = ['id' => 'news-' . $x['id'], 'kind' => 'news', 'title' => $x['title'], 'text' => $x['text'],
                'actions' => $x['url'] !== '' ? [['link', t('More'), $x['url']]] : []];
        }
    }
    $dismissed = a_dismissed();
    $n = array_values(array_filter($n, static fn($x) => !in_array($x['kind'], ['info', 'news'], true) || !in_array($x['id'], $dismissed, true)));
    $rank = ['security' => 0, 'task' => 1, 'update' => 2, 'info' => 3, 'news' => 4];
    usort($n, static fn($a, $b) => $rank[$a['kind']] <=> $rank[$b['kind']]);
    return $n;
}

function a_dash_post(array $admin): void
{
    if (isset($_POST['dismiss'])) {
        $id = (string)$_POST['dismiss'];
        foreach (a_dash_notices() as $x) {
            if ($x['id'] === $id && in_array($x['kind'], ['info', 'news'], true)) {
                $d = array_slice(array_values(array_unique(array_merge(a_dismissed(), [$id]))), -100);
                Settings::set('notices_dismissed', (string)json_encode($d));
            }
        }
    } elseif (isset($_POST['kick'])) {
        $node = (int)$_POST['kick'];
        $row = DB::row('SELECT * FROM {nodes} WHERE node=?', [$node]);
        if ($row && $row['sid'] !== session_id()) {
            DB::q('UPDATE {nodes} SET kicked=1 WHERE node=?', [$node]);
            cb_log((int)$admin['id'], $admin['handle'], 'Disconnected node ' . $node . ($row['handle'] !== '' ? ' (' . $row['handle'] . ')' : '') . ' (backend)');
            a_flash(t('Node {1} is being disconnected.', $node));
        }
    } elseif (isset($_POST['broadcast'])) {
        $text = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', a_in('text', 70)) ?? '', 0, 70);
        if ($text !== '') {
            $nodes = array_map('intval', array_column(DB::all('SELECT node FROM {nodes} WHERE sid<>?', [session_id()]), 'node'));
            foreach ($nodes as $node) {
                DB::insert('node_msgs', ['node' => $node, 'kind' => 'msg', 'from_node' => 0, 'from_handle' => $admin['handle'], 'text' => $text, 'time' => time()]);
            }
            cb_log((int)$admin['id'], $admin['handle'], 'Broadcast to ' . count($nodes) . ' node(s) (backend): ' . $text);
            a_flash(t('Message sent to {1} node(s).', count($nodes)));
        }
    } elseif (isset($_POST['enable_check'])) {
        Settings::set('update_check', '1');
        cb_update_check(true);
        a_flash(t('The update check is switched on.'));
    } elseif (isset($_POST['check_now'])) {
        cb_update_check(true);
        a_flash(Settings::get('update_status') === 'ok' ? t('Update check done.') : t('The update check failed.'), Settings::get('update_status') === 'ok' ? 'ok' : 'bad');
    } elseif (isset($_POST['skip'])) {
        $up = cb_update_available();
        if ($up && $up['version'] === (string)$_POST['skip']) {
            Settings::set('update_skip', $up['version']);
            cb_log((int)$admin['id'], $admin['handle'], 'Skipped update ' . $up['version']);
        }
    } elseif (isset($_POST['update_now'])) {
        try {
            cb_update_run((int)$admin['id'], $admin['handle']);
        } catch (CbUpdateError $e) {
            if ($e->reason === 'locked') {
                a_flash(a_update_reason('locked', []), 'bad');
            }
        }
    }
    a_go('dash');
}

/** Status of the update check for the system box. */
function a_update_status(): string
{
    if (Settings::get('update_check', '0') !== '1') {
        return t('off');
    }
    $when = Settings::int('update_last_check', 0);
    if (Settings::get('update_status') === 'failed') {
        return t('check failed ({1})', date('d.m.Y H:i', $when));
    }
    if ($up = cb_update_available()) {
        return t('version {1} available', $up['version']);
    }
    return $when ? t('last checked {1}', date('d.m.Y H:i', $when)) : t('not checked yet');
}

function page_dash(array $admin): void
{
    if (a_post()) {
        a_dash_post($admin);
    }
    cb_update_check();
    $notices = a_dash_notices();
    // one-time notices are shown once
    foreach (['update_result', 'update_stale'] as $k) {
        if (Settings::get($k) !== '') {
            Settings::set($k, '');
        }
    }

    echo '<h1>' . h(t('Overview')) . '</h1><div class="dash"><div class="dash-main">';

    echo '<section class="panel dash-notices"><h2>' . h(t('Notices')) . '</h2>';
    if (!$notices) {
        echo '<p class="note">' . h(t('Everything is fine, there are no notices.')) . '</p>';
    } else {
        $kinds = ['security' => t('Security:'), 'task' => t('Task:'), 'update' => t('Update:'), 'info' => t('Note:'), 'news' => t('News:')];
        echo '<ul class="notices">';
        foreach ($notices as $x) {
            echo '<li class="notice ' . h($x['kind']) . '"><p class="notice-title"><strong>' . h($kinds[$x['kind']]) . '</strong> ' . h($x['title']) . '</p>' .
                '<p>' . h($x['text']) . '</p>';
            if (!empty($x['items'])) {
                echo '<ul class="notice-items">';
                foreach ($x['items'] as $i) {
                    echo '<li>' . h($i) . '</li>';
                }
                echo '</ul>';
            }
            $acts = $x['actions'] ?? [];
            if (in_array($x['kind'], ['info', 'news'], true)) {
                $acts[] = ['post', t('Hide'), 'dismiss', $x['id'], ''];
            }
            if ($acts) {
                echo '<div class="row-actions">';
                foreach ($acts as $a) {
                    if ($a[0] === 'link') {
                        $ext = !str_starts_with($a[2], '?');
                        echo '<a class="btn small ghost" href="' . h($a[2]) . '"' . ($ext ? ' rel="noopener" target="_blank"' : '') . '>' . h($a[1]) . '</a>';
                    } else {
                        $cls = $a[2] === 'dismiss' ? 'linkbtn' : ($a[2] === 'update_now' || $a[2] === 'enable_check' ? 'btn small' : 'btn small ghost');
                        echo '<form method="post" action="' . h(a_url('dash')) . '"' . ($a[4] !== '' ? a_confirm($a[4]) : '') . '>' . a_csrf() .
                            '<button class="' . $cls . '" name="' . h($a[2]) . '" value="' . h($a[3]) . '">' . h($a[1]) . '</button></form>';
                    }
                }
                echo '</div>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '</section>';

    echo '<section class="panel dash-nodes"><h2>' . h(t('Nodes')) . '</h2><div class="tablewrap"><table><tr><th>' . h(t('Node')) . '</th><th>' . h(t('User')) .
        '</th><th>' . h(t('Activity')) . '</th><th>' . h(t('Since')) . '</th><th></th></tr>';
    $rows = DB::all('SELECT * FROM {nodes} ORDER BY node');
    if (!$rows) {
        echo '<tr><td colspan="5" class="note">' . h(t('All lines are free.')) . '</td></tr>';
    }
    foreach ($rows as $r) {
        $own = $r['sid'] === session_id();
        echo '<tr><td>' . (int)$r['node'] . '</td><td>' . h($r['handle'] ?: t('(logging in)')) . '</td><td>' . h($r['activity']) .
            '</td><td>' . date('H:i', (int)$r['since']) . '</td><td>' . ($own ? '<span class="note">' . h(t('you')) . '</span>' :
            '<form method="post" action="' . h(a_url('dash')) . '"' . a_confirm(t('Disconnect node {1}?', (int)$r['node'])) . '>' . a_csrf() .
            '<button class="btn small danger ghost" name="kick" value="' . (int)$r['node'] . '">' . h(t('Disconnect')) . '</button></form>') . '</td></tr>';
    }
    echo '</table></div>';
    if ($rows) {
        echo '<form method="post" action="' . h(a_url('dash')) . '" class="broadcast">' . a_csrf() .
            '<label for="bc-text">' . h(t('Broadcast to all nodes')) . '</label><div class="broadcast-row">' .
            '<input id="bc-text" name="text" maxlength="70" required><button class="btn small" name="broadcast" value="1">' . h(t('Send')) . '</button></div></form>';
    }
    echo '</section>';

    echo '<section class="panel dash-events"><h2>' . h(t('Latest events')) . '</h2><table class="events"><tr><th>' . h(t('Time')) . '</th><th>' .
        h(t('User')) . '</th><th>' . h(t('Event')) . '</th></tr>';
    foreach (DB::all('SELECT * FROM {log} ORDER BY id DESC LIMIT 15') as $l) {
        echo '<tr><td class="time">' . date('d.m. H:i', (int)$l['time']) . '</td><td>' . h($l['handle']) . '</td><td>' . h($l['text']) . '</td></tr>';
    }
    echo '</table></section></div><div class="dash-side">';

    $day = strtotime('today');
    $stats = [
        [(int)DB::val('SELECT COUNT(*) FROM {users}'), t('Users'), ''],
        [(int)DB::val('SELECT COUNT(*) FROM {calls} WHERE time>=?', [$day]), t('Calls today'), ''],
        [(int)DB::val('SELECT COUNT(*) FROM {nodes} WHERE user_id>0'), t('Online now'), ''],
        [(int)DB::val('SELECT COUNT(*) FROM {messages} WHERE private=0'), t('Public messages'), ''],
        [(int)DB::val('SELECT COUNT(*) FROM {files} WHERE approved=1'), t('Files'), ''],
        [(int)DB::val('SELECT COUNT(*) FROM {files} WHERE approved=0'), t('Uploads waiting'), a_url('files')],
    ];
    echo '<section class="panel dash-stats"><h2>' . h(t('Figures')) . '</h2><dl class="kv">';
    foreach ($stats as [$num, $label, $url]) {
        echo '<dt>' . h($label) . '</dt><dd>' . ($url !== '' && $num > 0 ? '<a href="' . h($url) . '">' . $num . '</a>' : $num) . '</dd>';
    }
    echo '</dl><p><a class="btn small ghost" href="' . h(a_url('stats')) . '">' . h(t('Statistics')) . '</a></p></section>';

    $last = Settings::int('last_backup', 0);
    echo '<section class="panel dash-system"><h2>' . h(t('System')) . '</h2><dl class="kv">' .
        '<dt>' . h(t('Version')) . '</dt><dd>' . h(CB_VERSION) . '</dd>' .
        '<dt>PHP</dt><dd>' . h(PHP_VERSION) . '</dd>' .
        '<dt>' . h(t('Database')) . '</dt><dd>' . (DB::$driver === 'mysql' ? 'MySQL' : 'SQLite') . '</dd>' .
        '<dt>' . h(t('Last backup')) . '</dt><dd>' . h($last ? date('d.m.Y H:i', $last) : t('never')) . '</dd>' .
        '<dt>' . h(t('Update check')) . '</dt><dd>' . h(a_update_status()) . '</dd></dl>';
    if (Settings::get('update_check', '0') === '1') {
        echo '<form method="post" action="' . h(a_url('dash')) . '">' . a_csrf() . '<button class="btn small ghost" name="check_now" value="1">' .
            h(t('Check now')) . '</button></form>';
    }
    echo '</section></div></div>';
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
            ['sysop_level', t('Level with sysop rights'), 'number', t('Users with this level or higher may enter this backend.')],
            ['logon_oneliners', t('Show oneliners after login'), 'bool', ''],
            ['max_msg_lines', t('Maximum lines per message'), 'number', ''],
        ],
        t('Registration') => [
            ['allow_new', t('New users may register'), 'bool', ''],
            ['new_level', t('Level for new users'), 'level', ''],
            ['new_validate', t('New users must be validated'), 'bool', t('New users can sign up, but only use the board after you validated them (sysop menu in the terminal or user page here).')],
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
        t('Updates') => [
            ['update_check', t('Look for updates'), 'bool', t('Once a day the board requests webcarrier-bbs.de/update.json to see if there is a new version. Nothing about your board is sent.')],
        ],
    ];
    if (a_post() && isset($_POST['check_now'])) {
        cb_update_check(true);
        a_flash(Settings::get('update_status') === 'ok' ? t('Update check done.') : t('The update check failed.'), Settings::get('update_status') === 'ok' ? 'ok' : 'bad');
        a_go('settings');
    }
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
                echo '<label class="inline"><input type="checkbox" name="' . h($k) . '"' . ($v === '1' ? ' checked' : '') . '> ' . h($label) . '</label>' .
                    ($extra !== '' ? '<p class="hint">' . h($extra) . '</p>' : '');
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
        $waiting = (int)DB::val('SELECT COUNT(*) FROM {users} WHERE pending=1');
        if ($title === t('Registration') && $waiting > 0 && Settings::get('new_validate', '0') !== '1') {
            echo '<p class="note">' . h(t('{1} user(s) still wait for validation. They stay waiting until you validate or delete them.', $waiting)) .
                ' <a href="' . h(a_url('users', ['filter' => 'pending'])) . '">' . h(t('Check new users')) . '</a></p>';
        }
        if ($title === t('Updates') && Settings::get('update_check', '0') === '1') {
            echo '<p class="note">' . h(t('Update check: {1}', a_update_status())) . '</p>' .
                '<p><button class="btn small ghost" form="check-now" name="check_now" value="1">' . h(t('Check now')) . '</button></p>';
        }
        echo '</div></section>';
    }
    echo '<p><button class="btn" type="submit">' . h(t('Save settings')) . '</button></p></form>';
    echo '<form method="post" id="check-now" action="' . h(a_url('settings')) . '">' . a_csrf() . '</form>';
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

function page_log(array $admin): void
{
    if (a_post() && isset($_POST['clear'])) {
        DB::q('DELETE FROM {log}');
        a_flash(t('Log cleared.'));
        a_go('log');
    }
    echo '<h1>' . h(t('Log')) . '</h1>';
    echo '<p class="note">' . h(t('The statistics count uploads and downloads from the log. Clearing the log also removes these numbers.')) . '</p>';
    echo '<form method="post"' . a_confirm(t('Clear the whole log? The upload and download statistics are cleared as well.')) . '>' . a_csrf() .
        '<p><button class="btn small danger ghost" name="clear" value="1">' . h(t('Clear log')) . '</button></p></form>';
    echo '<div class="tablewrap"><table><tr><th>' . h(t('Date')) . '</th><th>' . h(t('User')) . '</th><th>' . h(t('Event')) . '</th></tr>';
    foreach (DB::all('SELECT * FROM {log} ORDER BY id DESC LIMIT 300') as $l) {
        echo '<tr><td class="num">' . date('d.m.y H:i:s', (int)$l['time']) . '</td><td>' . h($l['handle']) . '</td><td>' . h($l['text']) . '</td></tr>';
    }
    echo '</table></div>';
}
