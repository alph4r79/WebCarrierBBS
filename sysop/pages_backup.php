<?php
/**
 * Sysop backend: backup as ZIP download and the daily backups, the archives are built in core/backup.php.
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

/** Send a daily backup from data/backups. Does not return. */
function a_send_auto_backup(array $admin, string $name): never
{
    $path = CB_DATA . '/backups/' . $name;
    if (!preg_match(CB_AUTO_BACKUP_RE, $name) || !is_file($path)) {
        a_flash(t('This backup does not exist.'), 'bad');
        a_go('backup');
    }
    cb_log((int)$admin['id'], $admin['handle'], 'Automatic backup ' . $name . ' downloaded');
    session_write_close();
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="webcarrierbbs-' . $name . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

/** JSON for the overview: creates the daily backup if it is due. Only for full accounts, see the router. */
function page_autobackup(array $admin): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !cb_csrf_ok($_POST['csrf'] ?? null)) {
        echo json_encode(['state' => 'error', 'text' => t('The form has expired. Please try again.')]);
        exit;
    }
    // the backup may take a while, other pages of this session must not wait for it
    session_write_close();
    $r = cb_auto_backup((int)$admin['id'], $admin['handle']);
    if ($r['state'] === 'done') {
        $r['when'] = date('d.m.Y H:i', Settings::int('auto_backup_last', time()));
    }
    $r['text'] = match ($r['state']) {
        'done' => t('Daily backup created.'),
        'error' => t('The daily backup failed: {1}', $r['msg']),
        default => t('The daily backup is up to date.'),
    };
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Section "Automatic backups" of the backup page: setting, time of the last one, list. */
function a_auto_backup_section(): string
{
    $last = Settings::int('auto_backup_last', 0);
    $h = '<section class="panel"><h2>' . h(t('Automatic backups')) . '</h2>' .
        '<form method="post" class="form">' . a_csrf() .
        '<label class="inline"><input type="checkbox" name="auto_backup"' . (Settings::get('auto_backup', '1') === '1' ? ' checked' : '') . '> ' .
        h(t('Daily backup')) . '</label><p class="hint">' .
        h(t('When the overview of the backend is opened and the last automatic backup is older than 24 hours, a backup of the database is made in data/backups. The newest {1} are kept, older automatic backups are deleted. Backups from before an update are never touched.', CB_AUTO_BACKUP_KEEP)) .
        '</p><p><button class="btn small" name="auto_save" value="1">' . h(t('Save')) . '</button></p></form>' .
        '<p>' . h(t('Last automatic backup:')) . ' <strong>' . h($last ? date('d.m.Y H:i', $last) : t('never')) . '</strong></p>';
    $err = cb_auto_backup_error();
    if ($err !== null) {
        $h .= '<div class="flash warn"><p>' . h(t('The last try on {1} failed: {2}', date('d.m.Y H:i', (int)$err['time']), (string)$err['msg'])) . '</p></div>';
    }
    $list = cb_auto_backups();
    if (!$list) {
        return $h . '<p class="note">' . h(t('There are no automatic backups yet.')) . '</p></section>';
    }
    $h .= '<div class="tablewrap"><table><tr><th>' . h(t('Date')) . '</th><th class="num">' . h(t('Size')) . '</th><th></th></tr>';
    foreach ($list as $b) {
        $h .= '<tr><td>' . h(date('d.m.Y H:i', $b['time'])) . '<br><span class="note mono">' . h($b['name']) . '</span></td><td class="num">' . h(a_size($b['size'])) .
            '</td><td class="row-actions"><a class="btn small ghost" href="' . h(a_url('backup', ['auto' => $b['name']])) . '">' . h(t('Download')) . '</a>' .
            '<form method="post"' . a_confirm(t('Delete this backup?')) . '>' . a_csrf() .
            '<button class="btn small danger ghost" name="auto_del" value="' . h($b['name']) . '">' . h(t('Delete')) . '</button></form></td></tr>';
    }
    return $h . '</table></div></section>';
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
    if (isset($_GET['auto'])) {
        a_send_auto_backup($admin, (string)$_GET['auto']);
    }
    if (a_post() && isset($_POST['auto_save'])) {
        $on = isset($_POST['auto_backup']) ? '1' : '0';
        if ($on !== Settings::get('auto_backup', '1')) {
            Settings::set('auto_backup', $on);
            cb_log((int)$admin['id'], $admin['handle'], $on === '1' ? 'Daily backup switched on' : 'Daily backup switched off');
        }
        a_flash(t('Settings saved.'));
        a_go('backup');
    }
    if (a_post() && isset($_POST['auto_del'])) {
        $name = (string)$_POST['auto_del'];
        if (preg_match(CB_AUTO_BACKUP_RE, $name) && is_file(CB_DATA . '/backups/' . $name) && @unlink(CB_DATA . '/backups/' . $name)) {
            cb_log((int)$admin['id'], $admin['handle'], 'Automatic backup ' . $name . ' deleted');
            a_flash(t('The backup was deleted.'));
        } else {
            a_flash(t('The backup could not be deleted.'), 'bad');
        }
        a_go('backup');
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
    echo '<div class="flash warn"><p>' . h(t('The folder data/ must never be reachable over the web. On Apache the included .htaccess files take care of that. On nginx or other servers add a rule that denies /data/ and /core/, for example:')) .
        '</p><p><code>location ~ ^/(data|core)/ { deny all; }</code></p></div>';
    if (!$hasZip) {
        echo '<div class="panel"><p>' . h(t('Your webspace has no ZipArchive, so the backup cannot be created here. Save these by FTP instead:')) . '</p><ul>' .
            '<li><code>core/config.php</code></li><li><code>data/</code> ' . h(t('(complete, with the SQLite database, screens and file areas)')) . '</li></ul>' .
            (DB::$driver === 'mysql' ? '<p>' . h(t('For MySQL also export the database, for example with phpMyAdmin of your hoster.')) . '</p>' : '') . '</div>';
        echo a_auto_backup_section();
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
    echo a_auto_backup_section();
}
