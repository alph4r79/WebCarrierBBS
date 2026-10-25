<?php
// Test client for the terminal API and helpers. Included by the test scripts.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

$GLOBALS['BASE'] = getenv('CB_BASE') ?: 'http://127.0.0.1:8811/';
$GLOBALS['FAILS'] = 0;
$GLOBALS['PASSES'] = 0;

function ok(bool $c, string $what): void
{
    if ($c) {
        $GLOBALS['PASSES']++;
        echo "  ok   $what\n";
    } else {
        $GLOBALS['FAILS']++;
        echo "  FAIL $what\n";
    }
}

function summary(): void
{
    echo "\n== {$GLOBALS['PASSES']} passed, {$GLOBALS['FAILS']} failed\n";
    exit($GLOBALS['FAILS'] ? 1 : 0);
}

/** Plain text of terminal output: CP437 transport to text, escape sequences removed. */
function plain(string $o): string
{
    $b = mb_convert_encoding($o, 'ISO-8859-1', 'UTF-8');
    $b = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $b) ?? '';
    // CP437 bytes to UTF-8, so tests compare with normal strings
    static $rev = null;
    if ($rev === null) {
        $rev = [];
        foreach (require getenv('CB_ROOT') . '/core/cp437map.php' as $ch => $byte) {
            if ($byte >= 128) {
                $rev[$byte] = $ch;
            }
        }
    }
    $out = '';
    for ($i = 0, $n = strlen($b); $i < $n; $i++) {
        $o = ord($b[$i]);
        $out .= $o < 128 ? $b[$i] : ($rev[$o] ?? '?');
    }
    return $out;
}

final class TC
{
    public string $jar;
    public string $csrf = '';
    public ?string $rt = null;
    public ?array $ask = null;
    public array $last = [];

    public function __construct(?string $jar = null)
    {
        $this->jar = $jar ?? tempnam(sys_get_temp_dir(), 'cbjar');
        if ($jar === null) {
            file_put_contents($this->jar, '');
        }
    }

    public function req(string $url, ?array $json = null, ?array $form = null, array $files = []): array
    {
        $ch = curl_init($GLOBALS['BASE'] . $url);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 120];
        if ($json !== null) {
            $opt[CURLOPT_POST] = true;
            $opt[CURLOPT_POSTFIELDS] = json_encode($json);
            $opt[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        } elseif ($form !== null || $files) {
            $opt[CURLOPT_POST] = true;
            $data = $form ?? [];
            foreach ($files as $k => $path) {
                $data[$k] = new CURLFile($path, 'application/octet-stream', basename($path));
            }
            $opt[CURLOPT_POSTFIELDS] = $data;
        }
        curl_setopt_array($ch, $opt);
        $raw = (string)curl_exec($ch);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['code' => $code, 'head' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
    }

    public function api(string $a, string $v = '', array $extra = []): array
    {
        $r = $this->req('api.php', ['a' => $a, 'v' => $v, 'csrf' => $this->csrf] + $extra);
        $j = json_decode($r['body'], true);
        if (!is_array($j)) {
            echo "  !! bad JSON ({$r['code']}): " . substr($r['body'], 0, 300) . "\n";
            return ['o' => '', 'ask' => null, 'hang' => true];
        }
        if (!empty($j['csrf'])) {
            $this->csrf = $j['csrf'];
        }
        if (!empty($j['rt'])) {
            $this->rt = $j['rt'];
        }
        if (array_key_exists('ask', $j) && $a !== 'poll') {
            $this->ask = $j['ask'];
        } elseif ($a === 'poll' && !empty($j['ask'])) {
            $this->ask = $j['ask'];
        }
        $this->last = $j;
        return $j;
    }

    public function in(string $v): array
    {
        $r = $this->api('in', $v);
        // follow wait() prompts like the terminal does
        $guard = 0;
        while (($r['ask']['t'] ?? '') === 'wait' && empty($r['hang']) && $guard++ < 20) {
            $o = $r['o'];
            $r = $this->api('wait');
            $r['o'] = $o . $r['o'];
        }
        return $r;
    }

    /** Start a call and log in, ends at the main menu prompt. */
    public function login(string $handle, string $pass): array
    {
        $this->api('start');
        $this->in($handle);
        $r = $this->in($pass);
        $all = $r['o'];
        $guard = 0;
        // until the prompt of the main menu (hot keys with ? and G for logoff)
        while (($r['ask']['t'] ?? '') === 'hot' && empty($r['hang']) && $guard++ < 8
            && !(str_contains((string)($r['ask']['k'] ?? ''), '?') && str_contains((string)($r['ask']['k'] ?? ''), 'G'))) {
            // pauses and the new since prompt after the login
            if (($r['ask']['k'] ?? '') === '' || ($r['ask']['c'] ?? false)) {
                $r = $this->in("\r");
            } else {
                $k = $r['ask']['k'];
                $r = $this->in(str_contains($k, 'N') ? 'N' : "\r");
            }
            $all .= $r['o'];
        }
        $r['all'] = $all;
        return $r;
    }

    public function resume(?string $rt = null): array
    {
        return $this->api('resume', '', ['rt' => $rt ?? (string)$this->rt]);
    }

    public function sid(): string
    {
        foreach (file($this->jar) ?: [] as $l) {
            if (str_contains($l, 'WEBCARRIERBBS')) {
                $p = preg_split('/\s+/', trim($l));
                return (string)end($p);
            }
        }
        return '';
    }
}

/** PDO to the database of the test instance. */
function tdb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = require getenv('CB_ROOT') . '/core/config.php';
        $db = $cfg['db'];
        if ($db['driver'] === 'mysql') {
            $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?: 3306) . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['pass']);
        } else {
            $pdo = new PDO('sqlite:' . getenv('CB_ROOT') . '/' . $db['file']);
            $pdo->exec('PRAGMA busy_timeout=5000');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement
{
    $st = tdb()->prepare(str_replace('{', 'cb_', str_replace('}', '', $sql)));
    $st->execute($p);
    return $st;
}

function val(string $sql, array $p = [])
{
    return q($sql, $p)->fetchColumn();
}

function setting(string $k, string $v): void
{
    if (val('SELECT COUNT(*) FROM {settings} WHERE name=?', [$k])) {
        q('UPDATE {settings} SET value=? WHERE name=?', [$v, $k]);
    } else {
        q('INSERT INTO {settings} (name, value) VALUES (?,?)', [$k, $v]);
    }
}

/** Create a user directly in the database. */
function mkuser(string $handle, string $pass, int $level = 10): int
{
    q('DELETE FROM {users} WHERE handle_lc=?', [mb_strtolower($handle)]);
    q('INSERT INTO {users} (handle, handle_lc, pass, location, level, created, today, baud) VALUES (?,?,?,?,?,?,?,?)',
        [$handle, mb_strtolower($handle), password_hash($pass, PASSWORD_DEFAULT), 'Testhausen', $level, time() - 86400 * 30, date('Y-m-d'), -1]);
    return (int)tdb()->lastInsertId();
}

/** Backend session: logs in as handle, returns [TC, csrf]. */
function backend(string $handle, string $pass): TC
{
    $b = new TC();
    $r = $b->req('sysop/index.php');
    preg_match('/name="csrf" value="([0-9a-f]+)"/', $r['body'], $m);
    $b->req('sysop/index.php', null, ['csrf' => $m[1] ?? '', 'handle' => $handle, 'pass' => $pass]);
    $r = $b->req('sysop/index.php?p=dash');
    preg_match('/name="csrf" value="([0-9a-f]+)"/', $r['body'], $m);
    $b->csrf = $m[1] ?? '';
    $b->last = ['page' => $r['body']];
    return $b;
}
