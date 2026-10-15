<?php
/**
 * WebCarrier BBS: shared code of the terminal and the backend for the daily work in the backend.
 * Roles, reachability of backend accounts, calls and chat from the backend, search in messages,
 * quoting, kinds of log entries and the data export of a user.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** A backend account counts as reachable while one of its pages asked within this many seconds. */
const CB_REACH_SECONDS = 75;
/** A chat from the backend ends when its page did not ask within this many seconds. */
const CB_CHAT_SECONDS = 45;
/** A call waits this long for a sysop. */
const CB_PAGE_SECONDS = 60;

/* ------------------------------------------------------------------ roles */

/** Role of a level in the backend: none, moderator or full. The sysop level is always full. */
function cb_level_role(int $level): string
{
    if ($level >= Settings::int('sysop_level', 255)) {
        return 'full';
    }
    $role = (string)(DB::val('SELECT backend FROM {levels} WHERE level<=? ORDER BY level DESC', [$level]) ?? 'none');
    return in_array($role, ['moderator', 'full'], true) ? $role : 'none';
}

/** Role of a user in the backend. Locked and waiting users have none. */
function cb_backend_role(array $u): string
{
    if ((int)$u['locked'] === 1 || (int)$u['pending'] === 1) {
        return 'none';
    }
    return cb_level_role((int)$u['level']);
}

/* ------------------------------------------------------------------ reachability, calls, chat */

/** Mark a backend account as reachable now. */
function cb_backend_touch(int $uid): void
{
    DB::q('UPDATE {users} SET backend_seen=? WHERE id=?', [time(), $uid]);
}

/** Backend accounts that are reachable right now (a page asked within CB_REACH_SECONDS). */
function cb_backend_reachable(): array
{
    $out = [];
    foreach (DB::all('SELECT * FROM {users} WHERE backend_seen>? AND locked=0 AND pending=0', [time() - CB_REACH_SECONDS]) as $u) {
        if (cb_backend_role($u) !== 'none') {
            $out[] = $u;
        }
    }
    return $out;
}

/** The backend is the other side of a chat without a node of its own: it uses the negative user id. */
function cb_backend_node(int $uid): int
{
    return -$uid;
}

/** True while the backend side of a chat is there: its page asked within CB_CHAT_SECONDS. */
function cb_backend_chat_alive(int $node, string $handle): bool
{
    return (bool)DB::val('SELECT COUNT(*) FROM {users} WHERE id=? AND handle=? AND backend_seen>?', [-$node, $handle, time() - CB_CHAT_SECONDS]);
}

/** Calls nobody took in time become missed, the log keeps a line for each. Old calls are removed after 30 days. */
function cb_pages_expire(): void
{
    foreach (DB::all("SELECT * FROM {sysop_pages} WHERE status='waiting' AND time<?", [time() - CB_PAGE_SECONDS - 10]) as $p) {
        if (DB::q("UPDATE {sysop_pages} SET status='missed' WHERE id=? AND status='waiting'", [(int)$p['id']])->rowCount()) {
            cb_log((int)$p['user_id'], (string)$p['handle'], 'Missed page from ' . $p['handle'] . ' (node ' . $p['node'] . ')');
        }
    }
    DB::q('DELETE FROM {sysop_pages} WHERE time<?', [time() - 30 * 86400]);
}

/**
 * Set the end of the running call of a user on a node (disconnected, vanished). Only the latest call:
 * calls from before version 1.9.0 have no end either and must keep "unknown".
 */
function cb_end_calls(int $uid, int $node, int $time): void
{
    $id = (int)DB::val('SELECT MAX(id) FROM {calls} WHERE user_id=? AND node=?', [$uid, $node]);
    if ($id > 0) {
        DB::q('UPDATE {calls} SET ended=? WHERE id=? AND ended=0 AND time<=?', [$time, $id, $time]);
    }
}

/* ------------------------------------------------------------------ messages */

/** Search condition over subject, text and sender, case-insensitive also for umlauts: [sql, params]. */
function cb_msg_search(string $q, string $prefix = ''): array
{
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)) . '%';
    return ['(' . DB::lower($prefix . 'subject') . " LIKE ? ESCAPE '!' OR " . DB::lower($prefix . 'body') . " LIKE ? ESCAPE '!' OR " .
        DB::lower($prefix . 'from_name') . " LIKE ? ESCAPE '!')", [$like, $like, $like]];
}

/** Lines of a quote of a message: initials and "> ", without its signature block, at most 40 lines. */
function cb_quote_lines(array $m): array
{
    $ini = '';
    foreach (preg_split('/[\s._\-]+/u', (string)$m['from_name']) ?: [] as $p) {
        if ($p !== '' && mb_strlen($ini) < 2) {
            $ini .= mb_strtoupper(mb_substr($p, 0, 1));
        }
    }
    // without the signature block, it starts with the line "-- "
    $body = (string)$m['body'];
    $lines = explode("\n", str_replace("\r", '', $body));
    foreach ($lines as $i => $l) {
        if (rtrim($l) === '--') {
            $body = implode("\n", array_slice($lines, 0, $i));
            break;
        }
    }
    $out = [];
    foreach (cb_wrap($body, 70) as $l) {
        if (trim($l) === '') {
            continue;
        }
        $out[] = ' ' . $ini . '> ' . $l;
        if (count($out) >= 40) {
            break;
        }
    }
    return $out;
}

/**
 * Text of a message written in the backend: control characters removed, lines longer than the
 * editor of the terminal (75) wrapped at a space. [text, error or null] with the line limit.
 */
function cb_backend_body(string $text): array
{
    $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
    $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text) ?? '';
    $lines = [];
    foreach (explode("\n", rtrim($text)) as $l) {
        foreach (mb_strlen($l) > 75 ? cb_wrap($l, 75) : [rtrim($l)] as $w) {
            $lines[] = $w;
        }
    }
    $max = Settings::int('max_msg_lines', 200);
    if (count($lines) > $max) {
        return [implode("\n", $lines), $max];
    }
    return [mb_substr(implode("\n", $lines), 0, 20000), null];
}

/**
 * Store a message written by a user (signature added, posts counted), like the editor of the
 * terminal. $p: area_id, to_id, to_name, subject, body, reply_to, private.
 */
function cb_post_message(array $from, array $p, bool $countPost = true): int
{
    $body = (string)$p['body'];
    $sig = trim((string)($from['signature'] ?? ''));
    if ($sig !== '') {
        $body .= "\n\n-- \n" . $sig;
    }
    $id = cb_message_insert([
        'area_id' => (int)($p['area_id'] ?? 0), 'from_id' => (int)$from['id'], 'from_name' => (string)$from['handle'],
        'to_id' => (int)($p['to_id'] ?? 0), 'to_name' => (string)($p['to_name'] ?? ''), 'subject' => mb_substr((string)$p['subject'], 0, 70),
        'body' => $body, 'posted' => time(), 'reply_to' => (int)($p['reply_to'] ?? 0), 'private' => (int)($p['private'] ?? 0), 'is_read' => 0,
    ]);
    if ($countPost) {
        DB::q('UPDATE {users} SET posts=posts+1 WHERE id=?', [(int)$from['id']]);
    }
    return $id;
}

/* ------------------------------------------------------------------ log */

/**
 * Kinds of log entries, from the text of the entry. The first kind with a matching pattern wins,
 * everything else is "other". New log texts only need a pattern here (SQL LIKE syntax).
 */
function cb_log_kinds(): array
{
    return [
        'fail' => ['Wrong password%', 'Wrong code%', 'Login while waiting%'],
        'login' => ['Login on node%', 'Logoff', 'Sysop backend login', 'Backup code used%', 'Second factor%'],
        'system' => ['Update %', 'Removed a stale maintenance flag', 'Downloaded a backup%', 'Backend settings%', 'Backend legal%', 'Backend levels%',
            'Backend banlist%', 'Backend questions%', 'Backend msgareas%', 'Backend fileareas%', 'Backend menus%', 'Backend menu%', 'Backend screens%',
            'Backend screen%', 'Backend texts%', 'Backend doors%', 'Skipped update%', 'Data export%', 'Exported the log%'],
        'files' => ['Upload%', 'Download %', 'Imported %', 'Deleted file%', 'Renamed file%', 'Moved file%', 'Approved%'],
        'backend' => ['Backend %', 'Edited user%', 'Created user%', 'Deleted user%', 'Deleted waiting user%', 'Validated user%', 'Deleted % message(s)',
            'Moved % message(s)%', 'Disconnected node%', 'Broadcast%', 'Chat with%', 'Ended the chat%', 'Report %', 'Circular%', 'Wrote %',
            'Deleted poll%', 'Closed poll%', 'Changed poll%', 'Created poll%', 'Deleted a line%', 'Deleted all%', 'Cleared%', 'Saved the sysop message',
            'Switched off the second factor of%', 'Deleted an answer%', 'Refused for moderator%', 'Declined the page%'],
    ];
}

/** SQL condition for a kind of log entries: [sql, params]. */
function cb_log_kind_sql(string $kind): array
{
    $kinds = cb_log_kinds();
    if ($kind === 'other') {
        $all = array_merge(...array_values($kinds));
        return ['NOT (' . implode(' OR ', array_fill(0, count($all), 'text LIKE ?')) . ')', $all];
    }
    $mine = $kinds[$kind] ?? [];
    if (!$mine) {
        return ['1=1', []];
    }
    // patterns of kinds listed before win, so they are left out here
    $before = [];
    foreach ($kinds as $k => $p) {
        if ($k === $kind) {
            break;
        }
        $before = array_merge($before, $p);
    }
    $sql = '(' . implode(' OR ', array_fill(0, count($mine), 'text LIKE ?')) . ')';
    if ($before) {
        $sql .= ' AND NOT (' . implode(' OR ', array_fill(0, count($before), 'text LIKE ?')) . ')';
    }
    return [$sql, array_merge($mine, $before)];
}

/** Kind of one log text, the same rules as cb_log_kind_sql(). */
function cb_log_kind(string $text): string
{
    foreach (cb_log_kinds() as $kind => $patterns) {
        foreach ($patterns as $p) {
            $rx = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($p, '/')) . '$/s';
            if (preg_match($rx, $text)) {
                return $kind;
            }
        }
    }
    return 'other';
}

/* ------------------------------------------------------------------ data export */

/**
 * Everything the board stores about a user, for an answer to a request for access (Art. 15 GDPR).
 * Without password hash, secret and backup codes of the second factor, the note of the sysop and
 * reports about the user.
 */
function cb_user_export(int $uid): array
{
    $u = DB::row('SELECT * FROM {users} WHERE id=?', [$uid]);
    if (!$u) {
        return [];
    }
    $level = DB::row('SELECT * FROM {levels} WHERE level<=? ORDER BY level DESC', [(int)$u['level']]);
    $handle = (string)$u['handle'];
    $files = DB::all('SELECT id, filename, size, description, added, approved, downloads FROM {files} WHERE uploader_id=? ORDER BY id', [$uid]);
    return [
        'generated' => date('c'),
        'board' => Settings::get('bbs_name', 'WebCarrier BBS'),
        'account' => [
            'handle' => $handle, 'location' => (string)$u['location'], 'level' => (int)$u['level'], 'level_name' => (string)($level['name'] ?? ''),
            'registered' => date('c', (int)$u['created']), 'calls' => (int)$u['calls'],
            'last_call' => (int)$u['last_call'] > 0 ? date('c', (int)$u['last_call']) : null,
            'about' => (string)$u['about'], 'signature' => (string)$u['signature'],
            'settings' => ['expert' => (int)$u['expert'], 'modem_speed' => (int)$u['baud'], 'node_messages' => (int)$u['allow_msgs'], 'tube_effect' => (int)$u['crt']],
            'locked' => (int)$u['locked'], 'waiting_for_validation' => (int)$u['pending'], 'posts' => (int)$u['posts'],
            'uploads' => ['files' => (int)$u['ul_files'], 'kb' => (int)$u['ul_kb']], 'downloads' => ['files' => (int)$u['dl_files'], 'kb' => (int)$u['dl_kb']],
            'second_factor' => (int)$u['totp_on'] === 1,
        ],
        'questionnaire' => array_map(static fn($a) => ['question' => $a['question'], 'answer' => $a['answer'], 'time' => date('c', (int)$a['time'])],
            DB::all('SELECT * FROM {nu_answers} WHERE user_id=? ORDER BY id', [$uid])),
        'calls' => array_map(static fn($c) => ['time' => date('c', (int)$c['time']), 'node' => (int)$c['node'],
            'seconds' => (int)$c['ended'] > 0 ? (int)$c['ended'] - (int)$c['time'] : null], DB::all('SELECT * FROM {calls} WHERE user_id=? ORDER BY id', [$uid])),
        'public_messages' => array_map(static fn($m) => ['time' => date('c', (int)$m['posted']), 'area' => (string)$m['area'], 'to' => $m['to_name'],
            'subject' => $m['subject'], 'text' => (string)$m['body']],
            DB::all('SELECT m.*, a.name AS area FROM {messages} m LEFT JOIN {msg_areas} a ON a.id=m.area_id WHERE m.from_id=? AND m.private=0 ORDER BY m.id', [$uid])),
        'private_mail' => array_map(static fn($m) => ['time' => date('c', (int)$m['posted']), 'from' => $m['from_name'], 'to' => $m['to_name'],
            'subject' => $m['subject'], 'text' => (string)$m['body'], 'read' => (int)$m['is_read']],
            DB::all('SELECT * FROM {messages} WHERE private=1 AND (from_id=? OR to_id=?) ORDER BY id', [$uid, $uid])),
        'uploads' => array_map(static fn($f) => ['file' => $f['filename'], 'size' => (int)$f['size'], 'description' => (string)$f['description'],
            'added' => date('c', (int)$f['added']), 'approved' => (int)$f['approved'], 'downloads' => (int)$f['downloads']], $files),
        'downloads' => array_map(static fn($l) => ['time' => date('c', (int)$l['time']), 'entry' => $l['text']],
            DB::all("SELECT * FROM {log} WHERE user_id=? AND text LIKE 'Download %' ORDER BY id", [$uid])),
        'oneliners' => array_map(static fn($o) => ['time' => date('c', (int)$o['time']), 'text' => $o['text']],
            DB::all('SELECT * FROM {oneliners} WHERE user_id=? ORDER BY id', [$uid])),
        'meeting_point' => array_map(static fn($l) => ['time' => date('c', (int)$l['time']), 'text' => $l['text']],
            DB::all('SELECT * FROM {tele_lines} WHERE user_id=? ORDER BY id', [$uid])),
        'poll_votes' => array_map(static fn($v) => ['time' => date('c', (int)$v['time']), 'poll' => $v['question'], 'answer' => $v['answer']],
            DB::all('SELECT v.time, p.question, o.text AS answer FROM {poll_votes} v JOIN {polls} p ON p.id=v.poll_id LEFT JOIN {poll_options} o ON o.id=v.option_id
                WHERE v.user_id=? ORDER BY v.time', [$uid])),
        'high_scores' => array_map(static fn($s) => ['door' => $s['door'], 'value' => (int)$s['value'], 'label' => $s['label'], 'time' => date('c', (int)$s['time'])],
            DB::all('SELECT * FROM {door_scores} WHERE user_id=? ORDER BY door', [$uid])),
        'reports_made' => array_map(static fn($r) => ['time' => date('c', (int)$r['time']), 'message' => $r['msg_subject'], 'reason' => $r['reason'], 'status' => $r['status']],
            DB::all('SELECT * FROM {msg_reports} WHERE reporter_id=? ORDER BY id', [$uid])),
        'log' => array_map(static fn($l) => ['time' => date('c', (int)$l['time']), 'entry' => $l['text']],
            DB::all('SELECT * FROM {log} WHERE user_id=? ORDER BY id', [$uid])),
    ];
}
