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
        'host' => t('The update package is hosted on a different server than the update information.'),
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
function a_dash_notices(?array $admin = null): array
{
    $n = [];
    if ($admin) {
        $wrongPw = (int)DB::val("SELECT COUNT(*) FROM {log} WHERE user_id=? "
            . "AND text LIKE 'Wrong password%' AND time>?", [(int)$admin['id'], time() - 86400]);
        if ($wrongPw >= 10) {
            $n[] = ['id' => 'wrongpw', 'kind' => 'security',
                'title' => t('{1} failed password attempts on your account in the last 24 hours', $wrongPw),
                'text' => (int)$admin['totp_on'] === 1
                    ? t('If that was not you, someone is trying to guess your password. Your second factor still protects the backend, but consider changing the password.')
                    : t('If that was not you, someone is trying to guess your password. Set up a second factor and consider changing the password.'),
                'actions' => [['link', t('My account'), a_url('account')]]];
        }
    }
    if ($admin && (int)$admin['totp_on'] !== 1) {
        if (in_array((int)$admin['id'], cb_totp_reset_ids(), true)) {
            $n[] = ['id' => 'totp-reset', 'kind' => 'security', 'title' => t('The second factor of your account was switched off'),
                'text' => t('The file data/2fa_reset.txt switched it off at your login. Your account is protected by the password alone until you set it up again.'),
                'actions' => [['link', t('My account'), a_url('account')]]];
        } elseif (Settings::int('totp_hint_' . (int)$admin['id'], 0) < time() - 30 * 86400) {
            // hidden for 30 days per sysop, not for good like the other notes
            $n[] = ['id' => 'totp', 'kind' => 'info', 'title' => t('Your account has no second factor'),
                'text' => t('With a code from an authenticator app a stolen password alone is not enough to get into the backend.'),
                'actions' => [['link', t('My account'), a_url('account')]]];
        }
    }
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
    $reports = (int)DB::val("SELECT COUNT(*) FROM {msg_reports} WHERE status='open'");
    if ($reports > 0) {
        $n[] = ['id' => 'reports', 'kind' => 'task', 'title' => t('{1} report(s) of messages are open', $reports),
            'text' => t('Callers reported messages they find not right.'), 'actions' => [['link', t('Reports'), a_url('reports')]]];
    }
    if ($admin && !a_full($admin)) {
        // moderators: only what they can work on, no notes about installation, updates and backups
        $n = array_values(array_filter($n, static fn($x) => in_array($x['id'], ['validate', 'uploads', 'reports', 'totp', 'totp-reset', 'wrongpw'], true)));
    }
    $dismissed = a_dismissed();
    $n = array_values(array_filter($n, static fn($x) => !in_array($x['kind'], ['info', 'news'], true) || !in_array($x['id'], $dismissed, true)));
    $rank = ['security' => 0, 'task' => 1, 'update' => 2, 'info' => 3, 'news' => 4];
    usort($n, static fn($a, $b) => $rank[$a['kind']] <=> $rank[$b['kind']]);
    return $n;
}

function a_dash_post(array $admin): void
{
    if (isset($_POST['dismiss']) && $_POST['dismiss'] === 'totp') {
        Settings::set('totp_hint_' . (int)$admin['id'], (string)time());
    } elseif (isset($_POST['dismiss'])) {
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
            if ((int)$row['user_id'] > 0) {
                cb_end_calls((int)$row['user_id'], $node, time());
            }
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
    } elseif (isset($_POST['sysmsg_save']) || isset($_POST['sysmsg_clear'])) {
        a_sysmsg_post($admin);
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

/**
 * Sysop message as lines: colour codes |00 to |23 and || stay, |CL, |CR, other codes and control
 * characters are removed. Second value: error text if there are more than 3 lines or a line is too long.
 */
function a_sysmsg_clean(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text) ?? '';
    $lines = [];
    foreach (explode("\n", trim($text, "\n")) as $l) {
        $l = preg_replace_callback('/\|(\d\d|CL|CR|\|)/', static fn($m) => $m[1] === '|' || (ctype_digit($m[1]) && (int)$m[1] < 24) ? $m[0] : '', $l) ?? '';
        $lines[] = rtrim($l);
    }
    if ($lines === ['']) {
        return [[], null];
    }
    if (count($lines) > 3) {
        return [$lines, t('The message has more than 3 lines.')];
    }
    foreach ($lines as $i => $l) {
        $len = mb_strlen(str_replace('||', '|', preg_replace('/\|\d\d/', '', $l) ?? ''));
        if ($len > 76) {
            return [$lines, t('Line {1} has {2} characters, at most 76 are allowed.', $i + 1, $len)];
        }
    }
    return [$lines, null];
}

/** Save or clear the sysop message (dashboard). */
function a_sysmsg_post(array $admin): void
{
    $old = [Settings::get('sysop_msg'), Settings::get('sysop_msg_until')];
    if (isset($_POST['sysmsg_clear'])) {
        $new = ['', ''];
    } else {
        $raw = (string)($_POST['sysmsg'] ?? '');
        $until = trim((string)($_POST['sysmsg_until'] ?? ''));
        [$lines, $err] = a_sysmsg_clean($raw);
        if ($err === null && $until !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $until, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) {
            $err = t('The date is not valid.');
        }
        if ($err !== null) {
            // keep the input for the form
            $_SESSION['cb_sysmsg_draft'] = [$raw, $until];
            a_flash($err . ' ' . t('The message was not saved.'), 'bad');
            return;
        }
        $new = $lines ? [implode("\n", $lines), $until] : ['', ''];
    }
    if ($new === $old) {
        return;
    }
    Settings::set('sysop_msg', $new[0]);
    Settings::set('sysop_msg_until', $new[1]);
    cb_log((int)$admin['id'], $admin['handle'], $new[0] === '' ? 'Cleared the sysop message' : 'Saved the sysop message');
    a_flash($new[0] === '' ? t('The message of the sysop was removed.') : t('The message of the sysop was saved.'));
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
    $notices = a_dash_notices($admin);
    // one-time notices are shown once
    foreach (['update_result', 'update_stale'] as $k) {
        if (Settings::get($k) !== '') {
            Settings::set($k, '');
        }
    }

    echo '<h1>' . h(t('Overview')) . '</h1>' . a_since_box($admin) . '<div class="dash"><div class="dash-main">';

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
        $chat = (int)$r['user_id'] > 0 && !$own ? '<form method="post" action="' . h(a_url('chat')) . '">' . a_csrf() .
            '<button class="btn small ghost" name="chat_start" value="' . (int)$r['node'] . '">' . h(t('Chat')) . '</button></form>' : '';
        echo '<tr><td>' . (int)$r['node'] . '</td><td>' . h($r['handle'] ?: t('(logging in)')) . '</td><td>' . h($r['activity']) .
            '</td><td>' . date('H:i', (int)$r['since']) . '</td><td class="row-actions">' . ($own ? '<span class="note">' . h(t('you')) . '</span>' : $chat .
            '<form method="post" action="' . h(a_url('dash')) . '"' . a_confirm(t('Disconnect node {1}?', (int)$r['node'])) . '>' . a_csrf() .
            '<button class="btn small danger ghost" name="kick" value="' . (int)$r['node'] . '">' . h(t('Disconnect')) . '</button></form>') . '</td></tr>';
    }
    echo '</table></div>';
    if ($rows && a_full($admin)) {
        echo '<form method="post" action="' . h(a_url('dash')) . '" class="broadcast">' . a_csrf() .
            '<label for="bc-text">' . h(t('Broadcast to all nodes')) . '</label><div class="broadcast-row">' .
            '<input id="bc-text" name="text" maxlength="70" required><button class="btn small" name="broadcast" value="1">' . h(t('Send')) . '</button></div></form>';
    }
    echo '</section>';

    if (!a_full($admin)) {
        a_dash_rest($admin);
        return;
    }
    $draft = $_SESSION['cb_sysmsg_draft'] ?? null;
    unset($_SESSION['cb_sysmsg_draft']);
    $msg = is_array($draft) ? (string)$draft[0] : Settings::get('sysop_msg');
    $until = is_array($draft) ? (string)$draft[1] : Settings::get('sysop_msg_until');
    $saved = Settings::get('sysop_msg');
    $savedUntil = Settings::get('sysop_msg_until');
    echo '<section class="panel dash-sysmsg"><h2>' . h(t('Message from the sysop')) . '</h2>';
    if ($saved !== '' && $savedUntil !== '' && date('Y-m-d') > $savedUntil) {
        echo '<p><span class="tag bad">' . h(t('expired')) . '</span> <span class="note">' .
            h(t('Shown until {1}, callers no longer see it.', date('d.m.Y', (int)strtotime($savedUntil)))) . '</span></p>';
    }
    echo '<form method="post" action="' . h(a_url('dash')) . '" class="form">' . a_csrf() .
        '<label>' . h(t('Text')) . '<textarea name="sysmsg" class="mono" rows="3">' . "\n" . h($msg) . '</textarea>' .
        '<span class="hint">' . h(t('Shown to every caller after the login. Up to 3 lines of 76 characters, pipe codes for colours like |14 are allowed.')) . '</span></label>' .
        '<label>' . h(t('Show until (optional)')) . '<input type="date" name="sysmsg_until" value="' . h($until) . '">' .
        '<span class="hint">' . h(t('Up to and including this day. Empty: until you change or remove the message.')) . '</span></label>' .
        '<div class="row-actions"><button class="btn small" name="sysmsg_save" value="1">' . h(t('Save message')) . '</button>' .
        ($saved !== '' ? '<button class="btn small danger ghost" name="sysmsg_clear" value="1">' . h(t('Remove message')) . '</button>' : '') .
        '</div></form></section>';

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

/** The rest of the overview for moderators: events without system entries, figures. */
function a_dash_rest(array $admin): void
{
    [$sys, $sp] = cb_log_kind_sql('system');
    echo '<section class="panel dash-events"><h2>' . h(t('Latest events')) . '</h2><table class="events"><tr><th>' . h(t('Time')) . '</th><th>' .
        h(t('User')) . '</th><th>' . h(t('Event')) . '</th></tr>';
    foreach (DB::all("SELECT * FROM {log} WHERE NOT ($sys) ORDER BY id DESC LIMIT 15", $sp) as $l) {
        echo '<tr><td class="time">' . date('d.m. H:i', (int)$l['time']) . '</td><td>' . h($l['handle']) . '</td><td>' . h($l['text']) . '</td></tr>';
    }
    echo '</table></section></div><div class="dash-side"><section class="panel dash-stats"><h2>' . h(t('Figures')) . '</h2><dl class="kv">' .
        '<dt>' . h(t('Online now')) . '</dt><dd>' . (int)DB::val('SELECT COUNT(*) FROM {nodes} WHERE user_id>0') . '</dd>' .
        '<dt>' . h(t('Uploads waiting')) . '</dt><dd>' . (int)DB::val('SELECT COUNT(*) FROM {files} WHERE approved=0') . '</dd>' .
        '<dt>' . h(t('Open reports')) . '</dt><dd>' . (int)DB::val("SELECT COUNT(*) FROM {msg_reports} WHERE status='open'") . '</dd></dl></section></div></div>';
}

/** Box "Since your last visit": counts since the start of the previous visit in the backend. */
function a_since_box(array $admin): string
{
    $prev = (int)$admin['backend_prev'];
    $h = '<section class="panel since"><h2>' . h(t('Since your last visit')) . '</h2>';
    if ($prev <= 0) {
        return $h . '<p class="note">' . h(t('From your next visit on, this box shows what happened in between.')) . '</p></section>';
    }
    $day = date('Y-m-d', $prev);
    $full = a_full($admin);
    $rows = [];
    $newUsers = (int)DB::val('SELECT COUNT(*) FROM {users} WHERE created>?', [$prev]);
    $waiting = (int)DB::val('SELECT COUNT(*) FROM {users} WHERE created>? AND pending=1', [$prev]);
    if ($full && $newUsers > 0) {
        $rows[] = [$newUsers, t('new user(s)') . ($waiting > 0 ? ', ' . t('of which {1} waiting', $waiting) : ''), a_url('users', $waiting > 0 ? ['filter' => 'pending'] : [])];
    } elseif (!$full && $waiting > 0) {
        $rows[] = [$waiting, t('new user(s) waiting for validation'), a_url('users', ['filter' => 'pending'])];
    }
    $rows[] = [(int)DB::val('SELECT COUNT(*) FROM {messages} WHERE private=0 AND posted>?', [$prev]), t('new public message(s)'), a_url('messages', ['from' => $day])];
    $up = (int)DB::val('SELECT COUNT(*) FROM {files} WHERE added>? AND uploader_id<>?', [$prev, (int)$admin['id']]);
    $upWait = (int)DB::val('SELECT COUNT(*) FROM {files} WHERE added>? AND approved=0', [$prev]);
    $rows[] = [$up, t('new upload(s)') . ($upWait > 0 ? ', ' . t('of which {1} waiting', $upWait) : ''), a_url('files', ['from' => $day] + ($upWait > 0 ? ['state' => 'waiting'] : []))];
    $rows[] = [(int)DB::val('SELECT COUNT(*) FROM {msg_reports} WHERE time>?', [$prev]), t('new report(s)'), a_url('reports')];
    $rows[] = [(int)DB::val("SELECT COUNT(*) FROM {sysop_pages} WHERE status='missed' AND time>?", [$prev]), t('missed call(s)'),
        a_url('log', ['q' => 'Missed page', 'from' => $day])];
    if ($full) {
        [$fail, $fp] = cb_log_kind_sql('fail');
        $rows[] = [(int)DB::val("SELECT COUNT(*) FROM {log} WHERE time>? AND $fail", array_merge([$prev], $fp)), t('failed login(s)'), a_url('log', ['kind' => 'fail', 'from' => $day])];
    }
    $rows = array_filter($rows, static fn($r) => $r[0] > 0);
    $h .= '<p class="note">' . h(t('Since {1}', date('d.m.Y H:i', $prev))) . '</p>';
    if (!$rows) {
        return $h . '<p>' . h(t('Nothing happened since your last visit.')) . '</p></section>';
    }
    $h .= '<ul class="since-list">';
    foreach ($rows as [$n, $label, $url]) {
        $h .= '<li><a href="' . h($url) . '"><strong>' . $n . '</strong> ' . h($label) . '</a></li>';
    }
    return $h . '</ul></section>';
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
            ['newsince', t('Show what is new since the last call'), 'bool', t('A box after the login counts new messages, files, members, oneliners, polls and high scores. An own logon screen places it with @NEWSINCE@.')],
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
            ['tab_notify', t('Hint in the browser tab'), 'bool', t('While the tab with the terminal is in the background, its title and icon show that something happened: a chat, a message, the turn in a door or a disconnect coming up. In the backend a call from a caller.')],
            ['crt', t('Tube effect'), 'select', ['off' => t('Off'), 'light' => t('Light (scanlines)'), 'strong' => t('Strong (scanlines, afterglow and glow)')]],
            ['wansidraw_url', t('Address of WAnsiDraw'), 'text', t('Callers and you can open ANSI files and screens in this editor. Empty switches the function off. The default is {1}.', CB_WANSIDRAW_URL)],
        ],
        t('Uploads') => [
            ['upload_max_kb', t('Maximum upload size in KB'), 'number', t('The PHP limit of your webspace is {1}.', cb_kb(Engine::uploadMaxBytes()))],
            ['upload_ext', t('Allowed file types'), 'text', t('Comma separated, for example zip,arj,lzh,txt')],
            ['upload_auto_approve', t('Uploads are online without your check'), 'bool', ''],
        ],
        t('Updates') => [
            ['update_check', t('Look for updates'), 'bool', t('Every 12 hours the board requests webcarrier-bbs.de/update.json to see if there is a new version. Nothing about your board is sent.')],
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
        if (!in_array(Settings::get('crt'), ['light', 'strong'], true)) {
            Settings::set('crt', 'off');
        }
        if (Settings::get('wansidraw_url') !== '' && cb_wansidraw_base() === '') {
            Settings::set('wansidraw_url', '');
            a_flash(t('The address of WAnsiDraw must start with http:// or https://. The function is switched off.'), 'warn');
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
                if ($k === 'crt') {
                    echo '<div class="crt-demo"><div class="tube" id="crt-tube"><canvas id="crt-pv"></canvas></div><p class="hint">' .
                        h(t('Preview. Callers can switch the effect off for themselves in their settings.')) . '</p></div>';
                }
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
        if ($title === t('General')) {
            echo '<p class="note">' . h(t('ID of this board:')) . ' <code>' . h(cb_box_id()) . '</code><br>' .
                h(t('It marks the messages written here, made for a later network between boards. It never changes and moves with a backup.')) . '</p>';
        }
        if ($title === t('Updates') && Settings::get('update_check', '0') === '1') {
            echo '<p class="note">' . h(t('Update check: {1}', a_update_status())) . '</p>' .
                '<p><button class="btn small ghost" form="check-now" name="check_now" value="1">' . h(t('Check now')) . '</button></p>';
        }
        echo '</div></section>';
    }
    echo '<p><button class="btn" type="submit">' . h(t('Save settings')) . '</button></p></form>';
    echo '<form method="post" id="check-now" action="' . h(a_url('settings')) . '">' . a_csrf() . '</form>';
    // preview of the tube effect with the renderer and the styles of the terminal
    $w = new AnsiWriter();
    $w->write('|CL|15' . cb_esc(Settings::get('bbs_name', 'WebCarrier BBS')) . '|CR|CR' .
        Lang::get('welcome_back', [cb_esc((string)$admin['handle']), (int)$admin['calls']]) . '|CR' . Lang::get('time_today', [60]) . '|CR|CR');
    for ($c = 1; $c < 16; $c++) {
        $w->write(sprintf('|%02d', $c) . '████');
    }
    $w->write('|CR|CR' . Lang::get('pause') . ' ');
    echo '<link rel="stylesheet" href="../assets/terminal.css?v=' . CB_VERSION . '">' .
        '<script src="../assets/font.js?v=' . CB_VERSION . '"></script><script src="../assets/terminal.js?v=' . CB_VERSION . '"></script>' .
        '<script>(function(){var t=new CarrierTerm(document.getElementById("crt-pv"));t.setBaud(0);t.write(' .
        json_encode(CP437::transport($w->buf), JSON_HEX_TAG | JSON_HEX_AMP) . ');t.showCursor(true);' .
        'var c=new CarrierCRT(document.getElementById("crt-tube"),t),s=document.querySelector("select[name=crt]");' .
        'c.set(s.value);s.addEventListener("change",function(){c.set(s.value);});}());</script>';
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
    if (a_post() && isset($_POST['del_tele'])) {
        $n = DB::q('DELETE FROM {tele_lines} WHERE id=?', [(int)$_POST['del_tele']])->rowCount();
        if ($n) {
            cb_log((int)$admin['id'], $admin['handle'], 'Deleted a line of the meeting point');
            a_flash(t('Line deleted.'));
        }
        a_go('oneliners');
    }
    if (a_post() && isset($_POST['clear_tele'])) {
        $n = DB::q('DELETE FROM {tele_lines}')->rowCount();
        cb_log((int)$admin['id'], $admin['handle'], 'Deleted all ' . $n . ' line(s) of the meeting point');
        a_flash(t('All lines of the meeting point deleted.'));
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

    $lines = DB::all('SELECT * FROM {tele_lines} ORDER BY id DESC');
    echo '<h2>' . h(t('Meeting point')) . '</h2><p class="note">' . h(t('The last 50 lines of the meeting point are kept. Callers see the lines of the last 30 minutes when they enter.')) . '</p>';
    if (!$lines) {
        echo '<div class="panel"><p class="note">' . h(t('There are no lines.')) . '</p></div>';
        return;
    }
    echo '<div class="tablewrap"><table><tr><th>' . h(t('Date')) . '</th><th>' . h(t('User')) . '</th><th>' . h(t('Text')) . '</th><th></th></tr>';
    foreach ($lines as $l) {
        echo '<tr><td class="num">' . date('d.m.y H:i', (int)$l['time']) . '</td><td>' . h($l['handle']) . '</td><td>' . h($l['text']) .
            '</td><td><form method="post"' . a_confirm(t('Delete this line?')) . '>' . a_csrf() .
            '<button class="btn small danger ghost" name="del_tele" value="' . (int)$l['id'] . '">' . h(t('Delete')) . '</button></form></td></tr>';
    }
    echo '</table></div><form method="post"' . a_confirm(t('Delete all lines of the meeting point?')) . '>' . a_csrf() .
        '<p><button class="btn small danger ghost" name="clear_tele" value="1">' . h(t('Delete all lines')) . '</button></p></form>';
}

/** Names of the kinds of log entries. */
function a_log_kind_names(): array
{
    return ['login' => t('Logins and logoffs'), 'fail' => t('Failed attempts'), 'files' => t('Uploads and downloads'), 'backend' => t('Actions in the backend'),
        'system' => t('Updates, backups and settings'), 'other' => t('Other')];
}

/** Log with filters for kind, handle, period and text, 100 entries a page, newest first. The filters are part of the address. */
function page_log(array $admin): void
{
    $full = a_full($admin);
    if (a_post() && isset($_POST['clear'])) {
        DB::q('DELETE FROM {log}');
        cb_log((int)$admin['id'], $admin['handle'], 'Cleared the log');
        a_flash(t('Log cleared.'));
        a_go('log');
    }
    $kinds = a_log_kind_names();
    if (!$full) {
        unset($kinds['system']);
    }
    $f = ['kind' => (string)($_GET['kind'] ?? ''), 'handle' => trim((string)($_GET['handle'] ?? '')), 'from' => (string)($_GET['from'] ?? ''),
        'to' => (string)($_GET['to'] ?? ''), 'q' => trim((string)($_GET['q'] ?? ''))];
    $f['kind'] = isset($kinds[$f['kind']]) ? $f['kind'] : '';
    $where = [];
    $par = [];
    if ($f['kind'] !== '') {
        [$sql, $p] = cb_log_kind_sql($f['kind']);
        $where[] = $sql;
        $par = array_merge($par, $p);
    }
    if (!$full) {
        // moderators do not see backups, updates and settings
        [$sql, $p] = cb_log_kind_sql('system');
        $where[] = "NOT ($sql)";
        $par = array_merge($par, $p);
    }
    if ($f['handle'] !== '') {
        $where[] = DB::lower('handle') . '=?';
        $par[] = mb_strtolower($f['handle']);
    }
    if (($t = a_date_ts($f['from'])) > 0) {
        $where[] = 'time>=?';
        $par[] = $t;
    }
    if (($t = a_date_ts($f['to'], true)) > 0) {
        $where[] = 'time<=?';
        $par[] = $t;
    }
    if ($f['q'] !== '') {
        $where[] = DB::lower('text') . " LIKE ? ESCAPE '!'";
        $par[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($f['q'])) . '%';
    }
    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    if ($full && isset($_GET['csv'])) {
        a_log_csv($admin, $sqlWhere, $par, $f);
    }
    $total = (int)DB::val('SELECT COUNT(*) FROM {log}' . $sqlWhere, $par);
    $pages = max(1, (int)ceil($total / 100));
    $pg = max(1, min($pages, (int)($_GET['pg'] ?? 1)));
    $rows = DB::all('SELECT * FROM {log}' . $sqlWhere . ' ORDER BY id DESC LIMIT 100 OFFSET ' . (($pg - 1) * 100), $par);
    $filter = array_filter($f);

    echo '<h1>' . h(t('Log')) . '</h1>';
    echo '<form method="get" class="form filterbar logfilter"><input type="hidden" name="p" value="log"><select name="kind" aria-label="' . h(t('Kind')) . '">' .
        '<option value="">' . h(t('All kinds')) . '</option>';
    foreach ($kinds as $k => $label) {
        echo '<option value="' . $k . '"' . ($k === $f['kind'] ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    echo '</select><input name="handle" value="' . h($f['handle']) . '" placeholder="' . h(t('Handle')) . '" aria-label="' . h(t('Handle')) . '">' .
        '<label class="inline">' . h(t('From')) . ' <input type="date" name="from" value="' . h($f['from']) . '"></label>' .
        '<label class="inline">' . h(t('to')) . ' <input type="date" name="to" value="' . h($f['to']) . '"></label>' .
        '<input name="q" value="' . h($f['q']) . '" placeholder="' . h(t('Text contains')) . '" aria-label="' . h(t('Text contains')) . '">' .
        '<button class="btn" type="submit">' . h(t('Filter')) . '</button>' . ($filter ? '<a href="' . h(a_url('log')) . '">' . h(t('Reset')) . '</a>' : '') . '</form>';
    echo '<p class="row-actions"><span class="note">' . h(t('{1} entries', $total)) . '</span>';
    if ($full) {
        echo '<a class="btn small ghost" href="' . h(a_url('log', $filter + ['csv' => 1])) . '">' . h(t('Download as CSV')) . '</a>';
    }
    echo '</p>';
    $names = a_log_kind_names();
    echo '<div class="tablewrap"><table class="cards log"><tr><th>' . h(t('Date')) . '</th><th>' . h(t('User')) . '</th><th>' . h(t('Kind')) . '</th><th>' . h(t('Event')) . '</th></tr>';
    foreach ($rows as $l) {
        echo '<tr><td class="num" data-label="' . h(t('Date')) . '">' . date('d.m.y H:i:s', (int)$l['time']) . '</td><td data-label="' . h(t('User')) . '">' . h($l['handle']) .
            '</td><td data-label="' . h(t('Kind')) . '">' . h($names[cb_log_kind((string)$l['text'])]) . '</td><td class="ev">' . h($l['text']) . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="4" class="note">' . h(t('No entries.')) . '</td></tr>';
    }
    echo '</table></div>';
    if ($pages > 1) {
        echo '<nav class="row-actions pager" aria-label="' . h(t('Pages')) . '">' .
            ($pg > 1 ? '<a class="btn small ghost" href="' . h(a_url('log', $filter + ['pg' => $pg - 1])) . '">' . h(t('Newer')) . '</a>' : '') .
            '<span class="note">' . h(t('Page {1} of {2}', $pg, $pages)) . '</span>' .
            ($pg < $pages ? '<a class="btn small ghost" href="' . h(a_url('log', $filter + ['pg' => $pg + 1])) . '">' . h(t('Older')) . '</a>' : '') . '</nav>';
    }
    if ($full) {
        echo '<form method="post"' . a_confirm(t('Clear the whole log? The upload and download statistics are cleared as well.')) . '>' . a_csrf() .
            '<p class="note">' . h(t('The statistics count uploads and downloads from the log. Clearing the log also removes these numbers.')) . '</p>' .
            '<p><button class="btn small danger ghost" name="clear" value="1">' . h(t('Clear log')) . '</button></p></form>';
    }
}

/** The filtered log as CSV (UTF-8 with BOM for spreadsheets), at most 10000 lines. */
function a_log_csv(array $admin, string $where, array $par, array $f): never
{
    cb_log((int)$admin['id'], $admin['handle'], 'Exported the log as CSV' . ($f !== array_filter([]) ? ' (' . http_build_query(array_filter($f)) . ')' : ''));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="webcarrierbbs-log-' . date('Y-m-d-His') . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    $names = a_log_kind_names();
    fputcsv($out, [t('Date'), t('User'), t('Kind'), t('Event')], ';');
    foreach (DB::all('SELECT * FROM {log}' . $where . ' ORDER BY id DESC LIMIT 10000', $par) as $l) {
        fputcsv($out, [date('Y-m-d H:i:s', (int)$l['time']), $l['handle'], $names[cb_log_kind((string)$l['text'])], $l['text']], ';');
    }
    fclose($out);
    exit;
}
