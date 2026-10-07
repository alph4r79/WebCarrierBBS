<?php
/**
 * Sysop backend: backup as ZIP download, the archive itself is built in core/backup.php.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

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

/** Build the archive and send it. Does not return. */
function a_send_backup(array $admin, bool $withFiles): never
{
    @set_time_limit(0);
    ignore_user_abort(true);
    $zipPath = CB_DATA . '/tmp/backup_' . bin2hex(random_bytes(8)) . '.zip';
    register_shutdown_function(static function () use ($zipPath) {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }
    });
    cb_backup_create($zipPath, $withFiles);
    cb_log((int)$admin['id'], $admin['handle'], 'Backup downloaded' . ($withFiles ? ' (with files)' : ''));

    session_write_close();
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($zipPath));
    header('Content-Disposition: attachment; filename="webcarrierbbs-backup-' . date('Y-m-d-His') . '.zip"');
    header('Cache-Control: private, no-store');
    readfile($zipPath);
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
    $filesSize = cb_dir_size(CB_DATA . '/files');
    echo '<form method="post" class="form panel">' . a_csrf() .
        '<p>' . h(t('The archive contains core/config.php, data/screens and the database ({1}).', DB::$driver === 'mysql' ? t('as SQL dump') : 'SQLite')) . '</p>' .
        '<label class="inline"><input type="checkbox" name="files"> ' . h(t('Include the file areas (data/files, currently {1})', a_size($filesSize))) . '</label>' .
        '<p class="note">' . h(t('Large backups can fail because of the time limit of your hoster. Then save data/files by FTP instead.')) . '</p>' .
        '<button class="btn" type="submit">' . h(t('Download backup')) . '</button></form>';
    echo '<p class="note">' . h(t('To restore, upload WebCarrier BBS fresh and unpack the archive into the same folder, the installer is not needed then.')) .
        (DB::$driver === 'mysql' ? ' ' . h(t('Import database.sql into the database first, for example with phpMyAdmin.')) : '') . '</p>';
}
