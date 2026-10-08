<?php
/**
 * WebCarrier BBS: bootstrap and shared helpers.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

define('CB_ROOT', dirname(__DIR__));
define('CB_DATA', CB_ROOT . '/data');
define('CB_VERSION', '1.4.0');
define('CB_AUTHOR', 'Christoph Scheel');
define('CB_AUTHOR_URL', 'https://chrisscheel.de');
// AGPL section 13: users of a networked installation must be able to get the source.
// Modified versions must point this to their own repository.
define('CB_SOURCE_URL', 'https://github.com/alph4r79/webcarrierbbs');

require __DIR__ . '/db.php';
require __DIR__ . '/migrate.php';
require __DIR__ . '/update.php';

function cb_installed(): bool
{
    return is_file(CB_ROOT . '/core/config.php');
}

/** Connect to the database, apply pending migrations, finish or clean up updates and start the session. */
function cb_boot(): void
{
    if (!cb_installed()) {
        throw new RuntimeException('not installed');
    }
    $cfg = require CB_ROOT . '/core/config.php';
    DB::connect($cfg['db']);
    cb_migrate();
    cb_update_housekeeping();
    date_default_timezone_set(Settings::get('timezone', 'Europe/Berlin'));
    cb_session();
}

function cb_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('WEBCARRIERBBS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => cb_base_path(),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', '7200');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/** Web path of the installation, e.g. "/" or "/bbs/". */
function cb_base_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = rtrim(dirname($script), '/');
    foreach (['/sysop', '/install'] as $sub) {
        if (str_ends_with($dir, $sub)) {
            $dir = substr($dir, 0, -strlen($sub));
        }
    }
    return $dir . '/';
}

function cb_csrf(): string
{
    if (empty($_SESSION['cb_csrf'])) {
        $_SESSION['cb_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['cb_csrf'];
}

function cb_csrf_ok(?string $t): bool
{
    return is_string($t) && !empty($_SESSION['cb_csrf']) && hash_equals($_SESSION['cb_csrf'], $t);
}

function cb_log(int $uid, string $handle, string $text): void
{
    DB::insert('log', ['time' => time(), 'user_id' => $uid, 'handle' => $handle, 'text' => mb_substr($text, 0, 250)]);
}

/** Login throttle: 5 wrong passwords in 15 minutes block the account (terminal and backend). */
function cb_login_blocked(int $uid): bool
{
    $n = (int)DB::val("SELECT COUNT(*) FROM {log} WHERE user_id=? AND text LIKE 'Wrong password%' AND time>?", [$uid, time() - 900]);
    return $n >= 5;
}

/** 3 to 20 characters, starts with a letter or digit. */
const CB_HANDLE_RE = '/^[\p{L}\p{N}][\p{L}\p{N} ._\-]{1,18}[\p{L}\p{N}._\-]$/u';

/**
 * Handle check for registration and the backend: null if fine, 'bad' or 'taken'.
 * $exceptId skips that user (editing), its current handle counts as allowed even if reserved.
 */
function cb_handle_check(string $handle, int $exceptId = 0): ?string
{
    if (!preg_match(CB_HANDLE_RE, $handle)) {
        return 'bad';
    }
    $lc = mb_strtolower($handle);
    $own = $exceptId > 0 && DB::val('SELECT handle_lc FROM {users} WHERE id=?', [$exceptId]) === $lc;
    $reserved = ['new', 'sysop', 'all', mb_strtolower(Lang::get('kw_new')), mb_strtolower(Lang::get('kw_all'))];
    if ((!$own && in_array($lc, $reserved, true))
        || DB::val('SELECT COUNT(*) FROM {users} WHERE handle_lc=? AND id<>?', [$lc, $exceptId])) {
        return 'taken';
    }
    return null;
}

/** DOS style file name (ASCII letters, digits, dot, dash, underscore), '' if nothing usable is left. */
function cb_safe_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    // one underscore per character, byte wise only if the name is no valid UTF-8
    $clean = preg_replace('/[^A-Za-z0-9._\-]/u', '_', $name) ?? preg_replace('/[^A-Za-z0-9._\-]/', '_', $name);
    $name = trim((string)$clean, '._');
    return strlen($name) > 80 ? '' : $name;
}

/** Anything the web server could execute or render, never stored under a user chosen name. */
function cb_dangerous_filename(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return (bool)preg_match('/^(php\d*|pht|phtml|phar|phps|cgi|pl|asp|aspx|jsp|shtml?|x?html?|xht|js|mjs|svgz?|htaccess)$/', $ext)
        || (bool)preg_match('/\.(php\d*|pht|phtml|phar|cgi|pl|py|asp|jsp)\./i', $name);
}

/** Delete a user with read pointers, door data and private mail. Public messages stay. */
function cb_delete_user(int $id): void
{
    DB::q('DELETE FROM {users} WHERE id=?', [$id]);
    DB::q('DELETE FROM {lastread} WHERE user_id=?', [$id]);
    DB::q('DELETE FROM {door_data} WHERE user_id=?', [$id]);
    DB::q('DELETE FROM {messages} WHERE private=1 AND (to_id=? OR from_id=?)', [$id, $id]);
}

/** Delete a stored file and its row. False if the file could not be removed from the disk (row is kept). */
function cb_delete_file(array $f): bool
{
    $p = CB_DATA . '/files/' . $f['storage'];
    if ($f['storage'] !== '' && is_file($p) && !@unlink($p)) {
        return false;
    }
    DB::q('DELETE FROM {files} WHERE id=?', [(int)$f['id']]);
    return true;
}

/** Credit line for the backend and installer pages. */
function cb_credit(string $sourceLabel = 'Source code'): string
{
    return '<footer class="credit">WebCarrier BBS ' . CB_VERSION . ' &middot; &copy; 2026 <a href="' . h(CB_AUTHOR_URL) . '" rel="noopener">' .
        h(CB_AUTHOR) . '</a> &middot; AGPL-3.0 &middot; <a href="' . h(CB_SOURCE_URL) . '" rel="noopener">' . h($sourceLabel) . '</a></footer>';
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------------------------------------------ language */

final class Lang
{
    private static array $s = [];
    public static string $code = 'en';

    public static function load(string $code): void
    {
        $code = preg_replace('/[^a-z]/', '', $code) ?: 'en';
        $en = require CB_ROOT . '/lang/en.php';
        $own = is_file(CB_ROOT . "/lang/$code.php") ? require CB_ROOT . "/lang/$code.php" : [];
        self::$s = array_merge($en, $own);
        self::$code = $code;
    }

    public static function get(string $k, array $a = []): string
    {
        $s = self::$s[$k] ?? $k;
        foreach ($a as $i => $v) {
            $s = str_replace('{' . ($i + 1) . '}', (string)$v, $s);
        }
        return $s;
    }
}

/* ------------------------------------------------------------------ CP437 */

final class CP437
{
    private static ?array $map = null;

    /** UTF-8 text to CP437 bytes. Control characters used by ANSI pass through. */
    public static function from(string $utf8): string
    {
        if ($utf8 === '') {
            return '';
        }
        if (!preg_match('/[\x80-\xFF]/', $utf8)) {
            return $utf8;
        }
        if (self::$map === null) {
            self::$map = require __DIR__ . '/cp437map.php';
        }
        $out = '';
        foreach (preg_split('//u', $utf8, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (strlen($ch) === 1) {
                $out .= $ch;
            } else {
                $out .= isset(self::$map[$ch]) ? chr(self::$map[$ch]) : '?';
            }
        }
        return $out;
    }

    /** CP437 bytes (for example a FILE_ID.DIZ) to UTF-8 text. */
    public static function toUtf8(string $bytes): string
    {
        if (self::$map === null) {
            self::$map = require __DIR__ . '/cp437map.php';
        }
        static $rev = null;
        if ($rev === null) {
            $rev = [];
            foreach (self::$map as $ch => $b) {
                if ($b >= 128) {
                    $rev[$b] = $ch;
                }
            }
        }
        $out = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($bytes[$i]);
            $out .= $o < 128 ? $bytes[$i] : ($rev[$o] ?? '?');
        }
        return $out;
    }

    /** CP437 bytes to transport string (each byte becomes U+0000..U+00FF). */
    public static function transport(string $bytes): string
    {
        return mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
    }

    /** Clean user input: valid UTF-8, no control characters, trimmed. */
    public static function clean(string $s, int $max): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
        return mb_substr(trim($s), 0, $max);
    }
}

/* ------------------------------------------------------------------ text helpers */

/** Word wrap that respects multibyte characters. */
function cb_wrap(string $text, int $width): array
{
    $out = [];
    foreach (preg_split("/\r\n|\r|\n/", $text) as $para) {
        $para = rtrim($para);
        if ($para === '') {
            $out[] = '';
            continue;
        }
        $line = '';
        foreach (preg_split('/( +)/u', $para, -1, PREG_SPLIT_DELIM_CAPTURE) as $tok) {
            if ($tok === '') {
                continue;
            }
            if (mb_strlen($line . $tok) <= $width) {
                $line .= $tok;
                continue;
            }
            if (trim($tok) === '') {
                $out[] = rtrim($line);
                $line = '';
                continue;
            }
            if ($line !== '') {
                $out[] = rtrim($line);
            }
            while (mb_strlen($tok) > $width) {
                $out[] = mb_substr($tok, 0, $width);
                $tok = mb_substr($tok, $width);
            }
            $line = $tok;
        }
        $out[] = rtrim($line);
    }
    return $out;
}

function cb_pad(string $s, int $w, string $align = 'L'): string
{
    $len = mb_strlen($s);
    if ($len >= $w) {
        return mb_substr($s, 0, $w);
    }
    $gap = $w - $len;
    if ($align === 'R') {
        return str_repeat(' ', $gap) . $s;
    }
    if ($align === 'C') {
        $l = intdiv($gap, 2);
        return str_repeat(' ', $l) . $s . str_repeat(' ', $gap - $l);
    }
    return $s . str_repeat(' ', $gap);
}

function cb_date(int $t): string
{
    return $t > 0 ? date(Lang::get('fmt_date'), $t) : Lang::get('never');
}

function cb_kb(int $bytes): string
{
    return number_format((int)ceil($bytes / 1024), 0, '', '') . 'k';
}

/**
 * Writer: turns Carrier pipe codes into ANSI and UTF-8 into CP437.
 *  |00..|15 foreground (DOS colour numbers), |16..|23 background,
 *  |CL clear screen, |CR new line, || a literal pipe.
 */
final class AnsiWriter
{
    public string $buf = '';
    private int $fg = 7;
    private int $bg = 0;
    private const MAP = [0, 4, 2, 6, 1, 5, 3, 7];

    public function sgr(): string
    {
        $f = $this->fg;
        $s = "\e[0;" . ($f > 7 ? '1;' : '') . '3' . self::MAP[$f & 7] . ';4' . self::MAP[$this->bg & 7] . 'm';
        return $s;
    }

    public function color(int $fg, ?int $bg = null): void
    {
        $this->fg = $fg & 15;
        if ($bg !== null) {
            $this->bg = $bg & 7;
        }
        $this->buf .= $this->sgr();
    }

    public function reset(): void
    {
        $this->fg = 7;
        $this->bg = 0;
        $this->buf .= "\e[0m";
    }

    public function write(string $utf8): void
    {
        $parts = preg_split('/\|(\d\d|CL|CR|\|)/', $utf8, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $p) {
            if ($i % 2 === 0) {
                $this->buf .= CP437::from($p);
                continue;
            }
            if ($p === '|') {
                $this->buf .= '|';
            } elseif ($p === 'CL') {
                $this->buf .= "\e[0m\e[2J\e[H" . $this->sgr();
            } elseif ($p === 'CR') {
                $this->buf .= "\r\n";
            } else {
                $n = (int)$p;
                if ($n < 16) {
                    $this->fg = $n;
                } elseif ($n < 24) {
                    $this->bg = $n - 16;
                } else {
                    continue;
                }
                $this->buf .= $this->sgr();
            }
        }
    }

    /** Raw CP437 bytes (ANSI screens). Colour state is unknown afterwards. */
    public function raw(string $bytes): void
    {
        $this->buf .= $bytes;
        $this->fg = 7;
        $this->bg = 0;
    }
}

/** Escape user supplied text so pipe codes are shown literally. */
function cb_esc(string $s): string
{
    return str_replace('|', '||', $s);
}

/**
 * Expand @MACRO@ and @MACRO:20L@ (width, align L/R/C) placeholders.
 * $resolver returns the value for a macro name or null if unknown.
 */
function cb_macros(string $s, callable $resolver, bool $cp437 = false): string
{
    return preg_replace_callback('/@([A-Z]{2,12})(?::(\d{1,2})([LRC]?))?@/', static function ($m) use ($resolver, $cp437) {
        $v = $resolver($m[1]);
        if ($v === null) {
            return $m[0];
        }
        $v = (string)$v;
        if (!empty($m[2])) {
            $v = cb_pad($v, (int)$m[2], $m[3] ?: 'L');
        }
        return $cp437 ? CP437::from($v) : $v;
    }, $s) ?? $s;
}

/** Path of a screen file, or null. Names are restricted to a-z0-9_-. */
function cb_screen_path(string $name): ?string
{
    $name = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $name));
    if ($name === '') {
        return null;
    }
    foreach (['ans', 'txt'] as $ext) {
        $p = CB_DATA . "/screens/$name.$ext";
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

/** Remove SAUCE record and EOF marker from ANSI files. */
function cb_strip_sauce(string $bytes): string
{
    $p = strpos($bytes, "\x1A");
    return $p === false ? $bytes : substr($bytes, 0, $p);
}

/** Installed doors: doors/*.php each return ['id','name','class','description']. */
function cb_doors(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = [];
    foreach (glob(CB_ROOT . '/doors/*.php') ?: [] as $f) {
        $d = require $f;
        if (is_array($d) && !empty($d['id']) && !empty($d['class'])) {
            $list[$d['id']] = $d;
        }
    }
    return $list;
}
