<?php
/**
 * WebCarrier BBS: public boards. Message areas the sysop made public can be read here as plain web
 * pages, without a call and without writing. Every page leads into the box with a direct dial link.
 *
 *   board.php               overview of the public boards
 *   board.php?a=5&page=2    messages of a board, newest first, 30 per page
 *   board.php?m=123         one message with its replies
 *   board.php?sitemap=1     sitemap, only while search engines are welcome
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
if (!cb_installed()) {
    header('Location: install/');
    exit;
}
// readers are no callers: no session and no cookie
cb_boot(false);
Lang::load(Settings::get('language', 'en'));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
    . "style-src 'self'; script-src 'none'; base-uri 'none'; "
    . "form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-cache');

const BOARD_PER_PAGE = 30;

/** Web text from the language file, pipe codes removed and escaped. */
function bt(string $k, ...$a): string
{
    return h(cb_strip_pipes(Lang::get($k, $a)));
}

/** Positive id from a query parameter, digits only, otherwise 0. */
function board_id(string $k): int
{
    $v = $_GET[$k] ?? '';
    return is_string($v) && preg_match('/^[1-9][0-9]{0,8}$/', $v) ? (int)$v : 0;
}

function board_date(int $t): string
{
    return '<time datetime="' . date('c', $t) . '">' . h(date(str_replace('y', 'Y', Lang::get('fmt_date')) . ' H:i', $t)) . '</time>';
}

/** Name of the writer on the web: the handle, or "Caller" if the user asked for it. */
function board_from(array $m): string
{
    return (int)($m['web_anon'] ?? 0) === 1 ? Lang::get('web_anon') : (string)$m['from_name'];
}

/** Columns of a message for the web, with the setting of the writer. */
const BOARD_MSG_COLS = 'm.id, m.area_id, m.subject, m.from_name, m.posted, m.reply_to, COALESCE(u.web_anon, 0) AS web_anon';

/** A public message (also checks its area), or null. */
function board_message(int $id, bool $body = false): ?array
{
    [$cond, $par] = cb_public_area_sql('a');
    return DB::row('SELECT ' . BOARD_MSG_COLS . ($body ? ', m.body' : '') . ', a.name AS area FROM {messages} m JOIN {msg_areas} a ON a.id=m.area_id
        LEFT JOIN {users} u ON u.id=m.from_id WHERE m.id=? AND m.private=0 AND ' . $cond, array_merge([$id], $par));
}

/** Short text for meta description: no pipe codes, no quoted lines, whitespace folded. */
function board_snippet(string $text, int $max = 155): string
{
    $lines = array_filter(explode("\n", str_replace("\r", '', $text)), static fn($l) => !preg_match('/^\s*[\p{L}]{0,3}>/u', $l) && rtrim($l) !== '--');
    $s = trim(preg_replace('/\s+/u', ' ', cb_strip_pipes(implode(' ', $lines))) ?? '');
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 3)) . '...' : $s;
}

function board_page(string $title, string $desc, string $canonical, string $body, int $status = 200): never
{
    http_response_code($status);
    $robots = $status !== 200 ? 'noindex' : (Settings::get('public_index', '1') === '1' ? 'index, follow' : 'noindex, follow');
    $name = Settings::get('bbs_name', 'WebCarrier BBS');
    echo '<!DOCTYPE html>' . "\n" . '<html lang="' . h(Lang::$code) . '">' . "\n<head>\n<meta charset=\"utf-8\">\n" .
        '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n" .
        '<title>' . h($title) . '</title>' . "\n" .
        ($desc !== '' ? '<meta name="description" content="' . h($desc) . '">' . "\n" : '') .
        '<meta name="robots" content="' . $robots . '">' . "\n" .
        ($canonical !== '' ? '<link rel="canonical" href="' . h($canonical) . '">' . "\n" : '') .
        '<link rel="stylesheet" href="assets/board.css?v=' . CB_VERSION . '">' . "\n</head>\n<body>\n" .
        '<header class="top"><a class="home" href="board.php">' . h($name) . '</a> <a class="call" href="./">' . bt('web_terminal') . '</a></header>' . "\n" .
        '<main>' . "\n" . $body . "\n</main>\n" .
        '<footer class="foot"><a href="legal.php?p=impressum">' . bt('legal_impressum') . '</a> <a href="legal.php?p=privacy">' . bt('legal_privacy') .
        '</a> <a href="' . h(CB_SOURCE_URL) . '" rel="noopener">' . bt('legal_source') . '</a></footer>' . "\n</body>\n</html>\n";
    exit;
}

function board_404(): never
{
    $back = cb_has_public_areas() ? '<p><a href="board.php">' . bt('web_all_boards') . '</a></p>' : '<p><a href="./">' . bt('web_terminal') . '</a></p>';
    board_page(Lang::get('web_not_found') . ' · ' . Settings::get('bbs_name', 'WebCarrier BBS'), '', '',
        '<h1>' . bt('web_not_found') . '</h1><p>' . bt('web_not_found_text') . '</p>' . $back, 404);
}

function board_call(string $kind, int $id): string
{
    return '<p class="callbox"><a href="./#' . $kind . '/' . $id . '">' . bt('web_call') . '</a></p>';
}

/* ------------------------------------------------------------------ pages */

function board_overview(string $base): never
{
    [$cond, $par] = cb_public_area_sql('a');
    $areas = DB::all('SELECT a.id, a.name, a.description,
        (SELECT COUNT(*) FROM {messages} m WHERE m.area_id=a.id AND m.private=0) AS n,
        (SELECT MAX(m.id) FROM {messages} m WHERE m.area_id=a.id AND m.private=0) AS last_id
        FROM {msg_areas} a WHERE ' . $cond . ' ORDER BY a.sort, a.id', $par);
    if (!$areas) {
        board_404();
    }
    $name = Settings::get('bbs_name', 'WebCarrier BBS');
    $h = '<h1>' . bt('web_boards') . '</h1><p class="intro">' . bt('web_intro', $name) . '</p><ul class="boards">';
    foreach ($areas as $a) {
        $h .= '<li><h2><a href="board.php?a=' . (int)$a['id'] . '">' . h($a['name']) . '</a></h2>' .
            ((string)$a['description'] !== '' ? '<p>' . h($a['description']) . '</p>' : '') .
            '<p class="meta"><span>' . bt('web_col_msgs') . ': ' . (int)$a['n'] . '</span>';
        $last = (int)$a['last_id'] > 0 ? board_message((int)$a['last_id']) : null;
        if ($last) {
            $h .= '<span>' . bt('web_col_last') . ': <a href="board.php?m=' . (int)$last['id'] . '">' . h($last['subject']) . '</a>, ' .
                h(board_from($last)) . ', ' . board_date((int)$last['posted']) . '</span>';
        }
        $h .= '</p></li>';
    }
    $h .= '</ul>';
    board_page(Lang::get('web_boards') . ' · ' . $name, Lang::get('web_desc_overview', [$name]), $base . 'board.php', $h);
}

function board_area(string $base, int $id): never
{
    [$cond, $par] = cb_public_area_sql('a');
    $a = DB::row('SELECT * FROM {msg_areas} a WHERE a.id=? AND ' . $cond, array_merge([$id], $par));
    if (!$a) {
        board_404();
    }
    $total = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE area_id=? AND private=0', [$id]);
    $pages = max(1, (int)ceil($total / BOARD_PER_PAGE));
    $page = isset($_GET['page']) ? board_id('page') : 1;
    if ($page < 1 || $page > $pages) {
        board_404();
    }
    [$rc, $rp] = cb_public_area_sql('ra');
    $rows = DB::all('SELECT ' . BOARD_MSG_COLS . ', (SELECT COUNT(*) FROM {messages} r JOIN {msg_areas} ra ON ra.id=r.area_id
        WHERE r.reply_to=m.id AND r.private=0 AND ' . $rc . ') AS replies
        FROM {messages} m LEFT JOIN {users} u ON u.id=m.from_id WHERE m.area_id=? AND m.private=0
        ORDER BY m.id DESC LIMIT ' . BOARD_PER_PAGE . ' OFFSET ' . (($page - 1) * BOARD_PER_PAGE), array_merge($rp, [$id]));
    $name = Settings::get('bbs_name', 'WebCarrier BBS');
    $h = '<nav class="crumbs"><a href="board.php">' . bt('web_all_boards') . '</a></nav><h1>' . h($a['name']) . '</h1>' .
        ((string)$a['description'] !== '' ? '<p class="intro">' . h($a['description']) . '</p>' : '') . board_call('area', $id);
    if (!$rows) {
        $h .= '<p>' . bt('web_empty') . '</p>';
    } else {
        $h .= '<ol class="msgs"><li class="head" aria-hidden="true"><span>' . bt('web_col_subject') . '</span><span>' . bt('web_col_from') . '</span><span>' .
            bt('web_col_date') . '</span><span>' . bt('web_col_replies') . '</span></li>';
        foreach ($rows as $m) {
            $h .= '<li><a class="subj" href="board.php?m=' . (int)$m['id'] . '">' . h($m['subject']) . '</a><span class="from">' . h(board_from($m)) .
                '</span><span class="date">' . board_date((int)$m['posted']) . '</span><span class="rep" title="' . bt('web_col_replies') . '">' .
                (int)$m['replies'] . '<span class="lbl"> ' . bt('web_col_replies') . '</span></span></li>';
        }
        $h .= '</ol>';
    }
    if ($pages > 1) {
        $h .= '<nav class="pager">' . ($page > 1 ? '<a rel="prev" href="board.php?a=' . $id . ($page > 2 ? '&amp;page=' . ($page - 1) : '') . '">' . bt('web_newer') . '</a>' : '<span></span>') .
            '<span>' . bt('web_page', $page, $pages) . '</span>' .
            ($page < $pages ? '<a rel="next" href="board.php?a=' . $id . '&amp;page=' . ($page + 1) . '">' . bt('web_older') . '</a>' : '<span></span>') . '</nav>';
    }
    $title = $a['name'] . ($page > 1 ? ' (' . Lang::get('web_page', [$page, $pages]) . ')' : '') . ' · ' . $name;
    $desc = (string)$a['description'] !== '' ? (string)$a['description'] : Lang::get('web_desc_board', [$a['name'], $name]);
    board_page($title, cb_strip_pipes($desc), $base . 'board.php?a=' . $id . ($page > 1 ? '&page=' . $page : ''), $h);
}

function board_msg(string $base, int $id): never
{
    $m = board_message($id, true);
    if (!$m) {
        board_404();
    }
    $orig = (int)$m['reply_to'] > 0 ? board_message((int)$m['reply_to']) : null;
    [$cond, $par] = cb_public_area_sql('a');
    $replies = DB::all('SELECT ' . BOARD_MSG_COLS . ' FROM {messages} m JOIN {msg_areas} a ON a.id=m.area_id LEFT JOIN {users} u ON u.id=m.from_id
        WHERE m.reply_to=? AND m.private=0 AND ' . $cond . ' ORDER BY m.id', array_merge([$id], $par));
    $h = '<nav class="crumbs"><a href="board.php">' . bt('web_all_boards') . '</a> <span aria-hidden="true">/</span> <a href="board.php?a=' . (int)$m['area_id'] . '">' .
        h($m['area']) . '</a></nav><article class="msg"><h1>' . h($m['subject']) . '</h1><p class="meta">' . h(board_from($m)) . ', ' . board_date((int)$m['posted']) . '</p>';
    if ($orig) {
        $h .= '<p class="orig">' . str_replace('{1}', '<a href="board.php?m=' . (int)$orig['id'] . '">' . h($orig['subject']) . '</a>',
                h(cb_strip_pipes(Lang::get('web_reply_to')))) . '</p>';
    }
    $h .= cb_msg_body_html((string)$m['body']) . '</article>' . board_call('msg', $id);
    if ($replies) {
        $h .= '<section class="replies"><h2>' . bt('web_replies') . '</h2><ul>';
        foreach ($replies as $r) {
            $h .= '<li><a href="board.php?m=' . (int)$r['id'] . '">' . h($r['subject']) . '</a> <span class="meta">' . h(board_from($r)) . ', ' .
                board_date((int)$r['posted']) . '</span></li>';
        }
        $h .= '</ul></section>';
    }
    $subj = cb_strip_pipes((string)$m['subject']);
    $snip = board_snippet((string)$m['body'], 150 - min(60, mb_strlen($subj)));
    board_page($subj . ' · ' . $m['area'] . ' · ' . Settings::get('bbs_name', 'WebCarrier BBS'), $subj . ($snip !== '' ? ': ' . $snip : ''),
        $base . 'board.php?m=' . $id, $h);
}

function board_sitemap(string $base): never
{
    if (Settings::get('public_index', '1') !== '1' || !cb_has_public_areas()) {
        board_404();
    }
    [$cond, $par] = cb_public_area_sql('a');
    header('Content-Type: application/xml; charset=utf-8');
    $url = static fn(string $loc, int $t = 0): string => '<url><loc>' . h($loc) . '</loc>' . ($t > 0 ? '<lastmod>' . date('Y-m-d', $t) . '</lastmod>' : '') . "</url>\n";
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . $url($base . 'board.php');
    foreach (DB::all('SELECT a.id, (SELECT MAX(m.posted) FROM {messages} m WHERE m.area_id=a.id AND m.private=0) AS last
        FROM {msg_areas} a WHERE ' . $cond . ' ORDER BY a.sort, a.id', $par) as $a) {
        $out .= $url($base . 'board.php?a=' . (int)$a['id'], (int)$a['last']);
    }
    foreach (DB::all('SELECT m.id, m.posted FROM {messages} m JOIN {msg_areas} a ON a.id=m.area_id WHERE m.private=0 AND ' . $cond .
        ' ORDER BY m.id DESC LIMIT ' . CB_SITEMAP_MAX, $par) as $m) {
        $out .= $url($base . 'board.php?m=' . (int)$m['id'], (int)$m['posted']);
    }
    echo $out . "</urlset>\n";
    exit;
}

/* ------------------------------------------------------------------ dispatch */

if (cb_maintenance()) {
    header('Retry-After: 300');
    board_page(Settings::get('bbs_name', 'WebCarrier BBS'), '', '', '<p>' . h(trim(cb_strip_pipes(str_replace('|CR', "\n", Lang::get('maintenance'))))) . '</p>', 503);
}
if (cb_rate_limited('board', CB_BOARD_RATE)) {
    header('Retry-After: 60');
    board_page(Settings::get('bbs_name', 'WebCarrier BBS'), '', '', '<p>' . bt('web_too_many') . '</p>', 429);
}
$base = cb_bbs_url();
if (isset($_GET['sitemap'])) {
    board_sitemap($base);
}
if (isset($_GET['m'])) {
    board_msg($base, board_id('m'));
}
if (isset($_GET['a'])) {
    board_area($base, board_id('a'));
}
board_overview($base);
