<?php
/**
 * WebCarrier BBS: direct dial links (#msg/<id>, #area/<id>) and public boards. Shared by the
 * engine, the backend and the public pages in board.php.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Messages of the public boards in the sitemap, newest first. */
const CB_SITEMAP_MAX = 5000;
/** Requests to the public pages per address and minute. */
const CB_BOARD_RATE = 120;

/**
 * Address of the board for links as entered in the settings, normalized with a trailing slash.
 * '' if it is no https address (http only for localhost) or has a query or fragment.
 */
function cb_clean_box_url(string $url): string
{
    $url = trim($url);
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['query']) || isset($p['fragment']) || isset($p['user'])) {
        return '';
    }
    $scheme = strtolower($p['scheme']);
    $host = strtolower($p['host']);
    $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
        return '';
    }
    if (!preg_match('/^[a-z0-9.\-]+$|^\[[0-9a-f:]+\]$/', $host)) {
        return '';
    }
    $path = (string)($p['path'] ?? '/');
    if (!preg_match('~^/[A-Za-z0-9._\~/%\-]*$~', $path)) {
        return '';
    }
    return $scheme . '://' . $host . (isset($p['port']) ? ':' . (int)$p['port'] : '') . rtrim($path, '/') . '/';
}

/** Target of a direct dial link: ['msg'|'area', id] or null. Only "msg/123" and "area/5", digits only. */
function cb_direct_target(string $s): ?array
{
    if (!preg_match('~^(msg|area)/([1-9][0-9]{0,8})$~', $s, $m)) {
        return null;
    }
    return [$m[1], (int)$m[2]];
}

/** Direct dial address of a public message or an area. */
function cb_direct_link(string $kind, int $id): string
{
    return cb_bbs_url() . '#' . ($kind === 'area' ? 'area' : 'msg') . '/' . $id;
}

/** An area may be public only if a new user may read it. */
function cb_area_public_allowed(array $area): bool
{
    return (int)$area['read_level'] <= Settings::int('new_level', 10);
}

/** SQL condition for the areas shown on the web, for a join with the alias $a. */
function cb_public_area_sql(string $a = 'a'): array
{
    return ["$a.public=1 AND $a.read_level<=?", [Settings::int('new_level', 10)]];
}

function cb_has_public_areas(): bool
{
    static $has = null;
    if ($has === null) {
        [$cond, $par] = cb_public_area_sql();
        $has = (bool)DB::val("SELECT COUNT(*) FROM {msg_areas} a WHERE $cond", $par);
    }
    return $has;
}

/** True if the messages of this area appear on the web. */
function cb_area_is_public(int $areaId): bool
{
    [$cond, $par] = cb_public_area_sql();
    return (bool)DB::val("SELECT COUNT(*) FROM {msg_areas} a WHERE a.id=? AND $cond", array_merge([$areaId], $par));
}

/**
 * Switch off public areas a new user may no longer read (read level raised or level of new users
 * lowered), one log entry per area. Returns the names of the areas switched off.
 */
function cb_public_areas_check(int $uid, string $handle): array
{
    $off = [];
    foreach (DB::all('SELECT id, name FROM {msg_areas} WHERE public=1 AND read_level>?', [Settings::int('new_level', 10)]) as $a) {
        DB::q('UPDATE {msg_areas} SET public=0 WHERE id=?', [(int)$a['id']]);
        cb_log($uid, $handle, 'Area ' . $a['id'] . ' no longer public on the web, new users cannot read it');
        $off[] = (string)$a['name'];
    }
    return $off;
}

/** Text of a message as the terminal shows it: pipe codes stay text, quoted lines in their own colour. */
function cb_msg_body_html(string $body): string
{
    $h = '';
    foreach (cb_wrap($body, 79) as $l) {
        $q = preg_match('/^\s*[\p{L}]{0,3}>/u', $l) === 1;
        $h .= ($q ? '<span class="q">' . h($l) . '</span>' : h($l)) . "\n";
    }
    return '<pre class="msgbody">' . $h . '</pre>';
}

/** Plain text without pipe codes, for titles and descriptions. */
function cb_strip_pipes(string $s): string
{
    $s = preg_replace('/\|(\d\d|CL|CR)/', '', str_replace('||', "\x01", $s)) ?? $s;
    return str_replace("\x01", '|', $s);
}

/**
 * Simple limit for the public pages: CB_BOARD_RATE requests per minute and address. The address is
 * only kept as a salted hash in data/tmp/rate and the files are removed after a few minutes.
 */
function cb_rate_limited(string $what, int $max): bool
{
    $dir = CB_DATA . '/tmp/rate';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return false;
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return false;
    }
    $file = $dir . '/' . $what . '-' . substr(hash('sha256', cb_box_id() . '|' . $ip), 0, 24);
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        return false;
    }
    flock($fh, LOCK_EX);
    $win = intdiv(time(), 60);
    [$w, $n] = array_map('intval', explode(' ', (string)stream_get_contents($fh)) + [0, 0]);
    $n = $w === $win ? $n + 1 : 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $win . ' ' . $n);
    flock($fh, LOCK_UN);
    fclose($fh);
    if (random_int(1, 100) === 1) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (@filemtime($f) < time() - 300) {
                @unlink($f);
            }
        }
    }
    return $n > $max;
}
