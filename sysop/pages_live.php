<?php
/**
 * Sysop backend: what happens live. The small status request every page sends, calls of callers
 * with the bar on top of every page, the chat with a caller and the reports of messages.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Calls that wait for a sysop right now. */
function a_waiting_calls(): array
{
    return DB::all("SELECT * FROM {sysop_pages} WHERE status='waiting' AND time>? ORDER BY id", [time() - CB_PAGE_SECONDS]);
}

/** Configuration of assets/sysop.js and the bar with waiting calls, on top of every page. */
function a_live_markup(array $admin): string
{
    $chat = $_SESSION['cb_chat'] ?? null;
    $cfg = [
        'url' => a_url('status'), 'chat' => is_array($chat) && $chat['state'] !== 'ended', 'notify' => Settings::get('tab_notify', '1') === '1',
        'L' => ['calls' => t('{1} (node {2}) is calling you'), 'take' => t('Start chat'), 'decline' => t('Decline'),
            'n_call' => t('Call from {1}'), 'n_report' => t('New report'), 'n_chat' => t('New line in the chat')],
    ];
    $h = '<div id="live" hidden data-csrf="' . h(cb_csrf()) . '" data-cfg="' . h((string)json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '"></div>';
    $h .= '<div id="callbar" class="callbar"' . (a_waiting_calls() ? '' : ' hidden') . '>';
    foreach (a_waiting_calls() as $c) {
        $h .= a_call_html($c);
    }
    return $h . '</div>';
}

function a_call_html(array $c): string
{
    return '<form method="post" action="' . h(a_url('chat')) . '" class="call">' . a_csrf() . '<span>' . h(t('{1} (node {2}) is calling you', $c['handle'], (int)$c['node'])) .
        '</span><button class="btn small" name="take" value="' . (int)$c['id'] . '">' . h(t('Start chat')) . '</button>' .
        '<button class="btn small ghost" name="decline" value="' . (int)$c['id'] . '">' . h(t('Decline')) . '</button></form>';
}

/** JSON for assets/sysop.js: calls, open reports, waiting users and uploads. Marks the account as reachable. */
function page_status(array $admin): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !cb_csrf_ok($_POST['csrf'] ?? null)) {
        echo json_encode(['err' => 'csrf']);
        exit;
    }
    cb_pages_expire();
    a_reports_cleanup();
    $chat = $_SESSION['cb_chat'] ?? null;
    if (!is_array($chat) || $chat['state'] === 'ended') {
        // lines for a chat this session no longer has
        DB::q('DELETE FROM {node_msgs} WHERE node=? AND time<?', [cb_backend_node((int)$admin['id']), time() - 120]);
    }
    echo json_encode([
        'calls' => array_map(static fn($c) => ['id' => (int)$c['id'], 'handle' => $c['handle'], 'node' => (int)$c['node']], a_waiting_calls()),
        'reports' => (int)DB::val("SELECT COUNT(*) FROM {msg_reports} WHERE status='open'"),
        'users' => (int)DB::val('SELECT COUNT(*) FROM {users} WHERE pending=1'),
        'uploads' => (int)DB::val('SELECT COUNT(*) FROM {files} WHERE approved=0'),
        'chat' => is_array($chat) && $chat['state'] !== 'ended',
        'bar' => implode('', array_map('a_call_html', a_waiting_calls())),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------ chat */

/** Start a chat with the caller on $node. False with a flash message if nobody is there. */
function a_chat_start(array $admin, int $node): bool
{
    $row = DB::row('SELECT * FROM {nodes} WHERE node=? AND user_id>0 AND kicked=0', [$node]);
    if (!$row) {
        a_flash(t('Nobody is logged in on node {1}.', $node), 'bad');
        return false;
    }
    $me = cb_backend_node((int)$admin['id']);
    $old = $_SESSION['cb_chat'] ?? null;
    if (is_array($old) && $old['state'] !== 'ended') {
        DB::insert('node_msgs', ['node' => (int)$old['node'], 'kind' => 'end', 'from_node' => $me, 'from_handle' => $admin['handle'], 'text' => '', 'time' => time()]);
    }
    DB::q('DELETE FROM {node_msgs} WHERE node=?', [$me]);
    DB::insert('node_msgs', ['node' => $node, 'kind' => 'chat', 'from_node' => $me, 'from_handle' => $admin['handle'], 'text' => '', 'time' => time()]);
    DB::q("UPDATE {sysop_pages} SET status='taken' WHERE node=? AND status='waiting'", [$node]);
    $_SESSION['cb_chat'] = ['node' => $node, 'handle' => (string)$row['handle'], 'uid' => (int)$row['user_id'], 'state' => 'wait', 'since' => time(),
        'lines' => [['sys', t('Waiting for {1} on node {2} ...', $row['handle'], $node), time()]]];
    cb_backend_touch((int)$admin['id']);
    cb_log((int)$admin['id'], $admin['handle'], 'Chat with ' . $row['handle'] . ' on node ' . $node . ' (backend)');
    return true;
}

/** New lines from the caller, the end of the chat and whether he is still there. */
function a_chat_sync(array $admin): void
{
    $chat = &$_SESSION['cb_chat'];
    if (!is_array($chat)) {
        return;
    }
    $me = cb_backend_node((int)$admin['id']);
    foreach (DB::all('SELECT * FROM {node_msgs} WHERE node=? ORDER BY id', [$me]) as $r) {
        DB::q('DELETE FROM {node_msgs} WHERE id=?', [(int)$r['id']]);
        if ((int)$r['from_node'] !== (int)$chat['node'] || $chat['state'] === 'ended') {
            continue;
        }
        if ($r['kind'] === 'line') {
            $chat['state'] = 'open';
            $chat['lines'][] = ['peer', (string)$r['text'], (int)$r['time']];
        } elseif ($r['kind'] === 'page') {
            // the caller joined: the terminal sends its own notice with pipe codes
            $chat['state'] = 'open';
            $chat['lines'][] = ['sys', str_replace('||', '|', preg_replace('/\|\d\d/', '', (string)$r['text']) ?? ''), (int)$r['time']];
        } elseif ($r['kind'] === 'end') {
            $chat['state'] = 'ended';
            $chat['lines'][] = ['sys', t('{1} ended the chat.', $chat['handle']), time()];
        }
    }
    if ($chat['state'] !== 'ended' && !DB::val('SELECT COUNT(*) FROM {nodes} WHERE node=? AND user_id=? AND kicked=0', [(int)$chat['node'], (int)$chat['uid']])) {
        $chat['state'] = 'ended';
        $chat['lines'][] = ['sys', t('{1} is no longer online.', $chat['handle']), time()];
    }
    $chat['lines'] = array_slice($chat['lines'], -300);
}

function a_chat_send(array $admin, string $text): void
{
    $chat = &$_SESSION['cb_chat'];
    $text = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? ''), 0, 70);
    if (!is_array($chat) || $chat['state'] === 'ended' || $text === '') {
        return;
    }
    DB::insert('node_msgs', ['node' => (int)$chat['node'], 'kind' => 'line', 'from_node' => cb_backend_node((int)$admin['id']),
        'from_handle' => $admin['handle'], 'text' => $text, 'time' => time()]);
    $chat['lines'][] = ['me', $text, time()];
}

function a_chat_end(array $admin): void
{
    $chat = &$_SESSION['cb_chat'];
    if (!is_array($chat) || $chat['state'] === 'ended') {
        return;
    }
    DB::insert('node_msgs', ['node' => (int)$chat['node'], 'kind' => 'end', 'from_node' => cb_backend_node((int)$admin['id']),
        'from_handle' => $admin['handle'], 'text' => '', 'time' => time()]);
    $chat['state'] = 'ended';
    $chat['lines'][] = ['sys', t('You ended the chat.'), time()];
    cb_log((int)$admin['id'], $admin['handle'], 'Ended the chat with ' . $chat['handle']);
}

function page_chat(array $admin): void
{
    if (a_post()) {
        if (isset($_POST['take'])) {
            $c = DB::row('SELECT * FROM {sysop_pages} WHERE id=?', [(int)$_POST['take']]);
            if (!$c || $c['status'] !== 'waiting' || (int)$c['time'] < time() - CB_PAGE_SECONDS) {
                a_flash(t('The call is no longer waiting.'), 'warn');
                a_go('dash');
            }
            if (a_chat_start($admin, (int)$c['node'])) {
                a_go('chat');
            }
            a_go('dash');
        }
        if (isset($_POST['decline'])) {
            $c = DB::row('SELECT * FROM {sysop_pages} WHERE id=?', [(int)$_POST['decline']]);
            if ($c && DB::q("UPDATE {sysop_pages} SET status='missed' WHERE id=? AND status='waiting'", [(int)$c['id']])->rowCount()) {
                DB::insert('node_msgs', ['node' => (int)$c['node'], 'kind' => 'pdecl', 'from_node' => cb_backend_node((int)$admin['id']),
                    'from_handle' => $admin['handle'], 'text' => '', 'time' => time()]);
                cb_log((int)$admin['id'], $admin['handle'], 'Declined the page of ' . $c['handle']);
                a_flash(t('Call of {1} declined.', $c['handle']));
            }
            a_go('dash');
        }
        if (isset($_POST['chat_start'])) {
            if (a_chat_start($admin, (int)$_POST['chat_start'])) {
                a_go('chat');
            }
            a_go('dash');
        }
        if (isset($_POST['line'])) {
            a_chat_send($admin, (string)$_POST['line']);
        }
        if (isset($_POST['end'])) {
            a_chat_end($admin);
        }
        a_go('chat');
    }
    a_chat_sync($admin);
    $chat = $_SESSION['cb_chat'] ?? null;
    echo '<h1>' . h(t('Chat')) . '</h1>';
    if (!is_array($chat)) {
        echo '<div class="panel"><p>' . h(t('There is no chat. Start one from a call, from the nodes on the overview or from the page of a user who is online.')) . '</p></div>';
        return;
    }
    $open = $chat['state'] !== 'ended';
    echo '<section class="panel chat" id="chat" data-poll="' . h(a_url('chatpoll')) . '" data-lines="' . count($chat['lines']) . '" data-open="' . ($open ? '1' : '0') . '">' .
        '<p class="chat-head"><strong>' . h($chat['handle']) . '</strong> <span class="note">' . h(t('Node {1}', (int)$chat['node'])) . '</span></p><ol class="chat-lines" id="chat-lines">';
    foreach ($chat['lines'] as [$who, $text, $time]) {
        echo a_chat_line($who, (string)$text, (int)$time, $who === 'peer' ? $chat['handle'] : $admin['handle']);
    }
    echo '</ol>';
    if ($open) {
        echo '<form method="post" class="chat-form" id="chat-form">' . a_csrf() . '<label class="sr-only" for="chat-in">' . h(t('Your line')) . '</label>' .
            '<input id="chat-in" name="line" maxlength="70" autocomplete="off" autofocus placeholder="' . h(t('Your line, Enter sends')) . '">' .
            '<button class="btn" type="submit">' . h(t('Send')) . '</button></form>' .
            '<form method="post" class="chat-end">' . a_csrf() . '<button class="btn small danger ghost" name="end" value="1">' . h(t('End chat')) . '</button></form>';
    } else {
        echo '<p class="note">' . h(t('The chat has ended.')) . '</p>';
    }
    echo '</section>';
}

function a_chat_line(string $who, string $text, int $time, string $name): string
{
    if ($who === 'sys') {
        return '<li class="sys"><span class="t">' . date('H:i', $time) . '</span> ' . h($text) . '</li>';
    }
    return '<li class="' . ($who === 'me' ? 'me' : 'peer') . '"><span class="t">' . date('H:i', $time) . '</span> <b>' . h($name) . '</b> ' . h($text) . '</li>';
}

/** JSON for the chat page every 1.5 seconds: sends a line or ends the chat, returns the new lines. */
function page_chatpoll(array $admin): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !cb_csrf_ok($_POST['csrf'] ?? null)) {
        echo json_encode(['err' => 'csrf']);
        exit;
    }
    if (isset($_POST['line'])) {
        a_chat_send($admin, (string)$_POST['line']);
    }
    if (isset($_POST['end'])) {
        a_chat_end($admin);
    }
    a_chat_sync($admin);
    $chat = $_SESSION['cb_chat'] ?? null;
    if (!is_array($chat)) {
        echo json_encode(['open' => false, 'html' => '', 'n' => 0]);
        exit;
    }
    $since = max(0, (int)($_POST['since'] ?? 0));
    $html = '';
    $peer = false;
    foreach (array_slice($chat['lines'], $since) as [$who, $text, $time]) {
        $html .= a_chat_line($who, (string)$text, (int)$time, $who === 'peer' ? $chat['handle'] : $admin['handle']);
        $peer = $peer || $who === 'peer';
    }
    echo json_encode(['open' => $chat['state'] !== 'ended', 'html' => $html, 'n' => count($chat['lines']), 'peer' => $peer], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------ reports */

/** Reports that were handled more than 90 days ago are removed. */
function a_reports_cleanup(): void
{
    DB::q("DELETE FROM {msg_reports} WHERE status<>'open' AND handled_time<?", [time() - 90 * 86400]);
}

function page_reports(array $admin): void
{
    a_reports_cleanup();
    if (a_post()) {
        $id = (int)($_POST['delete_msg'] ?? $_POST['move'] ?? $_POST['dismiss'] ?? 0);
        $r = DB::row('SELECT * FROM {msg_reports} WHERE id=?', [$id]);
        $m = $r ? DB::row('SELECT * FROM {messages} WHERE id=?', [(int)$r['msg_id']]) : null;
        $done = static fn(string $status) => DB::q("UPDATE {msg_reports} SET status=?, handled_by=?, handled_time=? WHERE msg_id=? AND status='open'",
            [$status, (int)$admin['id'], time(), (int)$r['msg_id']]);
        if ($r && isset($_POST['delete_msg'])) {
            if ($m) {
                DB::q('DELETE FROM {messages} WHERE id=?', [(int)$m['id']]);
            }
            $done('done');
            cb_log((int)$admin['id'], $admin['handle'], 'Report ' . $id . ': deleted message ' . $r['msg_id']);
            a_flash(t('Message deleted, the report is done.'));
        } elseif ($r && isset($_POST['move'])) {
            $target = a_int('move_to');
            if ($m && (int)$m['private'] === 0 && DB::val('SELECT COUNT(*) FROM {msg_areas} WHERE id=?', [$target])) {
                DB::q('UPDATE {messages} SET area_id=? WHERE id=?', [$target, (int)$m['id']]);
                $done('done');
                cb_log((int)$admin['id'], $admin['handle'], 'Report ' . $id . ': moved message ' . $m['id'] . ' to area ' . $target);
                a_flash(t('Message moved, the report is done.'));
            } else {
                a_flash(t('Please choose a message area.'), 'bad');
            }
        } elseif ($r && isset($_POST['dismiss'])) {
            DB::q('UPDATE {msg_reports} SET status=?, handled_by=?, handled_time=? WHERE id=?', ['dismissed', (int)$admin['id'], time(), $id]);
            cb_log((int)$admin['id'], $admin['handle'], 'Report ' . $id . ': dismissed');
            a_flash(t('Report dismissed.'));
        }
        a_go('reports');
    }
    $areas = [];
    foreach (DB::all('SELECT id, name FROM {msg_areas} ORDER BY sort, id') as $a) {
        $areas[(int)$a['id']] = $a['name'];
    }
    $open = DB::all("SELECT r.*, u.handle AS reporter FROM {msg_reports} r LEFT JOIN {users} u ON u.id=r.reporter_id WHERE r.status='open' ORDER BY r.id");
    echo '<h1>' . h(t('Reports')) . '</h1><p class="note">' .
        h(t('Callers report messages with the key ! in the reader. A reported private message is shown here, nothing else of the mailbox.')) . '</p>';
    echo '<h2>' . h(t('Open reports ({1})', count($open))) . '</h2>';
    if (!$open) {
        echo '<div class="panel"><p class="note">' . h(t('There are no open reports.')) . '</p></div>';
    }
    foreach ($open as $r) {
        $m = DB::row('SELECT * FROM {messages} WHERE id=?', [(int)$r['msg_id']]);
        echo '<article class="panel report"><p class="report-meta">' . h(t('Reported by {1} on {2}', $r['reporter'] ?? '?', date('d.m.Y H:i', (int)$r['time']))) .
            ($r['reason'] !== '' ? '<br><strong>' . h(t('Reason:')) . '</strong> ' . h($r['reason']) : '') . '</p>';
        if (!$m) {
            echo '<p><span class="tag bad">' . h(t('Message deleted')) . '</span> ' . h($r['msg_from'] . ': ' . $r['msg_subject']) . '</p>';
        } else {
            $where = (int)$m['private'] === 1 ? t('private') : ($areas[(int)$m['area_id']] ?? '?');
            echo '<dl class="msghead"><dt>' . h(t('Area')) . '</dt><dd>' . h($where) . '</dd><dt>' . h(t('From')) . '</dt><dd>' . h($m['from_name']) . '</dd><dt>' .
                h(t('To')) . '</dt><dd>' . h($m['to_name']) . '</dd><dt>' . h(t('Subject')) . '</dt><dd>' . h($m['subject']) . '</dd><dt>' . h(t('Date')) . '</dt><dd>' .
                date('d.m.Y H:i', (int)$m['posted']) . '</dd></dl>' . cb_msg_body_html((string)$m['body']);
        }
        echo '<form method="post" class="row-actions report-actions">' . a_csrf();
        if ($m) {
            echo '<button class="btn small danger" name="delete_msg" value="' . (int)$r['id'] . '" onclick="return confirm(' . h(json_encode(t('Delete this message?'))) . ')">' .
                h(t('Delete message')) . '</button>';
            if ((int)$m['private'] === 0) {
                echo '<span class="move">' . a_marea_move_select($areas, (int)$m['area_id']) . '<button class="btn small ghost" name="move" value="' . (int)$r['id'] . '">' .
                    h(t('Move')) . '</button></span>';
            }
        }
        echo '<button class="btn small ghost" name="dismiss" value="' . (int)$r['id'] . '">' . h(t('Dismiss report')) . '</button>';
        if ($m && (int)$m['from_id'] > 0) {
            echo '<a class="btn small ghost" href="' . h(a_url('mail', ['compose' => 1, 'to' => $m['from_name'], 'subj' => 'Re: ' . $m['subject']])) . '">' . h(t('Write to the sender')) . '</a>';
            if (a_full($admin)) {
                echo '<a class="btn small ghost" href="' . h(a_url('user', ['id' => (int)$m['from_id']])) . '">' . h(t('Page of the sender')) . '</a>';
            }
        }
        echo '</form></article>';
    }
    $old = DB::all("SELECT r.*, u.handle AS reporter, h.handle AS handler FROM {msg_reports} r LEFT JOIN {users} u ON u.id=r.reporter_id
        LEFT JOIN {users} h ON h.id=r.handled_by WHERE r.status<>'open' ORDER BY r.handled_time DESC LIMIT 200");
    if ($old) {
        echo '<h2>' . h(t('Handled in the last 90 days')) . '</h2><div class="tablewrap"><table class="cards"><tr><th>' . h(t('Date')) . '</th><th>' . h(t('Message')) .
            '</th><th>' . h(t('Reported by')) . '</th><th>' . h(t('Result')) . '</th></tr>';
        foreach ($old as $r) {
            $gone = !DB::val('SELECT COUNT(*) FROM {messages} WHERE id=?', [(int)$r['msg_id']]);
            echo '<tr><td data-label="' . h(t('Date')) . '">' . date('d.m.y H:i', (int)$r['handled_time']) . '</td><td data-label="' . h(t('Message')) . '">' .
                h($r['msg_from'] . ': ' . $r['msg_subject']) . ($gone ? ' <span class="tag">' . h(t('Message deleted')) . '</span>' : '') . '</td><td data-label="' .
                h(t('Reported by')) . '">' . h($r['reporter'] ?? '?') . '</td><td data-label="' . h(t('Result')) . '">' .
                h(($r['status'] === 'done' ? t('done') : t('dismissed')) . ', ' . ($r['handler'] ?? '?')) . '</td></tr>';
        }
        echo '</table></div>';
    }
}
