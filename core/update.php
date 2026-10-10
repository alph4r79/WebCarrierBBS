<?php
/**
 * WebCarrier BBS: update check and automatic update.
 *
 * The check fetches update.json (only when switched on, automatically at most every 12 hours). An update
 * runs in two requests: cb_update_run() verifies and copies the new files with the old
 * code loaded, the next request runs with the new code, applies the migrations in
 * cb_boot() and cb_update_housekeeping() finishes the update. Every copied file is
 * written to a journal first, so an aborted run can always be rolled back.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

const CB_UPDATE_URL = 'https://webcarrier-bbs.de/update.json';
/** Ed25519 public key that signs the release archives (base64). */
const CB_UPDATE_PUBKEY = '2ddnMrW5jR0/nPnMosZldnfd1sC3tDPQnSqtNHkAUlA=';
const CB_UPDATE_DIR = CB_DATA . '/tmp/update';
const CB_MAINTENANCE_FLAG = CB_DATA . '/maintenance.flag';

final class CbUpdateError extends RuntimeException
{
    /** @param string[] $args */
    public function __construct(public readonly string $reason, public readonly array $args = [])
    {
        parent::__construct($reason . ($args ? ': ' . implode(', ', $args) : ''));
    }
}

/** Update source, core/config.php may override it with update_url and update_pubkey (for tests). */
function cb_update_source(): array
{
    $cfg = cb_installed() ? (require CB_ROOT . '/core/config.php') : [];
    return [
        'url' => is_string($cfg['update_url'] ?? null) ? $cfg['update_url'] : CB_UPDATE_URL,
        'pubkey' => is_string($cfg['update_pubkey'] ?? null) ? $cfg['update_pubkey'] : CB_UPDATE_PUBKEY,
    ];
}

/* ------------------------------------------------------------------ maintenance */

function cb_maintenance(): bool
{
    return is_file(CB_MAINTENANCE_FLAG);
}

/** Maintenance text for callers in the language of the BBS (needs a database connection). */
function cb_maintenance_text(): string
{
    Lang::load(Settings::get('language', 'en'));
    return Lang::get('maintenance');
}

/* ------------------------------------------------------------------ check */

/** HTTP GET with a hard time limit. Returns the body (or true when written to $toFile), null on any problem. */
function cb_http_get(string $url, int $timeout, ?string $toFile = null, int $maxBytes = 0): string|bool|null
{
    if (!preg_match('~^https?://~i', $url)) {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fh = $toFile !== null ? @fopen($toFile, 'wb') : null;
        if ($toFile !== null && !$fh) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_USERAGENT => 'WebCarrierBBS',
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_FAILONERROR => true,
        ]);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if ($maxBytes > 0) {
            curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes);
        }
        if ($fh) {
            curl_setopt($ch, CURLOPT_FILE, $fh);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $res = @curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($fh) {
            fclose($fh);
        }
        if ($res === false || $code !== 200) {
            return null;
        }
        return $fh ? true : (string)$res;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'WebCarrierBBS', 'follow_location' => 1, 'max_redirects' => 3,
        'ignore_errors' => false]]);
    $body = @file_get_contents($url, false, $ctx, 0, $maxBytes > 0 ? $maxBytes : null);
    if (!is_string($body)) {
        return null;
    }
    if ($toFile !== null) {
        return @file_put_contents($toFile, $body) === strlen($body) ? true : null;
    }
    return $body;
}

/** Validate update.json. Returns the cleaned data or null if anything is missing or malformed. */
function cb_update_parse(string $json): ?array
{
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return null;
    }
    $isUrl = static fn($u) => is_string($u) && preg_match('~^https?://[^\s"<>]+$~i', $u);
    if (!is_string($d['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/', $d['version'])
        || !is_string($d['date'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date'])
        || !$isUrl($d['url'] ?? null)
        || !is_int($d['size'] ?? null) || $d['size'] <= 0
        || !is_string($d['sha256'] ?? null) || !preg_match('/^[0-9a-f]{64}$/i', $d['sha256'])
        || !is_string($d['signature'] ?? null) || strlen((string)base64_decode($d['signature'], true)) !== 64
        || !is_string($d['min_php'] ?? null) || !preg_match('/^\d+(\.\d+){0,2}$/', $d['min_php'])
        || !is_bool($d['manual_only'] ?? null)
        || !is_array($d['changes'] ?? null) || !is_array($d['notices'] ?? null)) {
        return null;
    }
    $changes = [];
    foreach ($d['changes'] as $lang => $lines) {
        if (!is_string($lang) || !preg_match('/^[a-z]{2}$/', $lang) || !is_array($lines)) {
            return null;
        }
        foreach ($lines as $l) {
            if (!is_string($l)) {
                return null;
            }
        }
        $changes[$lang] = array_values(array_map(static fn($l) => mb_substr($l, 0, 300), $lines));
    }
    $notices = [];
    foreach ($d['notices'] as $n) {
        if (!is_array($n) || !is_string($n['id'] ?? null) || !preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $n['id'])
            || !is_string($n['title'] ?? null) || !is_string($n['text'] ?? null)
            || (isset($n['url']) && !$isUrl($n['url']))) {
            return null;
        }
        $notices[] = ['id' => $n['id'], 'title' => mb_substr($n['title'], 0, 120), 'text' => mb_substr($n['text'], 0, 500),
            'url' => $n['url'] ?? ''];
    }
    return ['version' => $d['version'], 'date' => $d['date'], 'url' => $d['url'], 'size' => $d['size'], 'sha256' => strtolower($d['sha256']),
        'signature' => $d['signature'], 'min_php' => $d['min_php'], 'manual_only' => $d['manual_only'], 'changes' => $changes, 'notices' => $notices];
}

/** Fetch update.json if switched on and due (or forced). Result and time are stored in the settings. */
function cb_update_check(bool $force = false): void
{
    if (Settings::get('update_check', '0') !== '1') {
        return;
    }
    if (!$force) {
        // own timestamp for the automatic check, "Check now" does not move it; older boards start from update_last_check
        $auto = Settings::get('update_last_auto');
        if (time() - ($auto !== '' ? (int)$auto : Settings::int('update_last_check', 0)) < 43200) {
            return;
        }
        Settings::set('update_last_auto', (string)time());
    }
    Settings::set('update_last_check', (string)time());
    $body = cb_http_get(cb_update_source()['url'], 3, null, 65536);
    $data = is_string($body) ? cb_update_parse($body) : null;
    Settings::set('update_status', $data ? 'ok' : 'failed');
    if ($data) {
        Settings::set('update_data', (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

/** Cached update.json data, or null. */
function cb_update_data(): ?array
{
    $d = json_decode(Settings::get('update_data'), true);
    return is_array($d) && isset($d['version']) ? $d : null;
}

/** The offered update: newer than this version and not skipped. Older or equal versions are never offered. */
function cb_update_available(): ?array
{
    if (Settings::get('update_check', '0') !== '1') {
        return null;
    }
    $d = cb_update_data();
    if (!$d || !version_compare($d['version'], CB_VERSION, '>')) {
        return null;
    }
    $skip = Settings::get('update_skip');
    if ($skip !== '' && !version_compare($d['version'], $skip, '>')) {
        return null;
    }
    return $d;
}

/** Files and folders an update may replace (relative paths), everything except config, data and install. */
function cb_update_app_paths(): array
{
    $out = [];
    $root = CB_ROOT;
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $f) use ($root) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            return !cb_update_protected($rel);
        }
    ), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    }
    return $out;
}

/** Paths an update never touches. */
function cb_update_protected(string $rel): bool
{
    return $rel === 'core/config.php' || in_array(explode('/', $rel)[0], ['data', 'install', '.git'], true);
}

/** First path that is not writable (root, app files and folders), or null. */
function cb_update_write_problem(): ?string
{
    if (!is_writable(CB_ROOT)) {
        return '.';
    }
    foreach (cb_update_app_paths() as $rel) {
        if (!is_writable(CB_ROOT . '/' . $rel)) {
            return $rel;
        }
    }
    return null;
}

/**
 * Reasons why this update can only be done by hand, as [code, arg] pairs, empty if the button may be shown.
 * Codes: php, manual, zip, sodium, git, write.
 */
function cb_update_blockers(array $d): array
{
    $out = [];
    if (version_compare(PHP_VERSION, $d['min_php'], '<')) {
        $out[] = ['php', $d['min_php']];
    }
    if ($d['manual_only']) {
        $out[] = ['manual', ''];
    }
    if (!class_exists('ZipArchive')) {
        $out[] = ['zip', ''];
    }
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        $out[] = ['sodium', ''];
    }
    if (is_dir(CB_ROOT . '/.git')) {
        $out[] = ['git', ''];
    }
    if (!$out && ($p = cb_update_write_problem()) !== null) {
        $out[] = ['write', $p];
    }
    return $out;
}

/* ------------------------------------------------------------------ update */

function cb_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Exclusive lock for update steps, false if another request holds it. */
function cb_update_lock()
{
    if (!is_dir(CB_DATA . '/tmp')) {
        @mkdir(CB_DATA . '/tmp', 0775, true);
    }
    $h = @fopen(CB_DATA . '/tmp/update.lock', 'c');
    if (!$h || !flock($h, LOCK_EX | LOCK_NB)) {
        return false;
    }
    return $h;
}

function cb_update_unlock($h): void
{
    if ($h) {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function cb_set_time_limit(int $s): void
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (function_exists('set_time_limit') && !in_array('set_time_limit', $disabled, true)) {
        @set_time_limit($s);
    }
}

function cb_journal_write(array $journal): void
{
    if (file_put_contents(CB_UPDATE_DIR . '/journal.json', json_encode($journal)) === false) {
        throw new CbUpdateError('write', ['data/tmp/update/journal.json']);
    }
}

/** Undo all journaled changes, newest first. Returns the paths that could not be restored. */
function cb_update_rollback(): array
{
    $journal = json_decode((string)@file_get_contents(CB_UPDATE_DIR . '/journal.json'), true);
    if (!is_array($journal)) {
        return [];
    }
    $failed = [];
    foreach (array_reverse($journal) as $e) {
        $dst = CB_ROOT . '/' . $e['rel'];
        if ($e['type'] === 'dir') {
            @rmdir($dst); // only empty folders, anything else stays
            continue;
        }
        $ok = $e['existed'] ? @copy(CB_UPDATE_DIR . '/alt/' . $e['rel'], $dst) : (!is_file($dst) || @unlink($dst));
        if (!$ok) {
            $failed[] = $e['rel'];
        }
    }
    @unlink(CB_UPDATE_DIR . '/journal.json');
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    return $failed;
}

/** Package root inside the unpacked archive: the folder that contains core/bootstrap.php. */
function cb_update_package_root(string $dir): ?string
{
    if (is_file($dir . '/core/bootstrap.php')) {
        return $dir;
    }
    $sub = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
    if (count($sub) === 1 && is_file($sub[0] . '/core/bootstrap.php')) {
        return $sub[0];
    }
    return null;
}

/**
 * First half of the update (old code loaded): checks, backup, maintenance, download, verify,
 * unpack and copy. Throws CbUpdateError, everything is rolled back in that case.
 */
function cb_update_run(int $uid, string $handle): void
{
    $lock = cb_update_lock();
    if (!$lock) {
        throw new CbUpdateError('locked');
    }
    $copied = false;
    try {
        $d = cb_update_available();
        if (!$d) {
            throw new CbUpdateError('none');
        }
        if ($b = cb_update_blockers($d)) {
            throw new CbUpdateError('blocked', [$b[0][0]]);
        }
        $src = cb_update_source();
        $pub = base64_decode($src['pubkey'], true);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new CbUpdateError('key');
        }
        cb_set_time_limit(120);

        // 1. room for package, unpacked copy and the saved old files
        cb_rrmdir(CB_UPDATE_DIR);
        if (!@mkdir(CB_UPDATE_DIR . '/alt', 0775, true)) {
            throw new CbUpdateError('write', ['data/tmp/update']);
        }
        $free = function_exists('disk_free_space') ? @disk_free_space(CB_UPDATE_DIR) : false;
        if ($free !== false && $free < $d['size'] * 6 + 5 * 1024 * 1024) {
            throw new CbUpdateError('space');
        }

        // 2. backup of database, config and screens
        require_once CB_ROOT . '/core/backup.php';
        if (!is_dir(CB_DATA . '/backups')) {
            @mkdir(CB_DATA . '/backups', 0775, true);
        }
        try {
            cb_backup_create(CB_DATA . '/backups/vor-update-' . CB_VERSION . '-' . date('Ymd-His') . '.zip', false);
        } catch (Throwable $e) {
            throw new CbUpdateError('backup', [$e->getMessage()]);
        }
        $old = glob(CB_DATA . '/backups/vor-update-*.zip') ?: [];
        usort($old, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($old, 3) as $f) {
            @unlink($f);
        }

        // 3. maintenance mode, running calls are ended
        if (@file_put_contents(CB_MAINTENANCE_FLAG, (string)time()) === false) {
            throw new CbUpdateError('write', ['data/maintenance.flag']);
        }
        DB::q('DELETE FROM {nodes}');
        cb_log($uid, $handle, 'Update to ' . $d['version'] . ' started');

        // 4. download and verify
        $zipFile = CB_UPDATE_DIR . '/package.zip';
        if (cb_http_get($d['url'], 25, $zipFile, $d['size'] + 1024) !== true) {
            throw new CbUpdateError('download');
        }
        if (filesize($zipFile) !== $d['size']) {
            throw new CbUpdateError('size');
        }
        if (!hash_equals($d['sha256'], (string)hash_file('sha256', $zipFile))) {
            throw new CbUpdateError('sha256');
        }
        if (!sodium_crypto_sign_verify_detached((string)base64_decode($d['signature'], true), (string)file_get_contents($zipFile), $pub)) {
            throw new CbUpdateError('signature');
        }

        // 5. unpack and check the package
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new CbUpdateError('zip');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if ($n === '' || $n[0] === '/' || str_contains($n, '\\') || str_contains($n, ':') || preg_match('~(^|/)\.\.(/|$)~', $n)) {
                $zip->close();
                throw new CbUpdateError('zip');
            }
        }
        $ok = $zip->extractTo(CB_UPDATE_DIR . '/neu');
        $zip->close();
        $root = $ok ? cb_update_package_root(CB_UPDATE_DIR . '/neu') : null;
        if ($root === null) {
            throw new CbUpdateError('structure');
        }
        preg_match("/define\\('CB_VERSION', '([0-9.]+)'\\)/", (string)file_get_contents($root . '/core/bootstrap.php'), $m);
        if (($m[1] ?? '') !== $d['version'] || !version_compare($d['version'], CB_VERSION, '>')) {
            throw new CbUpdateError('version', [$m[1] ?? '?']);
        }

        // 6. copy, every old file is saved to alt/ and journaled before it is replaced
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            if ($f->isFile() && !cb_update_protected($rel)) {
                $files[] = $rel;
            }
        }
        sort($files);
        $journal = [];
        $copied = true;
        foreach ($files as $rel) {
            $dst = CB_ROOT . '/' . $rel;
            $dirs = [];
            for ($p = dirname($rel); $p !== '.' && !is_dir(CB_ROOT . '/' . $p); $p = dirname($p)) {
                $dirs[] = $p;
            }
            foreach (array_reverse($dirs) as $p) {
                $journal[] = ['type' => 'dir', 'rel' => $p];
                cb_journal_write($journal);
                if (!@mkdir(CB_ROOT . '/' . $p, 0775)) {
                    throw new CbUpdateError('copy', [$rel]);
                }
            }
            $existed = is_file($dst);
            if ($existed) {
                @mkdir(dirname(CB_UPDATE_DIR . '/alt/' . $rel), 0775, true);
                if (!@copy($dst, CB_UPDATE_DIR . '/alt/' . $rel)) {
                    throw new CbUpdateError('copy', [$rel]);
                }
            }
            $journal[] = ['type' => 'file', 'rel' => $rel, 'existed' => $existed];
            cb_journal_write($journal);
            // rename is atomic, Windows refuses it for the running script, then overwrite in place
            $ok = @copy($root . '/' . $rel, $dst . '.new') && (@rename($dst . '.new', $dst) || @copy($dst . '.new', $dst));
            @unlink($dst . '.new');
            if (!$ok) {
                throw new CbUpdateError('copy', [$rel]);
            }
        }

        // the next request runs the new code, migrates and finishes
        if (file_put_contents(CB_UPDATE_DIR . '/state.json', json_encode(['from' => CB_VERSION, 'to' => $d['version'], 'uid' => $uid, 'handle' => $handle])) === false) {
            throw new CbUpdateError('write', ['data/tmp/update/state.json']);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    } catch (Throwable $e) {
        $err = $e instanceof CbUpdateError ? $e : new CbUpdateError('error', [$e->getMessage()]);
        $left = $copied ? cb_update_rollback() : [];
        if ($err->reason !== 'locked') {
            @unlink(CB_MAINTENANCE_FLAG);
            cb_rrmdir(CB_UPDATE_DIR);
            Settings::set('update_result', (string)json_encode(['ok' => false, 'version' => $d['version'] ?? '', 'reason' => $err->reason,
                'args' => $err->args, 'restore_failed' => $left]));
            cb_log($uid, $handle, 'Update failed: ' . $err->getMessage() . ($left ? ' (not restored: ' . implode(', ', $left) . ')' : ''));
        }
        throw $err;
    } finally {
        cb_update_unlock($lock);
    }
}

/**
 * Called by cb_boot() on every request: finishes a copied update with the new code, or
 * cleans up after a crash (maintenance flag older than 15 minutes).
 */
function cb_update_housekeeping(): void
{
    $state = CB_UPDATE_DIR . '/state.json';
    $stale = cb_maintenance() && filemtime(CB_MAINTENANCE_FLAG) < time() - 900;
    if (!is_file($state) && !$stale) {
        return;
    }
    $lock = cb_update_lock();
    if (!$lock) {
        return;
    }
    try {
        $s = json_decode((string)@file_get_contents($state), true);
        if (is_array($s)) {
            // migrations already ran in cb_boot(), with the new code
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            @unlink(CB_MAINTENANCE_FLAG);
            cb_rrmdir(CB_UPDATE_DIR);
            Settings::set('update_result', (string)json_encode(['ok' => true, 'version' => CB_VERSION, 'from' => (string)$s['from']]));
            Settings::set('update_skip', '');
            cb_log((int)$s['uid'], (string)$s['handle'], 'Update from ' . $s['from'] . ' to ' . CB_VERSION . ' finished');
        } elseif ($stale) {
            $left = cb_update_rollback();
            @unlink(CB_MAINTENANCE_FLAG);
            cb_rrmdir(CB_UPDATE_DIR);
            Settings::set('update_stale', (string)json_encode(['time' => time(), 'restore_failed' => $left]));
            cb_log(0, 'System', 'Removed a stale maintenance flag' . ($left ? ', not restored: ' . implode(', ', $left) : ''));
        }
    } finally {
        cb_update_unlock($lock);
    }
}
