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

/** Warning (no block) if a handle the sysop sets is on the ban list. */
function a_ban_warning(string $handle): void
{
    $hit = cb_banned_handle($handle);
    if ($hit !== null) {
        a_flash(t('The handle {1} matches the entry "{2}" of the ban list. It was saved anyway.', $handle, $hit), 'warn');
    }
}

/** Ban list: handles and words, one per line. */
function page_banlist(array $admin): void
{
    if (a_post()) {
        foreach (['ban_handles', 'ban_words'] as $k) {
            $seen = [];
            $lines = [];
            foreach (preg_split('/\R/u', (string)($_POST[$k] ?? '')) ?: [] as $l) {
                $l = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $l) ?? '');
                $lc = mb_strtolower($l);
                if ($l !== '' && !isset($seen[$lc])) {
                    $seen[$lc] = true;
                    $lines[] = mb_substr($l, 0, 60);
                }
            }
            Settings::set($k, implode("\n", $lines));
        }
        cb_log((int)$admin['id'], $admin['handle'], 'Saved the ban list');
        a_flash(t('Ban list saved.'));
        a_go('banlist');
    }
    echo '<h1>' . h(t('Ban list')) . '</h1>';
    echo '<p class="note">' . h(t('One entry per line, upper and lower case do not matter. * stands for any characters, for example admin* or *sysop*.')) . '</p>';
    echo '<form method="post" class="form panel">' . a_csrf() . '<div class="grid2">' .
        '<label>' . h(t('Banned handles')) . '<textarea name="ban_handles" class="mono">' . h(Settings::get('ban_handles')) . '</textarea>' .
        '<span class="hint">' . h(t('An entry matches the whole handle. Blocks new registrations, in the backend you only get a warning.')) . '</span></label>' .
        '<label>' . h(t('Banned words')) . '<textarea name="ban_words" class="mono">' . h(Settings::get('ban_words')) . '</textarea>' .
        '<span class="hint">' . h(t('An entry matches whole words only, spam* also matches words starting with spam. Checked in oneliners, subjects, messages, private mail, locations and upload descriptions. Sysops are not affected.')) . '</span></label>' .
        '</div><button class="btn" type="submit">' . h(t('Save ban list')) . '</button></form>';

    $hits = [];
    foreach (DB::all('SELECT id, handle FROM {users} ORDER BY handle_lc') as $u) {
        if (($e = cb_banned_handle($u['handle'])) !== null) {
            $hits[] = [$u, $e];
        }
    }
    echo '<h2>' . h(t('Existing users on the ban list')) . '</h2>';
    if (!$hits) {
        echo '<div class="panel"><p class="note">' . h(t('No existing handle matches the ban list.')) . '</p></div>';
        return;
    }
    echo '<p class="note">' . h(t('These users are not locked automatically. Check them and decide yourself.')) . '</p>';
    echo '<div class="tablewrap"><table><tr><th>' . h(t('Handle')) . '</th><th>' . h(t('Entry')) . '</th><th></th></tr>';
    foreach ($hits as [$u, $e]) {
        echo '<tr><td>' . h($u['handle']) . '</td><td><code>' . h($e) . '</code></td><td><a class="btn small ghost" href="' .
            h(a_url('user', ['id' => $u['id']])) . '">' . h(t('Edit')) . '</a></td></tr>';
    }
    echo '</table></div>';
}

/** Validate (with a level up to the own one) or delete users that wait for validation. */
/** Highest level an account may give: full up to its own, moderators below their own and without backend rights. */
function a_max_level(array $admin): int
{
    return a_full($admin) ? (int)$admin['level'] : (int)$admin['level'] - 1;
}

function a_pending_post(array $admin): void
{
    $maxLevel = a_max_level($admin);
    $done = ['approve' => 0, 'delete' => 0];
    $jobs = [];
    if (isset($_POST['approve_one'])) {
        $id = (int)$_POST['approve_one'];
        $jobs[] = ['approve', $id, (int)($_POST['lvl'][$id] ?? Settings::int('new_level', 10))];
    } elseif (isset($_POST['delete_one'])) {
        $jobs[] = ['delete', (int)$_POST['delete_one'], 0];
    } elseif (in_array($_POST['bulk'] ?? '', ['approve', 'delete'], true)) {
        foreach (array_unique(array_map('intval', (array)($_POST['ids'] ?? []))) as $id) {
            $jobs[] = [(string)$_POST['bulk'], $id, a_int('bulk_level', 0, 255)];
        }
        if (!$jobs) {
            a_flash(t('No users selected.'), 'warn');
        }
    }
    foreach ($jobs as [$op, $id, $lvl]) {
        $u = DB::row('SELECT * FROM {users} WHERE id=? AND pending=1', [$id]);
        if (!$u) {
            continue;
        }
        if ($op === 'approve') {
            if ($lvl < 0 || $lvl > $maxLevel) {
                a_flash(t('You cannot assign a level above your own.'), 'bad');
                break;
            }
            if (!a_full($admin) && cb_level_role($lvl) !== 'none') {
                a_flash(t('Moderators cannot give a level with rights in the backend.'), 'bad');
                break;
            }
            DB::q('UPDATE {users} SET pending=0, level=? WHERE id=?', [$lvl, $id]);
            cb_log((int)$admin['id'], $admin['handle'], 'Validated user ' . $u['handle'] . ' with level ' . $lvl);
        } else {
            cb_delete_user($id);
            cb_log((int)$admin['id'], $admin['handle'], 'Deleted waiting user ' . $u['handle']);
        }
        $done[$op]++;
    }
    if ($done['approve']) {
        a_flash(t('{1} user(s) validated.', $done['approve']));
    }
    if ($done['delete']) {
        a_flash(t('{1} user(s) deleted.', $done['delete']));
    }
    a_go('users', ['filter' => 'pending']);
}

function page_users(array $admin): void
{
    $maxLevel = a_max_level($admin);
    if (a_post() && (isset($_POST['approve_one']) || isset($_POST['delete_one']) || isset($_POST['bulk']))) {
        a_pending_post($admin);
    }
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
        a_ban_warning($handle);
        a_go('user', ['id' => $id]);
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $pendingOnly = ($_GET['filter'] ?? '') === 'pending';
    $waiting = (int)DB::val('SELECT COUNT(*) FROM {users} WHERE pending=1');
    echo '<h1>' . h(t('Users')) . '</h1>';
    echo '<p class="row-actions filters"><a href="' . h(a_url('users')) . '"' . (!$pendingOnly ? ' aria-current="page"' : '') . '>' . h(t('All users')) . '</a>' .
        '<a href="' . h(a_url('users', ['filter' => 'pending'])) . '"' . ($pendingOnly ? ' aria-current="page"' : '') . '>' .
        h(t('Waiting for validation ({1})', $waiting)) . '</a></p>';
    if ($pendingOnly) {
        a_pending_list($maxLevel);
        return;
    }
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
            ((int)$u['locked'] ? ' <span class="tag bad">' . h(t('locked')) . '</span>' : '') .
            ((int)$u['pending'] ? ' <span class="tag">' . h(t('waiting')) . '</span>' : '') . '</td><td>' . h($u['location']) . '</td><td>' .
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

/** Users waiting for validation, with single and bulk actions. */
function a_pending_list(int $maxLevel): void
{
    $rows = DB::all('SELECT * FROM {users} WHERE pending=1 ORDER BY created, id');
    if (!$rows) {
        echo '<div class="panel"><p>' . h(t('Nobody is waiting for validation.')) . '</p></div>';
        return;
    }
    $def = min(Settings::int('new_level', 10), $maxLevel);
    $answers = [];
    foreach (DB::all('SELECT a.* FROM {nu_answers} a JOIN {users} u ON u.id=a.user_id WHERE u.pending=1 ORDER BY a.id') as $a) {
        $answers[(int)$a['user_id']][] = $a;
    }
    $all = 'for(const c of this.form.querySelectorAll(\'input[name="ids[]"]\'))c.checked=this.checked';
    echo '<form method="post" action="' . h(a_url('users', ['filter' => 'pending'])) . '">' . a_csrf() .
        '<div class="tablewrap"><table><tr><th class="check"><input type="checkbox" onclick="' . h($all) . '" aria-label="' . h(t('Select all')) . '"></th><th>' .
        h(t('Handle')) . '</th><th>' . h(t('Location')) . '</th><th>' . h(t('Registered')) . '</th><th>' . h(t('Level')) . '</th><th></th></tr>';
    foreach ($rows as $u) {
        $id = (int)$u['id'];
        echo '<tr><td class="check"><input type="checkbox" name="ids[]" value="' . $id . '" aria-label="' . h($u['handle']) . '"></td>' .
            '<td><a href="' . h(a_url('user', ['id' => $id])) . '">' . h($u['handle']) . '</a></td><td>' . h($u['location']) . '</td>' .
            '<td class="num">' . date('d.m.y H:i', (int)$u['created']) . '</td><td>' . a_level_select('lvl[' . $id . ']', $def, $maxLevel) . '</td>' .
            '<td class="row-actions"><button class="btn small" name="approve_one" value="' . $id . '">' . h(t('Validate')) . '</button>' .
            '<button class="btn small danger ghost" name="delete_one" value="' . $id . '" onclick="return confirm(' .
            h(json_encode(t('Delete {1} for good?', $u['handle']))) . ')">' . h(t('Delete')) . '</button></td></tr>';
        if (isset($answers[$id])) {
            echo '<tr class="answer-row"><td></td><td colspan="5">' . a_answers_html($answers[$id], false) . '</td></tr>';
        }
    }
    echo '</table></div><div class="bulk"><span class="note">' . h(t('Selected users:')) . '</span><span class="move">' .
        a_level_select('bulk_level', $def, $maxLevel) . '<button class="btn small" name="bulk" value="approve">' . h(t('Validate')) . '</button></span>' .
        '<button class="btn small danger ghost" name="bulk" value="delete" onclick="return confirm(' . h(json_encode(t('Delete the selected users?'))) . ')">' .
        h(t('Delete')) . '</button></div></form>';
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
        if (isset($_POST['export'])) {
            a_user_export($admin, $u);
        }
        if (isset($_POST['clear_profile'])) {
            // the sysop can only empty these texts, not edit them
            $field = $_POST['clear_profile'] === 'signature' ? 'signature' : 'about';
            if ((string)$u[$field] !== '') {
                DB::q('UPDATE {users} SET ' . $field . "='' WHERE id=?", [$id]);
                cb_log((int)$admin['id'], $admin['handle'], ($field === 'about' ? 'Cleared about me of ' : 'Cleared the signature of ') . $u['handle']);
                a_flash($field === 'about' ? t('About me removed.') : t('Signature removed.'));
            }
            a_go('user', ['id' => $id]);
        }
        if (isset($_POST['del_answer'])) {
            $a = DB::row('SELECT * FROM {nu_answers} WHERE id=? AND user_id=?', [(int)$_POST['del_answer'], $id]);
            if ($a) {
                DB::q('DELETE FROM {nu_answers} WHERE id=?', [(int)$a['id']]);
                cb_log((int)$admin['id'], $admin['handle'], 'Deleted an answer of ' . $u['handle'] . ' from the questionnaire');
                a_flash(t('Answer deleted.'));
            }
            a_go('user', ['id' => $id]);
        }
        if (isset($_POST['totp_off'])) {
            // the own second factor is switched off under My account, with password and code
            if (!$self && (int)$u['totp_on'] === 1) {
                cb_totp_disable($id);
                cb_log((int)$admin['id'], $admin['handle'], 'Switched off the second factor of ' . $u['handle']);
                a_flash(t('The second factor of {1} is switched off.', $u['handle']));
            }
            a_go('user', ['id' => $id]);
        }
        if (isset($_POST['delete'])) {
            if ($self || (int)$u['id'] === Settings::int('sysop_id', 1)) {
                a_flash(t('The main sysop account cannot be deleted.'), 'bad');
                a_go('user', ['id' => $id]);
            }
            cb_delete_user($id);
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
            'no_auto' => isset($_POST['no_auto']) ? 1 : 0,
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
        if ($lc !== $u['handle_lc']) {
            a_ban_warning($handle);
        }
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
    echo '<label class="inline"><input type="checkbox" name="no_auto"' . ((int)$u['no_auto'] ? ' checked' : '') . '> ' . h(t('Do not promote automatically')) . '</label>';
    // the login counts the call first, then checks the rules
    $auto = cb_auto_level(['calls' => (int)$u['calls'] + 1] + $u);
    if ($auto !== null) {
        echo '<p class="note">' . h(t('A rule of the automatic promotion would raise this user to level {1} at the next call.', $auto[0])) . '</p>';
    }
    echo '<p class="note">' . h(t('Member since {1}, {2} calls, last call {3}, {4} messages, uploads {5}k, downloads {6}k.',
            date('d.m.Y', (int)$u['created']), (int)$u['calls'], (int)$u['last_call'] ? date('d.m.Y H:i', (int)$u['last_call']) : '-',
            (int)$u['posts'], (int)$u['ul_kb'], (int)$u['dl_kb'])) . '</p>';
    echo '<p class="row-actions"><button class="btn" type="submit">' . h(t('Save user')) . '</button> <a class="btn ghost" href="' . h(a_url('users')) . '">' . h(t('Back')) . '</a></p></form>';
    echo '<div class="panel profile-texts"><h2>' . h(t('Profile')) . '</h2>';
    foreach (['about' => [t('About me'), t('Remove about me')], 'signature' => [t('Signature'), t('Remove signature')]] as $f => [$label, $btn]) {
        $text = (string)$u[$f];
        echo '<h3>' . h($label) . '</h3>' . ($text === '' ? '<p class="note">' . h(t('empty')) . '</p>' : '<pre class="mono">' . h($text) . '</pre>' .
            '<form method="post"' . a_confirm($btn . '?') . '>' . a_csrf() . '<button class="btn small danger ghost" name="clear_profile" value="' . $f . '">' . h($btn) . '</button></form>');
    }
    echo '<p class="note">' . h(t('Callers write these texts themselves in the terminal. You can only remove them.')) . '</p></div>';
    a_user_calls($admin, $u);
    $answers = DB::all('SELECT * FROM {nu_answers} WHERE user_id=? ORDER BY id', [$id]);
    if ($answers) {
        echo '<div class="panel"><h2>' . h(t('Questionnaire')) . '</h2>' . a_answers_html($answers, true) .
            '<p class="note">' . h(t('Answers from the registration on {1}.', date('d.m.Y', (int)$answers[0]['time']))) . '</p></div>';
    }
    if ((int)$u['totp_on'] === 1) {
        echo '<div class="panel"><h2>' . h(t('Second factor')) . '</h2><p><span class="tag ok">' . h(t('active')) . '</span> ' .
            h(t('Logins of this account ask for a code from an authenticator app.')) . '</p>';
        if ($self) {
            echo '<p class="note"><a href="' . h(a_url('account')) . '">' . h(t('My account')) . '</a></p>';
        } else {
            echo '<form method="post"' . a_confirm(t('Switch off the second factor of {1}? The account is then protected by the password alone.', $u['handle'])) . '>' .
                a_csrf() . '<button class="btn small danger ghost" name="totp_off" value="1">' . h(t('Switch off the second factor')) . '</button></form>';
        }
        echo '</div>';
    }
    if (!$self) {
        echo '<form method="post" class="panel"' . a_confirm(t('Delete {1} for good? Private mail of this user is deleted too.', $u['handle'])) . '>' . a_csrf() .
            '<p class="note">' . h(t('Public messages stay in the areas.')) . '</p><button class="btn danger" name="delete" value="1">' . h(t('Delete user')) . '</button></form>';
    }
}

/** Users a rule would promote at their next call: [user, new level]. */
function a_auto_preview(): array
{
    $out = [];
    foreach (DB::all('SELECT * FROM {users} WHERE locked=0 AND pending=0 AND no_auto=0 AND level<? ORDER BY handle_lc', [Settings::int('sysop_level', 255)]) as $u) {
        // the login counts the call first, then checks the rules
        $u['calls'] = (int)$u['calls'] + 1;
        $a = cb_auto_level($u);
        if ($a !== null) {
            $out[] = [$u, $a[0]];
        }
    }
    return $out;
}

/** Save the switch and the rules of the automatic promotion. */
function a_rules_post(): void
{
    if (isset($_POST['del_rule'])) {
        DB::q('DELETE FROM {level_rules} WHERE id=?', [(int)$_POST['del_rule']]);
        a_flash(t('Rule deleted.'));
        a_go('levels');
    }
    Settings::set('auto_level', isset($_POST['auto_level']) ? '1' : '0');
    $sysop = Settings::int('sysop_level', 255);
    $count = (int)DB::val('SELECT COUNT(*) FROM {level_rules}');
    $bad = false;
    foreach ((array)($_POST['ru'] ?? []) as $key => $row) {
        $n = static fn(string $k): int => max(0, min(1000000, (int)($row[$k] ?? 0)));
        $d = ['from_level' => min(255, $n('from_level')), 'to_level' => min(255, $n('to_level')), 'min_calls' => $n('min_calls'),
            'min_days' => $n('min_days'), 'min_posts' => $n('min_posts'), 'min_uploads' => $n('min_uploads'), 'active' => isset($row['active']) ? 1 : 0];
        $conditions = $d['min_calls'] + $d['min_days'] + $d['min_posts'] + $d['min_uploads'];
        $new = $key === 'new';
        if ($new && $conditions === 0) {
            continue;
        }
        $label = $new ? t('new') : (string)(int)$key;
        $err = match (true) {
            $conditions === 0 => t('Rule {1}: at least one condition must be above 0.', $label),
            $d['to_level'] <= $d['from_level'] => t('Rule {1}: the new level must be above the level it starts from.', $label),
            $d['to_level'] >= $sysop => t('Rule {1}: the new level must be below the sysop level ({2}).', $label, $sysop),
            $new && $count >= 5 => t('There are 5 rules already.'),
            default => null,
        };
        if ($err !== null) {
            a_flash($err . ' ' . t('The rule was not saved.'), 'bad');
            $bad = true;
        } elseif ($new) {
            DB::insert('level_rules', $d);
            $count++;
        } else {
            DB::update('level_rules', $d, 'id=?', [(int)$key]);
        }
    }
    if (!$bad) {
        a_flash(t('Automatic promotion saved.'));
    }
    a_go('levels');
}

/** Select of the backend role of a level; the sysop level is always full. */
function a_role_select(int $level, string $cur, array $roles): string
{
    if ($level >= Settings::int('sysop_level', 255)) {
        return '<td>' . h($roles['full']) . '</td>';
    }
    $h = '<td><select name="lv[' . $level . '][backend]" aria-label="' . h(t('Backend')) . '">';
    foreach ($roles as $k => $label) {
        $h .= '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $h . '</select></td>';
}

/** Last 50 calls of a user and the buttons for mail, chat and data export. */
function a_user_calls(array $admin, array $u): void
{
    $id = (int)$u['id'];
    $node = DB::val('SELECT node FROM {nodes} WHERE user_id=? AND kicked=0', [$id]);
    echo '<div class="panel"><h2>' . h(t('Contact and data')) . '</h2><div class="row-actions">';
    if ((int)$u['locked'] === 0 && (int)$u['pending'] === 0 && $id !== (int)$admin['id']) {
        echo '<a class="btn small ghost" href="' . h(a_url('mail', ['compose' => 1, 'to' => $u['handle']])) . '">' . h(t('Write a private message')) . '</a>';
    }
    if ($node !== null && $id !== (int)$admin['id']) {
        echo '<form method="post" action="' . h(a_url('chat')) . '">' . a_csrf() . '<button class="btn small ghost" name="chat_start" value="' . (int)$node . '">' .
            h(t('Start chat (node {1})', (int)$node)) . '</button></form>';
    }
    echo '<form method="post">' . a_csrf() . '<button class="btn small ghost" name="export" value="1">' . h(t('Export data')) . '</button></form></div>' .
        '<p class="note">' . h(t('The export is an answer to a request for access to the stored data: a ZIP with daten.json and daten.txt.')) . '</p></div>';
    $calls = DB::all('SELECT * FROM {calls} WHERE user_id=? ORDER BY id DESC LIMIT 50', [$id]);
    echo '<div class="panel"><h2>' . h(t('Calls')) . '</h2>';
    if (!$calls) {
        echo '<p class="note">' . h(t('No calls yet.')) . '</p></div>';
        return;
    }
    echo '<div class="tablewrap"><table class="cards"><tr><th>' . h(t('Date')) . '</th><th>' . h(t('Node')) . '</th><th>' . h(t('Duration')) . '</th></tr>';
    foreach ($calls as $c) {
        $dur = (int)$c['ended'] > 0 ? max(0, (int)$c['ended'] - (int)$c['time']) : null;
        echo '<tr><td data-label="' . h(t('Date')) . '">' . date('d.m.Y H:i', (int)$c['time']) . '</td><td data-label="' . h(t('Node')) . '">' . (int)$c['node'] .
            '</td><td data-label="' . h(t('Duration')) . '">' . h($dur === null ? t('unknown') : a_duration($dur)) . '</td></tr>';
    }
    echo '</table></div></div>';
}

/** 75 seconds as "1:15 min", an hour and more as "1:02 h". */
function a_duration(int $s): string
{
    if ($s >= 3600) {
        return sprintf('%d:%02d h', intdiv($s, 3600), intdiv($s % 3600, 60));
    }
    return sprintf('%d:%02d min', intdiv($s, 60), $s % 60);
}

/** ZIP with all data of a user as JSON and as text, built in data/tmp and removed after sending. */
function a_user_export(array $admin, array $u): never
{
    $data = cb_user_export((int)$u['id']);
    if (!class_exists('ZipArchive')) {
        a_flash(t('Your webspace has no ZipArchive, so the export cannot be created.'), 'bad');
        a_go('user', ['id' => (int)$u['id']]);
    }
    $file = CB_DATA . '/tmp/export_' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('daten.json', (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('daten.txt', a_export_text($data));
    $zip->close();
    cb_log((int)$admin['id'], $admin['handle'], 'Data export for ' . $u['handle']);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $name = 'daten-' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$u['handle']) . '-' . date('Y-m-d') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    @unlink($file);
    exit;
}

/** The export as readable text in the language of the board. */
function a_export_text(array $d): string
{
    $sections = ['account' => t('Account'), 'questionnaire' => t('Questionnaire'), 'calls' => t('Calls'), 'public_messages' => t('Public messages'),
        'private_mail' => t('Private mail'), 'uploads' => t('Uploads'), 'downloads' => t('Downloads'), 'oneliners' => t('Oneliners'),
        'meeting_point' => t('Meeting point'), 'poll_votes' => t('Votes in polls'), 'high_scores' => t('High scores'), 'reports_made' => t('Reports made'),
        'log' => t('Log')];
    $out = t('Data of {1} at {2}, {3}', $d['account']['handle'], $d['board'], date('d.m.Y H:i', (int)strtotime($d['generated']))) . "\n\n";
    // one line per value, lists and groups indented below their key
    $line = static function (string $indent, string $key, $v) use (&$line): string {
        if (is_array($v)) {
            $s = $indent . $key . "\n";
            foreach ($v as $k => $x) {
                $s .= $line($indent . '  ', is_int($k) ? '-' : $k . ':', $x);
            }
            return $s;
        }
        $v = $v === null ? '-' : (is_bool($v) ? ($v ? t('yes') : t('no')) : (string)$v);
        return $indent . $key . ' ' . str_replace("\n", "\n" . $indent . '  ', $v) . "\n";
    };
    foreach ($sections as $key => $title) {
        $out .= '== ' . $title . "\n";
        if ($d[$key] === []) {
            $out .= t('none') . "\n\n";
            continue;
        }
        foreach ($d[$key] as $k => $v) {
            $out .= $line('', is_int($k) ? '-' : $k . ':', $v);
        }
        $out .= "\n";
    }
    return $out;
}

function page_levels(array $admin): void
{
    if (a_post()) {
        if (isset($_POST['save_rules']) || isset($_POST['del_rule'])) {
            a_rules_post();
        }
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
        $sysop = Settings::int('sysop_level', 255);
        foreach ((array)($_POST['lv'] ?? []) as $lvl => $row) {
            $role = (string)($row['backend'] ?? 'none');
            DB::q('UPDATE {levels} SET backend=? WHERE level=?', [(int)$lvl >= $sysop ? 'full' : (in_array($role, ['moderator', 'full'], true) ? $role : 'none'), (int)$lvl]);
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
        '</th><th>' . h(t('Minutes per day')) . '</th><th>' . h(t('Download KB per day')) . '</th><th>' . h(t('Ratio')) . '</th><th>' . h(t('Backend')) . '</th><th></th></tr>';
    $roles = ['none' => t('none'), 'moderator' => t('Moderator'), 'full' => t('full')];
    foreach (DB::all('SELECT * FROM {levels} ORDER BY level') as $l) {
        $k = (int)$l['level'];
        echo "<tr><td class=\"num\">$k</td><td><input name=\"lv[$k][name]\" value=\"" . h($l['name']) . "\"></td>" .
            "<td><input type=\"number\" name=\"lv[$k][minutes]\" value=\"" . (int)$l['minutes'] . "\"></td>" .
            "<td><input type=\"number\" name=\"lv[$k][dl_kb]\" value=\"" . (int)$l['dl_kb'] . "\"></td>" .
            "<td><input type=\"number\" name=\"lv[$k][ratio]\" value=\"" . (int)$l['ratio'] . "\"></td>" . a_role_select($k, (string)$l['backend'], $roles) .
            '<td><button class="btn small danger ghost" name="del" value="' . $k . '" onclick="return confirm(' . h(json_encode(t('Delete this level?'))) . ')">' . h(t('Delete')) . '</button></td></tr>';
    }
    echo '<tr><td><input type="number" name="new_level" min="1" max="255" placeholder="' . h(t('new')) . '"></td><td><input name="new_name" placeholder="' . h(t('Name')) . '"></td>' .
        '<td><input type="number" name="new_minutes" value="60"></td><td><input type="number" name="new_dl" value="0"></td><td><input type="number" name="new_ratio" value="0"></td><td></td><td></td></tr>';
    echo '</table></div><p><button class="btn" type="submit">' . h(t('Save levels')) . '</button></p></form>';

    $on = Settings::get('auto_level', '0') === '1';
    $sysop = Settings::int('sysop_level', 255);
    $rules = DB::all('SELECT * FROM {level_rules} ORDER BY id');
    echo '<h2>' . h(t('Automatic promotion')) . '</h2><p class="note">' .
        h(t('A rule gives a user a higher level at the login: who has at least the first level and less than the new one and meets all conditions gets the new level. 0 means the condition is not checked. Posts are public messages, uploads are approved files. Nobody is ever lowered, and locked or waiting users, sysops and users with the mark "Do not promote automatically" are left out.')) .
        '</p><form method="post" class="form">' . a_csrf() . '<label class="inline"><input type="checkbox" name="auto_level"' . ($on ? ' checked' : '') . '> ' .
        h(t('Promote automatically')) . '</label><div class="tablewrap"><table class="rules"><tr><th>' . h(t('Rule')) . '</th><th>' . h(t('From level')) . '</th><th>' .
        h(t('New level')) . '</th><th>' . h(t('Calls')) . '</th><th>' . h(t('Days since registration')) . '</th><th>' . h(t('Posts')) . '</th><th>' .
        h(t('Uploads')) . '</th><th>' . h(t('Active')) . '</th><th></th></tr>';
    $row = static function (string $key, array $r, string $label) use ($sysop): string {
        $h = '<tr><td class="num">' . h($label) . '</td><td>' . a_level_select("ru[$key][from_level]", (int)$r['from_level'], $sysop - 1) . '</td><td>' .
            a_level_select("ru[$key][to_level]", (int)$r['to_level'], $sysop - 1) . '</td>';
        foreach (['min_calls', 'min_days', 'min_posts', 'min_uploads'] as $f) {
            $h .= '<td><input type="number" min="0" name="ru[' . $key . '][' . $f . ']" value="' . (int)$r[$f] . '"></td>';
        }
        return $h . '<td class="check"><input type="checkbox" name="ru[' . $key . '][active]"' . ((int)$r['active'] ? ' checked' : '') . ' aria-label="' . h(t('Active')) . '"></td>';
    };
    foreach ($rules as $r) {
        echo $row((string)(int)$r['id'], $r, (string)(int)$r['id']) . '<td><button class="btn small danger ghost" name="del_rule" value="' . (int)$r['id'] .
            '" onclick="return confirm(' . h(json_encode(t('Delete this rule?'))) . ')">' . h(t('Delete')) . '</button></td></tr>';
    }
    if (count($rules) < 5) {
        $new = Settings::int('new_level', 10);
        echo $row('new', ['from_level' => $new, 'to_level' => $new, 'min_calls' => 0, 'min_days' => 0, 'min_posts' => 0, 'min_uploads' => 0, 'active' => 1], t('new')) . '<td></td></tr>';
    }
    echo '</table></div><p><button class="btn" name="save_rules" value="1">' . h(t('Save automatic promotion')) . '</button></p></form>';
    if (!$on) {
        echo '<p class="note">' . h(t('The automatic promotion is switched off, the rules do nothing.')) . '</p>';
    } elseif ($rules) {
        $would = a_auto_preview();
        echo '<div class="panel"><p>' . h(t('At the next call {1} user(s) would be promoted.', count($would))) . '</p>';
        if ($would) {
            echo '<ul class="promo">';
            foreach (array_slice($would, 0, 50) as [$u, $to]) {
                echo '<li><a href="' . h(a_url('user', ['id' => (int)$u['id']])) . '">' . h($u['handle']) . '</a> ' . h(t('level {1} to {2}', (int)$u['level'], $to)) . '</li>';
            }
            echo '</ul>';
            if (count($would) > 50) {
                echo '<p class="note">' . h(t('and {1} more', count($would) - 50)) . '</p>';
            }
        }
        echo '</div>';
    }
}
