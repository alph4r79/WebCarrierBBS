<?php
/**
 * Sysop backend: users and security levels.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Error text for a handle, or null. Same rules as the registration in the terminal. */
function a_handle_error(string $handle, int $exceptId = 0): ?string
{
    $err = cb_handle_check($handle, $exceptId);
    if ($err === 'bad') {
        return t('Handle: 3 to 20 letters, digits, spaces, dots, dashes or underscores.');
    }
    return $err === 'taken' ? t('This handle is already taken or reserved.') : null;
}

function page_users(array $admin): void
{
    $maxLevel = (int)$admin['level'];
    if (a_post() && isset($_POST['create'])) {
        $handle = a_in('handle', 20);
        $pw = (string)($_POST['pass'] ?? '');
        $level = a_int('level', 0, 255);
        $err = a_handle_error($handle);
        if ($err === null && mb_strlen($pw) < 6) {
            $err = t('The new password needs at least 6 characters.');
        } elseif ($err === null && $pw !== (string)($_POST['pass2'] ?? '')) {
            $err = t('The passwords do not match.');
        } elseif ($err === null && $level > $maxLevel) {
            $err = t('You cannot assign a level above your own.');
        }
        if ($err !== null) {
            a_flash($err, 'bad');
            a_go('users');
        }
        $id = DB::insert('users', [
            'handle' => $handle, 'handle_lc' => mb_strtolower($handle), 'pass' => password_hash($pw, PASSWORD_DEFAULT),
            'location' => a_in('location', 40), 'level' => $level, 'created' => time(), 'today' => date('Y-m-d'), 'baud' => -1,
        ]);
        cb_log((int)$admin['id'], $admin['handle'], 'Created user ' . $handle);
        a_flash(t('User {1} created.', $handle));
        a_go('user', ['id' => $id]);
    }

    $q = trim((string)($_GET['q'] ?? ''));
    echo '<h1>' . h(t('Users')) . '</h1>';
    echo '<form method="get" class="form row-actions"><input type="hidden" name="p" value="users">' .
        '<input name="q" value="' . h($q) . '" placeholder="' . h(t('Search handle or location')) . '" style="max-width:320px;margin:0">' .
        '<button class="btn" type="submit">' . h(t('Search')) . '</button></form><br>';
    $where = '';
    $par = [];
    if ($q !== '') {
        $where = 'WHERE handle_lc LIKE ? OR LOWER(location) LIKE ?';
        $like = '%' . mb_strtolower($q) . '%';
        $par = [$like, $like];
    }
    $rows = DB::all("SELECT * FROM {users} $where ORDER BY handle_lc LIMIT 500", $par);
    $levels = a_levels();
    echo '<div class="tablewrap"><table><tr><th>' . h(t('Handle')) . '</th><th>' . h(t('Location')) . '</th><th>' . h(t('Level')) .
        '</th><th class="num">' . h(t('Calls')) . '</th><th>' . h(t('Last call')) . '</th><th class="num">UL</th><th class="num">DL</th><th></th></tr>';
    foreach ($rows as $u) {
        echo '<tr><td><a href="' . h(a_url('user', ['id' => $u['id']])) . '">' . h($u['handle']) . '</a>' .
            ((int)$u['locked'] ? ' <span class="tag bad">' . h(t('locked')) . '</span>' : '') . '</td><td>' . h($u['location']) . '</td><td>' .
            h($levels[(int)$u['level']] ?? (string)$u['level']) . '</td><td class="num">' . (int)$u['calls'] . '</td><td>' .
            ((int)$u['last_call'] ? date('d.m.y H:i', (int)$u['last_call']) : '') . '</td><td class="num">' . (int)$u['ul_files'] .
            '</td><td class="num">' . (int)$u['dl_files'] . '</td><td><a class="btn small ghost" href="' . h(a_url('user', ['id' => $u['id']])) . '">' .
            h(t('Edit')) . '</a></td></tr>';
    }
    echo '</table></div>';

    echo '<form method="post" class="form panel" autocomplete="off">' . a_csrf() . '<h2>' . h(t('New user')) . '</h2><div class="grid2">' .
        '<label>' . h(t('Handle')) . '<input name="handle" required maxlength="20"></label>' .
        '<label>' . h(t('Location')) . '<input name="location" maxlength="40"></label>' .
        '<label>' . h(t('Password')) . '<input type="password" name="pass" required minlength="6" autocomplete="new-password"></label>' .
        '<label>' . h(t('Repeat password')) . '<input type="password" name="pass2" required minlength="6" autocomplete="new-password"></label>' .
        '<label>' . h(t('Level')) . a_level_select('level', min(Settings::int('new_level', 10), $maxLevel), $maxLevel) . '</label>' .
        '</div><button class="btn" name="create" value="1">' . h(t('Create user')) . '</button></form>';
}

function page_user(array $admin): void
{
    $id = (int)($_GET['id'] ?? 0);
    $u = DB::row('SELECT * FROM {users} WHERE id=?', [$id]);
    if (!$u) {
        a_flash(t('User not found.'), 'bad');
        a_go('users');
    }
    $self = (int)$u['id'] === (int)$admin['id'];
    $maxLevel = (int)$admin['level'];
    if (!$self && (int)$u['level'] > $maxLevel) {
        a_flash(t('You cannot edit users with a higher level than your own.'), 'bad');
        a_go('users');
    }
    if (a_post()) {
        if (isset($_POST['delete'])) {
            if ($self || (int)$u['id'] === Settings::int('sysop_id', 1)) {
                a_flash(t('The main sysop account cannot be deleted.'), 'bad');
                a_go('user', ['id' => $id]);
            }
            DB::q('DELETE FROM {users} WHERE id=?', [$id]);
            DB::q('DELETE FROM {lastread} WHERE user_id=?', [$id]);
            DB::q('DELETE FROM {door_data} WHERE user_id=?', [$id]);
            DB::q('DELETE FROM {messages} WHERE private=1 AND (to_id=? OR from_id=?)', [$id, $id]);
            cb_log((int)$admin['id'], $admin['handle'], 'Deleted user ' . $u['handle']);
            a_flash(t('User {1} deleted.', $u['handle']));
            a_go('users');
        }
        $handle = a_in('handle', 20);
        $lc = mb_strtolower($handle);
        $err = a_handle_error($handle, $id);
        if ($err === null && !$self && a_int('level', 0, 255) > $maxLevel) {
            $err = t('You cannot assign a level above your own.');
        }
        if ($err !== null) {
            a_flash($err, 'bad');
            a_go('user', ['id' => $id]);
        }
        $data = [
            'handle' => $handle, 'handle_lc' => $lc, 'location' => a_in('location', 40),
            'level' => $self ? (int)$u['level'] : a_int('level', 0, 255),
            'locked' => $self ? 0 : (isset($_POST['locked']) ? 1 : 0),
            'expert' => isset($_POST['expert']) ? 1 : 0,
            'note' => a_in('note', 255),
            'ul_files' => a_int('ul_files'), 'dl_files' => a_int('dl_files'),
        ];
        if (isset($_POST['reset_time'])) {
            $data['time_today'] = 0;
            $data['dl_kb_today'] = 0;
        }
        $pw = (string)($_POST['newpass'] ?? '');
        if ($pw !== '') {
            if (mb_strlen($pw) < 6) {
                a_flash(t('The new password needs at least 6 characters.'), 'bad');
                a_go('user', ['id' => $id]);
            }
            $data['pass'] = password_hash($pw, PASSWORD_DEFAULT);
        }
        DB::update('users', $data, 'id=?', [$id]);
        DB::q('UPDATE {messages} SET from_name=? WHERE from_id=?', [$handle, $id]);
        cb_log((int)$admin['id'], $admin['handle'], 'Edited user ' . $handle);
        a_flash(t('User saved.'));
        a_go('user', ['id' => $id]);
    }
    echo '<h1>' . h(t('User {1}', $u['handle'])) . '</h1>';
    echo '<form method="post" class="form panel">' . a_csrf() . '<div class="grid2">';
    echo '<label>' . h(t('Handle')) . '<input name="handle" value="' . h($u['handle']) . '" maxlength="20"></label>';
    echo '<label>' . h(t('Location')) . '<input name="location" value="' . h($u['location']) . '" maxlength="40"></label>';
    echo '<label>' . h(t('Level')) . ($self ? '<input value="' . (int)$u['level'] . '" disabled><span class="hint">' . h(t('You cannot change your own level.')) . '</span>'
            : a_level_select('level', (int)$u['level'], $maxLevel)) . '</label>';
    echo '<label>' . h(t('New password')) . '<input type="password" name="newpass" autocomplete="new-password"><span class="hint">' .
        h(t('Leave empty to keep the password. Callers cannot reset passwords themselves, that is your job.')) . '</span></label>';
    echo '<label>' . h(t('Uploads (files)')) . '<input type="number" name="ul_files" value="' . (int)$u['ul_files'] . '"></label>';
    echo '<label>' . h(t('Downloads (files)')) . '<input type="number" name="dl_files" value="' . (int)$u['dl_files'] . '"></label>';
    echo '</div>';
    echo '<label>' . h(t('Sysop note (only visible here)')) . '<input name="note" value="' . h($u['note']) . '" maxlength="255"></label>';
    if (!$self) {
        echo '<label class="inline"><input type="checkbox" name="locked"' . ((int)$u['locked'] ? ' checked' : '') . '> ' . h(t('Account locked')) . '</label>';
    }
    echo '<label class="inline"><input type="checkbox" name="expert"' . ((int)$u['expert'] ? ' checked' : '') . '> ' . h(t('Expert mode')) . '</label>';
    echo '<label class="inline"><input type="checkbox" name="reset_time"> ' . h(t('Reset time and download counter for today')) . '</label>';
    echo '<p class="note">' . h(t('Member since {1}, {2} calls, last call {3}, {4} messages, uploads {5}k, downloads {6}k.',
            date('d.m.Y', (int)$u['created']), (int)$u['calls'], (int)$u['last_call'] ? date('d.m.Y H:i', (int)$u['last_call']) : '-',
            (int)$u['posts'], (int)$u['ul_kb'], (int)$u['dl_kb'])) . '</p>';
    echo '<p class="row-actions"><button class="btn" type="submit">' . h(t('Save user')) . '</button> <a class="btn ghost" href="' . h(a_url('users')) . '">' . h(t('Back')) . '</a></p></form>';
    if (!$self) {
        echo '<form method="post" class="panel"' . a_confirm(t('Delete {1} for good? Private mail of this user is deleted too.', $u['handle'])) . '>' . a_csrf() .
            '<p class="note">' . h(t('Public messages stay in the areas.')) . '</p><button class="btn danger" name="delete" value="1">' . h(t('Delete user')) . '</button></form>';
    }
}

function page_levels(array $admin): void
{
    if (a_post()) {
        if (isset($_POST['del'])) {
            $l = (int)$_POST['del'];
            if ($l >= Settings::int('sysop_level', 255) || $l === Settings::int('new_level', 10)) {
                a_flash(t('This level is in use by the system settings.'), 'bad');
            } else {
                DB::q('DELETE FROM {levels} WHERE level=?', [$l]);
                a_flash(t('Level deleted.'));
            }
            a_go('levels');
        }
        foreach ((array)($_POST['lv'] ?? []) as $lvl => $row) {
            DB::update('levels', [
                'name' => mb_substr(trim((string)($row['name'] ?? '')), 0, 40),
                'minutes' => max(0, (int)($row['minutes'] ?? 0)),
                'dl_kb' => max(0, (int)($row['dl_kb'] ?? 0)),
                'ratio' => max(0, (int)($row['ratio'] ?? 0)),
            ], 'level=?', [(int)$lvl]);
        }
        $new = (int)($_POST['new_level'] ?? 0);
        if ($new > 0 && $new <= 255 && trim((string)($_POST['new_name'] ?? '')) !== '') {
            if (DB::val('SELECT COUNT(*) FROM {levels} WHERE level=?', [$new])) {
                a_flash(t('Level {1} exists already.', $new), 'bad');
            } else {
                DB::insert('levels', ['level' => $new, 'name' => mb_substr(trim((string)$_POST['new_name']), 0, 40),
                    'minutes' => max(0, (int)($_POST['new_minutes'] ?? 60)), 'dl_kb' => max(0, (int)($_POST['new_dl'] ?? 0)),
                    'ratio' => max(0, (int)($_POST['new_ratio'] ?? 0))]);
            }
        }
        a_flash(t('Levels saved.'));
        a_go('levels');
    }
    echo '<h1>' . h(t('Levels')) . '</h1>';
    echo '<p class="note">' . h(t('Each user has a level from 0 to 255. A user gets the limits of the highest level row that is not above his own level. 0 minutes or 0 KB means unlimited. Ratio 3 means three downloads per upload, 0 switches the ratio off.')) . '</p>';
    echo '<form method="post" class="form">' . a_csrf() . '<div class="tablewrap"><table><tr><th>' . h(t('Level')) . '</th><th>' . h(t('Name')) .
        '</th><th>' . h(t('Minutes per day')) . '</th><th>' . h(t('Download KB per day')) . '</th><th>' . h(t('Ratio')) . '</th><th></th></tr>';
    foreach (DB::all('SELECT * FROM {levels} ORDER BY level') as $l) {
        $k = (int)$l['level'];
        echo "<tr><td class=\"num\">$k</td><td><input name=\"lv[$k][name]\" value=\"" . h($l['name']) . "\"></td>" .
            "<td><input type=\"number\" name=\"lv[$k][minutes]\" value=\"" . (int)$l['minutes'] . "\"></td>" .
            "<td><input type=\"number\" name=\"lv[$k][dl_kb]\" value=\"" . (int)$l['dl_kb'] . "\"></td>" .
            "<td><input type=\"number\" name=\"lv[$k][ratio]\" value=\"" . (int)$l['ratio'] . "\"></td>" .
            '<td><button class="btn small danger ghost" name="del" value="' . $k . '" onclick="return confirm(' . h(json_encode(t('Delete this level?'))) . ')">' . h(t('Delete')) . '</button></td></tr>';
    }
    echo '<tr><td><input type="number" name="new_level" min="1" max="255" placeholder="' . h(t('new')) . '"></td><td><input name="new_name" placeholder="' . h(t('Name')) . '"></td>' .
        '<td><input type="number" name="new_minutes" value="60"></td><td><input type="number" name="new_dl" value="0"></td><td><input type="number" name="new_ratio" value="0"></td><td></td></tr>';
    echo '</table></div><p><button class="btn" type="submit">' . h(t('Save levels')) . '</button></p></form>';
}
