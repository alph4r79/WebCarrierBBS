<?php
/**
 * Sysop backend: backup as ZIP download (config, screens, database, optionally the file areas).
 * The archive mirrors the installation, so a restore is: unpack over a fresh upload.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Total size of all files below a folder. */
function a_dir_size(string $dir): int
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

function a_size(int $bytes): string
{
    if ($bytes >= 1 << 30) {
        return number_format($bytes / (1 << 30), 1, ',', '.') . ' GB';
    }
    if ($bytes >= 1 << 20) {
        return number_format($bytes / (1 << 20), 1, ',', '.') . ' MB';
    }
    return number_format((int)ceil($bytes / 1024), 0, ',', '.') . ' KB';
}

/** Add a folder recursively, $prefix is its path inside the archive. */
function a_zip_dir(ZipArchive $zip, string $dir, string $prefix): void
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
function a_sqlite_copy(string $dst): void
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
function a_mysql_dump(string $dst): void
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

/** Build the archive and send it. Does not return. */
function a_send_backup(array $admin, bool $withFiles): never
{
    @set_time_limit(0);
    ignore_user_abort(true);
    $tmpDir = CB_DATA . '/tmp';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0775, true);
    }
    $base = $tmpDir . '/backup_' . bin2hex(random_bytes(8));
    $temp = [$base . '.zip'];
    register_shutdown_function(static function () use (&$temp) {
        foreach ($temp as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    });

    $zip = new ZipArchive();
    if ($zip->open($base . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create ' . $base . '.zip');
    }
    $zip->addFile(CB_ROOT . '/core/config.php', 'core/config.php');
    a_zip_dir($zip, CB_DATA . '/screens', 'data/screens');
    if (DB::$driver === 'sqlite') {
        $temp[] = $base . '.sqlite';
        a_sqlite_copy($base . '.sqlite');
        $zip->addFile($base . '.sqlite', 'data/' . basename(DB::$file));
    } else {
        $temp[] = $base . '.sql';
        a_mysql_dump($base . '.sql');
        $zip->addFile($base . '.sql', 'database.sql');
    }
    if ($withFiles) {
        a_zip_dir($zip, CB_DATA . '/files', 'data/files');
    }
    if (!$zip->close()) {
        throw new RuntimeException('cannot write the backup archive');
    }
    cb_log((int)$admin['id'], $admin['handle'], 'Backup downloaded' . ($withFiles ? ' (with files)' : ''));

    session_write_close();
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($base . '.zip'));
    header('Content-Disposition: attachment; filename="webcarrierbbs-backup-' . date('Y-m-d-His') . '.zip"');
    header('Cache-Control: private, no-store');
    readfile($base . '.zip');
    exit;
}

function page_backup(array $admin): void
{
    $hasZip = class_exists('ZipArchive');
    // leftovers of aborted runs
    foreach (glob(CB_DATA . '/tmp/backup_*') ?: [] as $old) {
        if (filemtime($old) < time() - 3600) {
            @unlink($old);
        }
    }
    if ($hasZip && a_post()) {
        try {
            a_send_backup($admin, isset($_POST['files']));
        } catch (Throwable $e) {
            error_log('WebCarrier BBS backup: ' . $e->getMessage());
            a_flash(t('The backup failed: {1}', $e->getMessage()), 'bad');
            a_go('backup');
        }
    }

    echo '<h1>' . h(t('Backup')) . '</h1>';
    echo '<div class="flash warn"><p>' . h(t('The backup contains core/config.php with the access data of the database and all password hashes. Keep it in a safe place.')) . '</p></div>';
    if (!$hasZip) {
        echo '<div class="panel"><p>' . h(t('Your webspace has no ZipArchive, so the backup cannot be created here. Save these by FTP instead:')) . '</p><ul>' .
            '<li><code>core/config.php</code></li><li><code>data/</code> ' . h(t('(complete, with the SQLite database, screens and file areas)')) . '</li></ul>' .
            (DB::$driver === 'mysql' ? '<p>' . h(t('For MySQL also export the database, for example with phpMyAdmin of your hoster.')) . '</p>' : '') . '</div>';
        return;
    }
    $filesSize = a_dir_size(CB_DATA . '/files');
    echo '<form method="post" class="form panel">' . a_csrf() .
        '<p>' . h(t('The archive contains core/config.php, data/screens and the database ({1}).', DB::$driver === 'mysql' ? t('as SQL dump') : 'SQLite')) . '</p>' .
        '<label class="inline"><input type="checkbox" name="files"> ' . h(t('Include the file areas (data/files, currently {1})', a_size($filesSize))) . '</label>' .
        '<p class="note">' . h(t('Large backups can fail because of the time limit of your hoster. Then save data/files by FTP instead.')) . '</p>' .
        '<button class="btn" type="submit">' . h(t('Download backup')) . '</button></form>';
    echo '<p class="note">' . h(t('To restore, upload WebCarrier BBS fresh and unpack the archive into the same folder, the installer is not needed then.')) .
        (DB::$driver === 'mysql' ? ' ' . h(t('Import database.sql into the database first, for example with phpMyAdmin.')) : '') . '</p>';
}
