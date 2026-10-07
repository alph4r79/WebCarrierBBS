<?php
/**
 * WebCarrier BBS: backup archive (config, screens, database, optionally the file areas).
 * Used by the backup page and before an automatic update. The archive mirrors the
 * installation, so a restore is: unpack over a fresh upload.
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

/** Write a backup archive to $zipPath. Throws on failure, records the time in last_backup. */
function cb_backup_create(string $zipPath, bool $withFiles): void
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
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('cannot create ' . basename($zipPath));
        }
        $zip->addFile(CB_ROOT . '/core/config.php', 'core/config.php');
        cb_zip_dir($zip, CB_DATA . '/screens', 'data/screens');
        if (DB::$driver === 'sqlite') {
            cb_sqlite_copy($dbCopy);
            $zip->addFile($dbCopy, 'data/' . basename(DB::$file));
        } else {
            cb_mysql_dump($dbCopy);
            $zip->addFile($dbCopy, 'database.sql');
        }
        if ($withFiles) {
            cb_zip_dir($zip, CB_DATA . '/files', 'data/files');
        }
        if (!$zip->close()) {
            throw new RuntimeException('cannot write the backup archive');
        }
    } finally {
        if (is_file($dbCopy)) {
            @unlink($dbCopy);
        }
    }
    Settings::set('last_backup', (string)time());
}
