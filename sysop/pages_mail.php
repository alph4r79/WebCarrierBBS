<?php
/**
 * Sysop backend: reading, answering and writing messages, the own private mail, search in the
 * public messages and circular letters.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Names of the message areas, id => name. */
function a_marea_names(): array
{
    $names = [];
    foreach (DB::all('SELECT id, name FROM {msg_areas} ORDER BY sort, id') as $a) {
        $names[(int)$a['id']] = $a['name'];
    }
    return $names;
}

/** Date from a field of type date as timestamp (start or end of the day), 0 if empty or invalid. */
function a_date_ts(string $v, bool $end = false): int
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return 0;
    }
    return (int)strtotime($v . ($end ? ' 23:59:59' : ' 00:00:00'));
}

/** Head of a message: area or "private", from, to, subject, date. */
function a_msg_head(array $m, array $names): string
{
    $where = (int)$m['private'] === 1 ? t('private') : ($names[(int)$m['area_id']] ?? '?');
    return '<dl class="msghead"><dt>' . h(t('Area')) . '</dt><dd>' . h($where) . '</dd><dt>' . h(t('From')) . '</dt><dd>' . h($m['from_name']) . '</dd><dt>' .
        h(t('To')) . '</dt><dd>' . h($m['to_name']) . '</dd><dt>' . h(t('Subject')) . '</dt><dd>' . h($m['subject']) . '</dd><dt>' . h(t('Date')) . '</dt><dd>' .
        date('d.m.Y H:i', (int)$m['posted']) . '</dd></dl>';
}

/**
 * Form to write a message. $f: area (null for private mail), to, subj, body, reply_to.
 * $areas: the selectable areas for public messages.
 */
function a_msg_form(string $page, array $f, array $areas, string $button): string
{
    $h = '<form method="post" action="' . h(a_url($page)) . '" class="form panel msgform">' . a_csrf();
    if ($f['area'] !== null) {
        $h .= '<label>' . h(t('Area')) . '<select name="area">';
        foreach ($areas as $id => $n) {
            $h .= '<option value="' . $id . '"' . ($id === (int)$f['area'] ? ' selected' : '') . '>' . h($n) . '</option>';
        }
        $h .= '</select></label>';
    }
    $h .= '<label>' . h(t('To')) . '<input name="to" maxlength="20" value="' . h((string)$f['to']) . '"' . ($f['area'] === null ? ' required' : '') . '>' .
        ($f['area'] !== null ? '<span class="hint">' . h(t('Empty: to all.')) . '</span>' : '') . '</label>' .
        '<label>' . h(t('Subject')) . '<input name="subject" maxlength="60" required value="' . h((string)$f['subj']) . '"></label>' .
        '<label>' . h(t('Text')) . '<textarea name="body" class="mono" rows="14" required>' . "\n" . h((string)$f['body']) . '</textarea>' .
        '<span class="hint">' . h(t('Lines longer than 75 characters are wrapped when saving, like in the editor of the terminal. Your signature is added below.')) . '</span></label>' .
        '<input type="hidden" name="reply_to" value="' . (int)$f['reply_to'] . '">' .
        '<p class="row-actions"><button class="btn" name="post_msg" value="1">' . h($button) . '</button></p></form>';
    return $h;
}

/** Subject of an answer: "Re: " once. */
function a_re(string $subj): string
{
    return mb_substr(preg_match('/^re:/i', $subj) ? $subj : 'Re: ' . $subj, 0, 60);
}

/* ------------------------------------------------------------------ public messages */

function page_messages(array $admin): void
{
    $names = a_marea_names();
    $area = (int)($_GET['area'] ?? 0);
    $view = (int)($_GET['view'] ?? 0);
    $q = trim((string)($_GET['q'] ?? ''));
    $from = (string)($_GET['from'] ?? '');
    $to = (string)($_GET['to'] ?? '');
    $filter = array_filter(['area' => $area, 'q' => $q, 'from' => $from, 'to' => $to]);
    if (a_post()) {
        if (isset($_POST['bulk'])) {
            a_messages_bulk($admin, $names, $filter + array_filter(['view' => $view]));
        }
        if (isset($_POST['post_msg'])) {
            a_messages_post($admin, $names);
        }
        if (isset($_POST['circ_prepare']) || isset($_POST['circ_send']) || isset($_POST['circ_next'])) {
            a_circular_post($admin);
        }
        a_go('messages', $filter);
    }

    echo '<h1>' . h(t('Messages')) . '</h1>';
    if (isset($_GET['reply'])) {
        $m = DB::row('SELECT * FROM {messages} WHERE id=? AND private=0', [(int)$_GET['reply']]);
        if ($m) {
            echo '<h2>' . h(t('Answer')) . '</h2>' . a_msg_head($m, $names) . a_msg_form('messages', ['area' => (int)$m['area_id'], 'to' => $m['from_name'],
                    'subj' => a_re((string)$m['subject']), 'body' => implode("\n", cb_quote_lines($m)) . "\n\n", 'reply_to' => (int)$m['id']], $names, t('Send answer'));
            return;
        }
    }
    if (isset($_GET['compose'])) {
        echo '<h2>' . h(t('New message')) . '</h2>' . a_msg_form('messages', ['area' => $area ?: (int)array_key_first($names), 'to' => '', 'subj' => '', 'body' => '',
                'reply_to' => 0], $names, t('Send message'));
        return;
    }
    if ($view > 0) {
        a_message_view($admin, $names, $view, $filter);
        return;
    }
    echo '<p class="row-actions"><a class="btn" href="' . h(a_url('messages', ['compose' => 1] + array_filter(['area' => $area]))) . '">' . h(t('New message')) . '</a>' .
        (a_full($admin) ? '<a class="btn ghost" href="#circular">' . h(t('Circular letter')) . '</a>' : '') . '</p>';
    echo '<form method="get" class="form filterbar row-actions"><input type="hidden" name="p" value="messages">' .
        '<input name="q" value="' . h($q) . '" placeholder="' . h(t('Search subject, text and sender')) . '" aria-label="' . h(t('Search')) . '">' .
        '<select name="area" aria-label="' . h(t('Area')) . '"><option value="0">' . h(t('All areas')) . '</option>';
    foreach ($names as $aid => $n) {
        echo '<option value="' . $aid . '"' . ($aid === $area ? ' selected' : '') . '>' . h($n) . '</option>';
    }
    echo '</select><label class="inline">' . h(t('From')) . ' <input type="date" name="from" value="' . h($from) . '"></label>' .
        '<label class="inline">' . h(t('to')) . ' <input type="date" name="to" value="' . h($to) . '"></label>' .
        '<button class="btn" type="submit">' . h(t('Show')) . '</button>' . ($filter ? '<a href="' . h(a_url('messages')) . '">' . h(t('Reset')) . '</a>' : '') . '</form>';
    $where = ['private=0'];
    $par = [];
    if ($area) {
        $where[] = 'area_id=?';
        $par[] = $area;
    }
    if (mb_strlen($q) >= 2) {
        [$cond, $p] = cb_msg_search($q);
        $where[] = $cond;
        $par = array_merge($par, $p);
    }
    if (($t = a_date_ts($from)) > 0) {
        $where[] = 'posted>=?';
        $par[] = $t;
    }
    if (($t = a_date_ts($to, true)) > 0) {
        $where[] = 'posted<=?';
        $par[] = $t;
    }
    $rows = DB::all('SELECT * FROM {messages} WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 200', $par);
    if (!$rows) {
        echo '<div class="panel"><p>' . h($q !== '' ? t('No message found for "{1}".', $q) : t('No messages.')) . '</p></div>';
    } else {
        if ($q !== '' || $from !== '' || $to !== '') {
            echo '<p class="note">' . h(t('{1} hit(s), at most 200 are shown.', count($rows))) . '</p>';
        }
        $all = 'for(const c of this.form.querySelectorAll(\'input[name="ids[]"]\'))c.checked=this.checked';
        echo '<form method="post" action="' . h(a_url('messages', $filter)) . '">' . a_csrf() .
            '<div class="tablewrap"><table class="cards"><tr><th class="check"><input type="checkbox" onclick="' . h($all) . '" aria-label="' . h(t('Select all')) . '"></th><th>' .
            h(t('Date')) . '</th><th>' . h(t('Area')) . '</th><th>' . h(t('From')) . '</th><th>' . h(t('To')) . '</th><th>' . h(t('Subject')) . '</th></tr>';
        foreach ($rows as $m) {
            echo '<tr><td class="check"><input type="checkbox" name="ids[]" value="' . (int)$m['id'] . '" aria-label="' . h($m['subject']) . '"></td>' .
                '<td class="num" data-label="' . h(t('Date')) . '">' . date('d.m.y H:i', (int)$m['posted']) . '</td><td data-label="' . h(t('Area')) . '">' .
                h($names[(int)$m['area_id']] ?? '?') . '</td><td data-label="' . h(t('From')) . '">' . h($m['from_name']) . '</td><td data-label="' . h(t('To')) . '">' .
                h($m['to_name']) . '</td><td class="subj"><a href="' . h(a_url('messages', $filter + ['view' => (int)$m['id']])) . '">' . h($m['subject']) . '</a></td></tr>';
        }
        echo '</table></div><div class="bulk"><span class="note">' . h(t('Selected messages:')) . '</span>' .
            '<button class="btn small danger ghost" name="bulk" value="delete" onclick="return confirm(' . h(json_encode(t('Delete the selected messages?'))) . ')">' .
            h(t('Delete')) . '</button><span class="move">' . a_marea_move_select($names, 0) .
            '<button class="btn small ghost" name="bulk" value="move">' . h(t('Move')) . '</button></span></div></form>';
    }
    if (a_full($admin)) {
        a_circular_form();
    }
}

function a_message_view(array $admin, array $names, int $view, array $filter): void
{
    $m = DB::row('SELECT * FROM {messages} WHERE id=? AND private=0', [$view]);
    if (!$m) {
        echo '<div class="panel"><p>' . h(t('The message does not exist.')) . '</p></div>';
        return;
    }
    $prev = DB::val('SELECT MAX(id) FROM {messages} WHERE area_id=? AND private=0 AND id<?', [(int)$m['area_id'], $view]);
    $next = DB::val('SELECT MIN(id) FROM {messages} WHERE area_id=? AND private=0 AND id>?', [(int)$m['area_id'], $view]);
    $orig = (int)$m['reply_to'] > 0 ? DB::row('SELECT id FROM {messages} WHERE id=? AND private=0', [(int)$m['reply_to']]) : null;
    $replies = DB::all('SELECT id, from_name, subject, posted FROM {messages} WHERE reply_to=? AND private=0 ORDER BY id', [$view]);
    echo '<nav class="row-actions msgnav"><a class="btn small ghost" href="' . h(a_url('messages', $filter)) . '">' . h(t('Back to the list')) . '</a>' .
        ($prev ? '<a class="btn small ghost" href="' . h(a_url('messages', $filter + ['view' => (int)$prev])) . '">' . h(t('Previous')) . '</a>' : '') .
        ($next ? '<a class="btn small ghost" href="' . h(a_url('messages', $filter + ['view' => (int)$next])) . '">' . h(t('Next')) . '</a>' : '') . '</nav>';
    echo '<article class="panel">' . a_msg_head($m, $names) . a_msg_body_html((string)$m['body']);
    if ((int)$m['reply_to'] > 0) {
        echo '<p class="note">' . ($orig ? '<a href="' . h(a_url('messages', $filter + ['view' => (int)$orig['id']])) . '">' . h(t('To the original message')) . '</a>'
                : h(t('The original message no longer exists.'))) . '</p>';
    }
    if ($replies) {
        echo '<h3>' . h(t('Answers to it')) . '</h3><ul class="replies">';
        foreach ($replies as $r) {
            echo '<li><a href="' . h(a_url('messages', $filter + ['view' => (int)$r['id']])) . '">' . h($r['subject']) . '</a> <span class="note">' .
                h($r['from_name'] . ', ' . date('d.m.y H:i', (int)$r['posted'])) . '</span></li>';
        }
        echo '</ul>';
    }
    echo '<div class="row-actions msgactions"><a class="btn" href="' . h(a_url('messages', ['reply' => $view])) . '">' . h(t('Answer')) . '</a>' .
        '<form method="post" action="' . h(a_url('messages', $filter + ['view' => $view])) . '" class="bulk">' . a_csrf() .
        '<input type="hidden" name="ids[]" value="' . $view . '"><span class="move">' . a_marea_move_select($names, (int)$m['area_id']) .
        '<button class="btn small ghost" name="bulk" value="move">' . h(t('Move')) . '</button></span>' .
        '<button class="btn small danger ghost" name="bulk" value="delete" onclick="return confirm(' . h(json_encode(t('Delete this message?'))) . ')">' .
        h(t('Delete')) . '</button></form></div></article>';
}

function a_messages_bulk(array $admin, array $names, array $back): never
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    $op = (string)$_POST['bulk'];
    $target = a_int('move_to');
    if (!$ids) {
        a_flash(t('No messages selected.'), 'warn');
    } elseif ($op === 'delete') {
        $n = 0;
        foreach ($ids as $mid) {
            $n += DB::q('DELETE FROM {messages} WHERE id=? AND private=0', [$mid])->rowCount();
        }
        cb_log((int)$admin['id'], $admin['handle'], 'Deleted ' . $n . ' message(s)');
        a_flash(t('{1} message(s) deleted.', $n));
        unset($back['view']);
    } elseif ($op === 'move') {
        if (!isset($names[$target])) {
            a_flash(t('Please choose a message area.'), 'bad');
        } else {
            $n = 0;
            foreach ($ids as $mid) {
                $n += DB::q('UPDATE {messages} SET area_id=? WHERE id=? AND private=0', [$target, $mid])->rowCount();
            }
            cb_log((int)$admin['id'], $admin['handle'], 'Moved ' . $n . ' message(s) to area ' . $target);
            a_flash(t('{1} message(s) moved to {2}.', $n, $names[$target]));
        }
    }
    a_go('messages', $back);
}

/** A public message from the backend: new or as an answer, from the logged in account. */
function a_messages_post(array $admin, array $names): never
{
    $area = a_int('area');
    $reply = DB::row('SELECT * FROM {messages} WHERE id=? AND private=0', [a_int('reply_to')]);
    [$body, $err] = cb_backend_body((string)($_POST['body'] ?? ''));
    $subj = a_in('subject', 60);
    $to = a_in('to', 20);
    if (!isset($names[$area])) {
        $err = t('Please choose a message area.');
    } elseif ($subj === '' || trim($body) === '') {
        $err = t('Subject and text must not be empty.');
    } elseif ($err !== null) {
        $err = t('The text has more than {1} lines.', $err);
    }
    if ($err !== null) {
        a_flash($err, 'bad');
        a_go('messages', $reply ? ['reply' => (int)$reply['id']] : ['compose' => 1, 'area' => $area]);
    }
    $u = $to !== '' ? DB::row('SELECT id, handle FROM {users} WHERE handle_lc=? AND pending=0', [mb_strtolower($to)]) : null;
    $id = cb_post_message($admin, ['area_id' => $area, 'to_id' => $u ? (int)$u['id'] : 0, 'to_name' => $u ? $u['handle'] : ($to !== '' ? $to : Lang::get('kw_all')),
        'subject' => $subj, 'body' => $body, 'reply_to' => $reply ? (int)$reply['id'] : 0, 'private' => 0]);
    cb_log((int)$admin['id'], $admin['handle'], 'Wrote message ' . $id . ' in area ' . $area . ' (backend)');
    a_flash(t('Message saved.'));
    a_go('messages', ['view' => $id]);
}

/* ------------------------------------------------------------------ circular letters */

/** Recipients of a circular: not locked, not waiting, not the sender; from a level, called within 90 days. */
function a_circular_recipients(int $self, int $minLevel, bool $recent): array
{
    $sql = 'SELECT id FROM {users} WHERE locked=0 AND pending=0 AND id<>? AND level>=?';
    $par = [$self, $minLevel];
    if ($recent) {
        $sql .= ' AND last_call>=?';
        $par[] = time() - 90 * 86400;
    }
    return array_map('intval', array_column(DB::all($sql . ' ORDER BY id', $par), 'id'));
}

function a_circular_form(): void
{
    $c = $_SESSION['cb_circ'] ?? null;
    echo '<section class="panel" id="circular"><h2>' . h(t('Circular letter')) . '</h2>';
    if (is_array($c) && !empty($c['ids'])) {
        $done = (int)$c['done'];
        $total = count($c['ids']);
        if ($done === 0 && empty($c['go'])) {
            echo '<p>' . h(t('"{1}" goes as private mail to {2} user(s).', $c['subject'], $total)) . '</p><form method="post" action="' . h(a_url('messages')) . '" class="row-actions">' .
                a_csrf() . '<button class="btn" name="circ_send" value="1">' . h(t('Send to {1} user(s) now', $total)) . '</button>' .
                '<button class="btn ghost" name="circ_send" value="cancel">' . h(t('Cancel')) . '</button></form></section>';
            return;
        }
        echo '<p>' . h(t('{1} of {2} sent.', $done, $total)) . '</p><form method="post" action="' . h(a_url('messages')) . '" id="circ-next">' . a_csrf() .
            '<button class="btn" name="circ_next" value="1">' . h(t('Continue')) . '</button></form>' .
            '<script>document.getElementById("circ-next").querySelector("button").click();</script></section>';
        return;
    }
    echo '<p class="note">' . h(t('A private message to many users at once, with your signature. Locked users, users waiting for validation and your own account get nothing. At most 5000 recipients.')) . '</p>' .
        '<form method="post" action="' . h(a_url('messages')) . '" class="form">' . a_csrf() .
        '<label>' . h(t('Subject')) . '<input name="subject" maxlength="60" required></label>' .
        '<label>' . h(t('Text')) . '<textarea name="body" class="mono" rows="8" required></textarea></label>' .
        '<fieldset><label class="inline"><input type="radio" name="scope" value="all" checked> ' . h(t('All users')) . '</label>' .
        '<label class="inline"><input type="radio" name="scope" value="level"> ' . h(t('All users from level')) . ' ' . a_level_select('level', Settings::int('new_level', 10)) . '</label>' .
        '<label class="inline"><input type="checkbox" name="recent" value="1"> ' . h(t('Only users who called in the last 90 days')) . '</label></fieldset>' .
        '<button class="btn" name="circ_prepare" value="1">' . h(t('Check recipients')) . '</button></form></section>';
}

/** Prepare, confirm and send a circular. Sending runs in steps of 500, each step is a request of its own. */
function a_circular_post(array $admin): never
{
    if (isset($_POST['circ_prepare'])) {
        [$body, $err] = cb_backend_body((string)($_POST['body'] ?? ''));
        $subj = a_in('subject', 60);
        $ids = a_circular_recipients((int)$admin['id'], ($_POST['scope'] ?? '') === 'level' ? a_int('level', 0, 255) : 0, isset($_POST['recent']));
        if ($subj === '' || trim($body) === '') {
            a_flash(t('Subject and text must not be empty.'), 'bad');
        } elseif ($err !== null) {
            a_flash(t('The text has more than {1} lines.', $err), 'bad');
        } elseif (!$ids) {
            a_flash(t('No user matches.'), 'warn');
        } elseif (count($ids) > 5000) {
            a_flash(t('{1} users match, at most 5000 are possible. Please narrow the recipients.', count($ids)), 'bad');
        } else {
            $_SESSION['cb_circ'] = ['subject' => $subj, 'body' => $body, 'ids' => $ids, 'done' => 0];
        }
        a_go('messages', ['circ' => 1]);
    }
    $c = &$_SESSION['cb_circ'];
    if (!is_array($c) || ($_POST['circ_send'] ?? '') === 'cancel') {
        unset($_SESSION['cb_circ']);
        a_go('messages');
    }
    $c['go'] = true;
    @set_time_limit(120);
    $users = [];
    $part = array_slice($c['ids'], (int)$c['done'], 500);
    if ($part) {
        foreach (DB::all('SELECT id, handle FROM {users} WHERE id IN (' . implode(',', array_fill(0, count($part), '?')) . ') AND locked=0 AND pending=0', $part) as $u) {
            $users[(int)$u['id']] = $u['handle'];
        }
    }
    DB::$pdo->beginTransaction();
    foreach ($part as $uid) {
        if (isset($users[$uid])) {
            // a circular is no post of the sender in his statistics
            cb_post_message($admin, ['to_id' => $uid, 'to_name' => $users[$uid], 'subject' => $c['subject'], 'body' => $c['body'], 'private' => 1], false);
        }
    }
    DB::$pdo->commit();
    $c['done'] = (int)$c['done'] + count($part);
    if ($c['done'] >= count($c['ids'])) {
        cb_log((int)$admin['id'], $admin['handle'], 'Circular "' . mb_substr($c['subject'], 0, 60) . '" to ' . count($c['ids']) . ' user(s)');
        a_flash(t('Circular letter sent to {1} user(s).', count($c['ids'])));
        unset($_SESSION['cb_circ']);
        a_go('messages');
    }
    a_go('messages', ['circ' => 1]);
}

/* ------------------------------------------------------------------ own private mail */

function page_mail(array $admin): void
{
    $me = (int)$admin['id'];
    $box = ($_GET['box'] ?? '') === 'sent' ? 'sent' : 'in';
    $q = trim((string)($_GET['q'] ?? ''));
    if (a_post() && isset($_POST['post_msg'])) {
        [$body, $err] = cb_backend_body((string)($_POST['body'] ?? ''));
        $subj = a_in('subject', 60);
        $to = DB::row('SELECT * FROM {users} WHERE handle_lc=?', [mb_strtolower(a_in('to', 30))]);
        $reply = DB::row('SELECT * FROM {messages} WHERE id=? AND private=1 AND (to_id=? OR from_id=?)', [a_int('reply_to'), $me, $me]);
        if (!$to || (int)$to['locked'] === 1 || (int)$to['pending'] === 1) {
            $err = t('There is no user {1} who can get mail.', a_in('to', 30));
        } elseif ($subj === '' || trim($body) === '') {
            $err = t('Subject and text must not be empty.');
        } elseif ($err !== null) {
            $err = t('The text has more than {1} lines.', $err);
        }
        if ($err !== null) {
            a_flash($err, 'bad');
            $_SESSION['cb_mail_draft'] = ['to' => a_in('to', 30), 'subj' => $subj, 'body' => (string)($_POST['body'] ?? ''), 'reply_to' => $reply ? (int)$reply['id'] : 0];
            a_go('mail', ['compose' => 1]);
        }
        $id = cb_post_message($admin, ['to_id' => (int)$to['id'], 'to_name' => $to['handle'], 'subject' => $subj, 'body' => $body,
            'reply_to' => $reply ? (int)$reply['id'] : 0, 'private' => 1]);
        cb_log($me, $admin['handle'], 'Wrote private mail to ' . $to['handle'] . ' (backend)');
        a_flash(t('Mail to {1} sent.', $to['handle']));
        a_go('mail', ['box' => 'sent', 'view' => $id]);
    }
    if (a_post() && isset($_POST['del'])) {
        $n = DB::q('DELETE FROM {messages} WHERE id=? AND private=1 AND to_id=?', [(int)$_POST['del'], $me])->rowCount();
        if ($n) {
            cb_log($me, $admin['handle'], 'Deleted a private mail (backend)');
            a_flash(t('Mail deleted.'));
        }
        a_go('mail');
    }
    echo '<h1>' . h(t('My mail')) . '</h1>';
    $unread = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE private=1 AND to_id=? AND is_read=0', [$me]);
    echo '<p class="row-actions filters"><a href="' . h(a_url('mail')) . '"' . ($box === 'in' ? ' aria-current="page"' : '') . '>' . h(t('Inbox ({1} new)', $unread)) . '</a>' .
        '<a href="' . h(a_url('mail', ['box' => 'sent'])) . '"' . ($box === 'sent' ? ' aria-current="page"' : '') . '>' . h(t('Sent')) . '</a>' .
        '<a class="btn small" href="' . h(a_url('mail', ['compose' => 1])) . '">' . h(t('Write mail')) . '</a></p>';
    if (isset($_GET['compose']) || isset($_GET['reply'])) {
        $draft = $_SESSION['cb_mail_draft'] ?? null;
        unset($_SESSION['cb_mail_draft']);
        $f = is_array($draft) ? $draft : ['to' => (string)($_GET['to'] ?? ''), 'subj' => (string)($_GET['subj'] ?? ''), 'body' => '', 'reply_to' => 0];
        if (isset($_GET['reply'])) {
            $m = DB::row('SELECT * FROM {messages} WHERE id=? AND private=1 AND to_id=?', [(int)$_GET['reply'], $me]);
            if ($m) {
                $f = ['to' => $m['from_name'], 'subj' => a_re((string)$m['subject']), 'body' => implode("\n", cb_quote_lines($m)) . "\n\n", 'reply_to' => (int)$m['id']];
                echo a_msg_head($m, []);
            }
        }
        echo a_msg_form('mail', ['area' => null] + $f, [], t('Send mail'));
        return;
    }
    if (isset($_GET['view'])) {
        // only mail of the own account, also with a changed address
        $m = DB::row('SELECT * FROM {messages} WHERE id=? AND private=1 AND (to_id=? OR from_id=?)', [(int)$_GET['view'], $me, $me]);
        if (!$m) {
            echo '<div class="panel"><p>' . h(t('The message does not exist.')) . '</p></div>';
            return;
        }
        if ((int)$m['to_id'] === $me && (int)$m['is_read'] === 0) {
            DB::q('UPDATE {messages} SET is_read=1 WHERE id=?', [(int)$m['id']]);
        }
        echo '<article class="panel">' . a_msg_head($m, []) . a_msg_body_html((string)$m['body']) . '<div class="row-actions msgactions">';
        if ((int)$m['to_id'] === $me) {
            echo '<a class="btn" href="' . h(a_url('mail', ['reply' => (int)$m['id']])) . '">' . h(t('Answer')) . '</a><form method="post" action="' . h(a_url('mail')) . '"' .
                a_confirm(t('Delete this mail?')) . '>' . a_csrf() . '<button class="btn small danger ghost" name="del" value="' . (int)$m['id'] . '">' . h(t('Delete')) . '</button></form>';
        }
        echo '<a class="btn small ghost" href="' . h(a_url('mail', $box === 'sent' ? ['box' => 'sent'] : [])) . '">' . h(t('Back to the list')) . '</a></div></article>';
        return;
    }
    echo '<form method="get" class="form filterbar row-actions"><input type="hidden" name="p" value="mail">' . ($box === 'sent' ? '<input type="hidden" name="box" value="sent">' : '') .
        '<input name="q" value="' . h($q) . '" placeholder="' . h(t('Search your mail')) . '" aria-label="' . h(t('Search')) . '"><button class="btn" type="submit">' .
        h(t('Search')) . '</button></form>';
    $where = 'private=1 AND ' . ($box === 'sent' ? 'from_id=?' : 'to_id=?');
    $par = [$me];
    if (mb_strlen($q) >= 2) {
        [$cond, $p] = cb_msg_search($q);
        $where .= ' AND ' . $cond;
        $par = array_merge($par, $p);
    }
    $rows = DB::all("SELECT * FROM {messages} WHERE $where ORDER BY id DESC LIMIT 200", $par);
    if (!$rows) {
        echo '<div class="panel"><p>' . h(t('No mail.')) . '</p></div>';
        return;
    }
    echo '<div class="tablewrap"><table class="cards"><tr><th>' . h(t('Date')) . '</th><th>' . h($box === 'sent' ? t('To') : t('From')) . '</th><th>' . h(t('Subject')) . '</th></tr>';
    foreach ($rows as $m) {
        $new = $box === 'in' && (int)$m['is_read'] === 0;
        echo '<tr' . ($new ? ' class="unread"' : '') . '><td class="num" data-label="' . h(t('Date')) . '">' . date('d.m.y H:i', (int)$m['posted']) . '</td><td data-label="' .
            h($box === 'sent' ? t('To') : t('From')) . '">' . h($box === 'sent' ? $m['to_name'] : $m['from_name']) . '</td><td class="subj"><a href="' .
            h(a_url('mail', ['view' => (int)$m['id']] + ($box === 'sent' ? ['box' => 'sent'] : []))) . '">' . h($m['subject']) . '</a>' .
            ($new ? ' <span class="tag ok">' . h(t('new')) . '</span>' : '') . '</td></tr>';
    }
    echo '</table></div>';
}
