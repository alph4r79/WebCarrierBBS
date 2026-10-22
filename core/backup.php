<?php
/**
 * WebCarrier BBS: backup archive (config, screens, database, optionally the file areas).
 * Used by the backup page and before an automatic update. The archive mirrors the
 * installation, so a restore is: unpack over a fresh upload. The daily backup started
 * by the overview of the backend holds only the database.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Total size of all files below a folder. */
function cb_dir_size(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $sum = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) {
            $sum += $f->getSize();
        }
    }
    return $sum;
}

/** Add a folder recursively, $prefix is its path inside the archive. */
function cb_zip_dir(ZipArchive $zip, string $dir, string $prefix): void
{
    if (!is_dir($dir)) {
        return;
    }
    $zip->addEmptyDir($prefix);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = $prefix . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        if ($f->isDir()) {
            $zip->addEmptyDir($rel);
        } else {
            $zip->addFile($f->getPathname(), $rel);
        }
    }
}

/** Consistent copy of the SQLite database. */
function cb_sqlite_copy(string $dst): void
{
    $ver = (string)DB::val('SELECT sqlite_version()');
    if (version_compare($ver, '3.27.0', '>=')) {
        DB::$pdo->exec('VACUUM INTO ' . DB::$pdo->quote($dst));
        return;
    }
    // older SQLite: flush the WAL into the main file, then copy it
    DB::$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    if (!copy(DB::$file, $dst)) {
        throw new RuntimeException('copy of the database failed');
    }
}

/** SQL dump of all WebCarrier tables (MySQL), written in blocks to $dst. */
function cb_mysql_dump(string $dst): void
{
    $out = fopen($dst, 'wb');
    if (!$out) {
        throw new RuntimeException('cannot write ' . $dst);
    }
    fwrite($out, "-- WebCarrier BBS " . CB_VERSION . " database backup, " . date('Y-m-d H:i:s') . "\n" .
        "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = [];
    foreach (DB::schema() as $sql) {
        if (preg_match('/^CREATE TABLE \{([a-z_]+)\}/', $sql, $m)) {
            $tables[] = DB::$prefix . $m[1];
        }
    }
    $pdo = DB::$pdo;
    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
        fwrite($out, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $create[1] . ";\n\n");
        // unbuffered, rows are streamed instead of loaded at once
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $st = $pdo->query('SELECT * FROM `' . $table . '`');
        $block = [];
        while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
            $block[] = '(' . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
            if (count($block) >= 200) {
                fwrite($out, 'INSERT INTO `' . $table . '` VALUES ' . implode(",\n", $block) . ";\n");
                $block = [];
            }
        }
        if ($block) {
            fwrite($out, 'INSERT INTO `' . $table . '` VALUES ' . implode(",\n", $block) . ";\n");
        }
        $st->closeCursor();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        fwrite($out, "\n");
    }
    fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($out);
}

/**
 * Write a backup archive to $zipPath. Throws on failure. A full backup records the time in last_backup,
 * one with $dbOnly (daily backup) contains only the database and leaves last_backup alone.
 */
function cb_backup_create(string $zipPath, bool $withFiles, bool $dbOnly = false): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is missing');
    }
    $tmpDir = CB_DATA . '/tmp';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0775, true);
    }
    $dbCopy = $tmpDir . '/backup_' . bin2hex(random_bytes(8)) . (DB::$driver === 'sqlite' ? '.sqlite' : '.sql');
    try {
        $zip = new ZipArchive();
        if (@$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('cannot create ' . basename($zipPath));
        }
        if (!$dbOnly) {
            $zip->addFile(CB_ROOT . '/core/config.php', 'core/config.php');
            cb_zip_dir($zip, CB_DATA . '/screens', 'data/screens');
        }
        if (DB::$driver === 'sqlite') {
            cb_sqlite_copy($dbCopy);
            $zip->addFile($dbCopy, 'data/' . basename(DB::$file));
        } else {
            cb_mysql_dump($dbCopy);
            $zip->addFile($dbCopy, 'database.sql');
        }
        if ($withFiles && !$dbOnly) {
            cb_zip_dir($zip, CB_DATA . '/files', 'data/files');
        }
        if (!@$zip->close()) {
            throw new RuntimeException('cannot write the backup archive');
        }
    } finally {
        if (is_file($dbCopy)) {
            @unlink($dbCopy);
        }
    }
    if (!$dbOnly) {
        Settings::set('last_backup', (string)time());
    }
}

/* ------------------------------------------------------------------ daily backup */

/** Daily backups kept in data/backups, older ones are deleted after each new one. */
const CB_AUTO_BACKUP_KEEP = 7;

/** File name pattern of a daily backup, also the check for downloads and deletes. */
const CB_AUTO_BACKUP_RE = '/^auto-\d{8}-\d{6}-[0-9a-f]{8}\.zip$/';

/** Daily backups, newest first: [name, size, time]. */
function cb_auto_backups(): array
{
    $out = [];
    foreach (glob(CB_DATA . '/backups/auto-*.zip') ?: [] as $f) {
        if (preg_match(CB_AUTO_BACKUP_RE, basename($f))) {
            $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
        }
    }
    usort($out, static fn($a, $b) => [$b['time'], $b['name']] <=> [$a['time'], $a['name']]);
    return $out;
}

/** Last error of the daily backup: ['time' => ..., 'msg' => ...] or null. */
function cb_auto_backup_error(): ?array
{
    $e = json_decode(Settings::get('auto_backup_error'), true);
    return is_array($e) && isset($e['time'], $e['msg']) ? $e : null;
}

/**
 * True if the overview should start a daily backup: switched on, the last one older than 24 hours and
 * no failed try in the last hour (a full disk is not tried again on every page view).
 */
function cb_auto_backup_due(): bool
{
    if (Settings::get('auto_backup', '1') !== '1' || Settings::int('auto_backup_last', 0) > time() - 86400) {
        return false;
    }
    $err = cb_auto_backup_error();
    return $err === null || (int)$err['time'] < time() - 3600;
}

/**
 * Create the daily backup if it is due. A lock lets only one of several requests at the same time
 * build it, the others wait and then find it done. Returns ['state' => done|skip|error, 'msg' => ...].
 * Never throws.
 */
function cb_auto_backup(int $uid, string $handle): array
{
    if (!cb_auto_backup_due()) {
        return ['state' => 'skip', 'msg' => ''];
    }
    @set_time_limit(300);
    ignore_user_abort(true);
    $lock = false;
    $path = '';
    try {
        // a folder fixed by hand since the last try must not come from the realpath cache
        clearstatcache(true);
        foreach (['/tmp', '/backups'] as $d) {
            if (!is_dir(CB_DATA . $d) && !@mkdir(CB_DATA . $d, 0775, true)) {
                throw new RuntimeException('cannot create data' . $d);
            }
        }
        $lock = @fopen(CB_DATA . '/tmp/autobackup.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('cannot lock data/tmp/autobackup.lock');
        }
        // another request may have made it while this one waited for the lock
        $last = DB::val("SELECT value FROM {settings} WHERE name='auto_backup_last'");
        if (is_numeric($last) && (int)$last > time() - 86400) {
            return ['state' => 'skip', 'msg' => ''];
        }
        $name = 'auto-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
        $path = CB_DATA . '/backups/' . $name;
        cb_backup_create($path, false, true);
        Settings::set('auto_backup_last', (string)time());
        Settings::set('auto_backup_error', '');
        foreach (array_slice(cb_auto_backups(), CB_AUTO_BACKUP_KEEP) as $old) {
            @unlink(CB_DATA . '/backups/' . $old['name']);
        }
        cb_log($uid, $handle, 'Automatic backup ' . $name . ' created');
        return ['state' => 'done', 'msg' => $name];
    } catch (Throwable $e) {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
        $msg = mb_substr(str_replace(CB_ROOT, '', $e->getMessage()), 0, 200);
        try {
            Settings::set('auto_backup_error', (string)json_encode(['time' => time(), 'msg' => $msg]));
            cb_log($uid, $handle, 'Automatic backup failed: ' . $msg);
        } catch (Throwable $e2) {
            error_log('WebCarrier BBS automatic backup: ' . $msg . ' / ' . $e2->getMessage());
        }
        return ['state' => 'error', 'msg' => $msg];
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
