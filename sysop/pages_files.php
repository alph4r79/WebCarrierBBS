<?php
/**
 * Sysop backend: file areas, files, approval of uploads and the bulk import.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

function a_farea_names(): array
{
    $n = [];
    foreach (DB::all('SELECT id, name FROM {file_areas} ORDER BY sort, id') as $a) {
        $n[(int)$a['id']] = $a['name'];
    }
    return $n;
}

function a_diz(string $path): string
{
    if (!class_exists('ZipArchive') || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
        return '';
    }
    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        return '';
    }
    $out = '';
    for ($i = 0; $i < $z->numFiles; $i++) {
        if (strtolower(basename((string)$z->getNameIndex($i))) === 'file_id.diz') {
            $out = CP437::toUtf8((string)$z->getFromIndex($i, 4096));
            break;
        }
    }
    $z->close();
    return trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', str_replace("\r", '', $out)) ?? '');
}

/** Store a file in an area. Returns an error text or null. */
function a_store_file(int $area, string $src, string $name, string $desc, int $uid, string $handle, bool $move): ?string
{
    $name = cb_safe_filename($name);
    if ($name === '') {
        return t('Invalid file name.');
    }
    if (DB::val('SELECT COUNT(*) FROM {files} WHERE area_id=? AND LOWER(filename)=?', [$area, strtolower($name)])) {
        return t('{1} exists already in this area.', $name);
    }
    $dir = CB_DATA . '/files/' . $area;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return t('Cannot create {1}.', 'data/files/' . $area);
    }
    $dst = $dir . '/' . $name;
    if (is_file($dst)) {
        return t('{1} exists already in this area.', $name);
    }
    $ok = $move ? @rename($src, $dst) : @copy($src, $dst);
    if (!$ok && $move) {
        $ok = @copy($src, $dst) && @unlink($src);
    }
    if (!$ok) {
        return t('Could not store {1}.', $name);
    }
    DB::insert('files', ['area_id' => $area, 'filename' => $name, 'size' => (int)filesize($dst), 'description' => mb_substr($desc, 0, 2000),
        'uploader_id' => $uid, 'uploader' => $handle, 'added' => time(), 'downloads' => 0, 'approved' => 1, 'storage' => $area . '/' . $name]);
    return null;
}

/** Delete file and database row. False if the file could not be removed from the disk (row is kept). */
function a_delete_file(array $f): bool
{
    $p = CB_DATA . '/files/' . $f['storage'];
    if ($f['storage'] !== '' && is_file($p) && !@unlink($p)) {
        return false;
    }
    DB::q('DELETE FROM {files} WHERE id=?', [(int)$f['id']]);
    return true;
}

/** Move or rename a stored file (disk and database). Returns an error text or null. */
function a_relocate_file(array $f, int $area, string $name): ?string
{
    if (DB::val('SELECT COUNT(*) FROM {files} WHERE area_id=? AND LOWER(filename)=? AND id<>?', [$area, strtolower($name), (int)$f['id']])) {
        return t('{1} exists already in this area.', $name);
    }
    $dir = CB_DATA . '/files/' . $area;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return t('Cannot create {1}.', 'data/files/' . $area);
    }
    $src = CB_DATA . '/files/' . $f['storage'];
    $dst = $dir . '/' . $name;
    $sameFile = $f['storage'] !== '' && strtolower($src) === strtolower($dst);
    if (is_file($dst) && !$sameFile) {
        return t('{1} exists already in this area.', $name);
    }
    if ($f['storage'] === '' || !is_file($src)) {
        return t('{1} is missing on the disk.', $f['filename']);
    }
    if ($src !== $dst) {
        // two steps, so a change of case only also works on case insensitive file systems
        $tmp = $dir . '/.mv_' . bin2hex(random_bytes(6));
        if (!@rename($src, $tmp) || !@rename($tmp, $dst)) {
            if (is_file($tmp)) {
                @rename($tmp, $src);
            }
            return t('Could not store {1}.', $name);
        }
    }
    DB::update('files', ['area_id' => $area, 'filename' => $name, 'storage' => $area . '/' . $name], 'id=?', [(int)$f['id']]);
    return null;
}

function page_fileareas(array $admin): void
{
    if (a_post()) {
        if (isset($_POST['del'])) {
            $id = (int)$_POST['del'];
            $left = 0;
            foreach (DB::all('SELECT * FROM {files} WHERE area_id=?', [$id]) as $f) {
                $left += a_delete_file($f) ? 0 : 1;
            }
            if ($left > 0) {
                a_flash(t('{1} file(s) could not be deleted from the disk, the area was kept.', $left), 'bad');
                a_go('fileareas');
            }
            @rmdir(CB_DATA . '/files/' . $id);
            DB::q('DELETE FROM {file_areas} WHERE id=?', [$id]);
            a_flash(t('Area and its files deleted.'));
            a_go('fileareas');
        }
        foreach ((array)($_POST['a'] ?? []) as $id => $r) {
            $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 60);
            if ($name === '') {
                continue;
            }
            DB::update('file_areas', [
                'name' => $name, 'description' => mb_substr(trim((string)($r['description'] ?? '')), 0, 120),
                'dl_level' => max(0, min(255, (int)($r['dl_level'] ?? 10))), 'ul_level' => max(0, min(255, (int)($r['ul_level'] ?? 10))),
                'uploads' => isset($r['uploads']) ? 1 : 0, 'sort' => (int)($r['sort'] ?? 0),
            ], 'id=?', [(int)$id]);
        }
        $n = a_in('new_name', 60);
        if ($n !== '') {
            DB::insert('file_areas', ['name' => $n, 'description' => a_in('new_description', 120), 'dl_level' => a_int('new_dl', 0, 255),
                'ul_level' => a_int('new_ul', 0, 255), 'uploads' => isset($_POST['new_uploads']) ? 1 : 0,
                'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+10 FROM {file_areas}')]);
        }
        a_flash(t('File areas saved.'));
        a_go('fileareas');
    }
    echo '<h1>' . h(t('File areas')) . '</h1>';
    echo '<p class="note">' . h(t('Access level: who may see and download. Upload level: who may upload, if uploads are switched on for the area.')) . '</p>';
    echo '<form method="post" class="form">' . a_csrf() . '<div class="tablewrap"><table><tr><th>' . h(t('Sort')) . '</th><th>' . h(t('Name')) . '</th><th>' .
        h(t('Description')) . '</th><th>' . h(t('Access level')) . '</th><th>' . h(t('Uploads')) . '</th><th>' . h(t('Upload level')) . '</th><th class="num">' . h(t('Files')) . '</th><th></th></tr>';
    foreach (DB::all('SELECT * FROM {file_areas} ORDER BY sort, id') as $a) {
        $id = (int)$a['id'];
        $n = (int)DB::val('SELECT COUNT(*) FROM {files} WHERE area_id=?', [$id]);
        echo "<tr><td><input type=\"number\" name=\"a[$id][sort]\" value=\"" . (int)$a['sort'] . "\" style=\"width:5em\"></td>" .
            "<td><input name=\"a[$id][name]\" value=\"" . h($a['name']) . "\"></td><td><input name=\"a[$id][description]\" value=\"" . h($a['description']) . "\"></td>" .
            "<td><input type=\"number\" name=\"a[$id][dl_level]\" value=\"" . (int)$a['dl_level'] . "\" style=\"width:5em\"></td>" .
            "<td><input type=\"checkbox\" name=\"a[$id][uploads]\"" . ((int)$a['uploads'] ? ' checked' : '') . "></td>" .
            "<td><input type=\"number\" name=\"a[$id][ul_level]\" value=\"" . (int)$a['ul_level'] . "\" style=\"width:5em\"></td>" .
            '<td class="num"><a href="' . h(a_url('files', ['area' => $id])) . '">' . $n . '</a></td>' .
            '<td><button class="btn small danger ghost" name="del" value="' . $id . '" onclick="return confirm(' .
            h(json_encode(t('Delete this area with all {1} files from the disk?', $n))) . ')">' . h(t('Delete')) . '</button></td></tr>';
    }
    echo '<tr><td></td><td><input name="new_name" placeholder="' . h(t('New area')) . '"></td><td><input name="new_description" placeholder="' . h(t('Description')) . '"></td>' .
        '<td><input type="number" name="new_dl" value="10" style="width:5em"></td><td><input type="checkbox" name="new_uploads"></td>' .
        '<td><input type="number" name="new_ul" value="10" style="width:5em"></td><td></td><td></td></tr>';
    echo '</table></div><p><button class="btn" type="submit">' . h(t('Save areas')) . '</button></p></form>';
}

function page_files(array $admin): void
{
    $area = (int)($_GET['area'] ?? 0);
    $names = a_farea_names();
    if (a_post()) {
        $back = $area ? ['area' => $area] : [];
        if (isset($_POST['approve'])) {
            DB::q('UPDATE {files} SET approved=1 WHERE id=?', [(int)$_POST['approve']]);
            a_flash(t('File approved.'));
        } elseif (isset($_POST['del'])) {
            $f = DB::row('SELECT * FROM {files} WHERE id=?', [(int)$_POST['del']]);
            if ($f && !a_delete_file($f)) {
                a_flash(t('{1} could not be deleted from the disk.', $f['filename']), 'bad');
                a_go('files', $back);
            }
            if ($f) {
                cb_log((int)$admin['id'], $admin['handle'], 'Deleted file ' . $f['filename']);
            }
            a_flash(t('File deleted.'));
        } elseif (isset($_POST['save_file'])) {
            $f = DB::row('SELECT * FROM {files} WHERE id=?', [(int)$_POST['save_file']]);
            if ($f) {
                DB::q('UPDATE {files} SET description=? WHERE id=?', [a_in('desc', 2000), (int)$f['id']]);
                $name = cb_safe_filename(a_in('filename', 120));
                $err = null;
                if ($name === '') {
                    $err = t('Invalid file name.');
                } elseif ($name !== $f['filename'] && cb_dangerous_filename($name)) {
                    $err = t('This file type is not allowed.');
                } elseif ($name !== $f['filename']) {
                    $err = a_relocate_file($f, (int)$f['area_id'], $name);
                    if ($err === null) {
                        cb_log((int)$admin['id'], $admin['handle'], 'Renamed file ' . $f['filename'] . ' to ' . $name);
                    }
                }
                if ($err !== null) {
                    a_flash($err, 'bad');
                    a_go('files', ['area' => (int)$f['area_id'], 'edit' => (int)$f['id']]);
                }
                a_flash(t('File saved.'));
                a_go('files', ['area' => (int)$f['area_id']]);
            }
        } elseif (isset($_POST['bulk'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
            $op = (string)$_POST['bulk'];
            $target = a_int('move_to');
            if (!$ids) {
                a_flash(t('No files selected.'), 'warn');
            } elseif ($op === 'move' && !isset($names[$target])) {
                a_flash(t('Please choose a file area.'), 'bad');
            } else {
                $done = 0;
                foreach ($ids as $fid) {
                    $f = DB::row('SELECT * FROM {files} WHERE id=?', [$fid]);
                    if (!$f) {
                        continue;
                    }
                    if ($op === 'approve') {
                        DB::q('UPDATE {files} SET approved=1 WHERE id=?', [$fid]);
                        $done++;
                    } elseif ($op === 'delete') {
                        if (!a_delete_file($f)) {
                            a_flash(t('{1} could not be deleted from the disk.', $f['filename']), 'bad');
                            continue;
                        }
                        cb_log((int)$admin['id'], $admin['handle'], 'Deleted file ' . $f['filename']);
                        $done++;
                    } elseif ($op === 'move' && (int)$f['area_id'] !== $target) {
                        $err = a_relocate_file($f, $target, (string)$f['filename']);
                        if ($err !== null) {
                            a_flash(t('{1} skipped: {2}', $f['filename'], $err), 'warn');
                            continue;
                        }
                        cb_log((int)$admin['id'], $admin['handle'], 'Moved file ' . $f['filename'] . ' to area ' . $target);
                        $done++;
                    }
                }
                $msg = match ($op) {
                    'approve' => t('{1} file(s) approved.', $done),
                    'delete' => t('{1} file(s) deleted.', $done),
                    'move' => t('{1} file(s) moved.', $done),
                    default => null,
                };
                if ($msg !== null) {
                    a_flash($msg);
                }
            }
        } elseif (isset($_POST['upload'])) {
            $target = a_int('target');
            if (!isset($names[$target])) {
                a_flash(t('Please choose a file area.'), 'bad');
                a_go('files', $back);
            }
            $up = $_FILES['files'] ?? null;
            $count = 0;
            if ($up && is_array($up['name'])) {
                foreach ($up['name'] as $i => $n) {
                    if (($up['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'][$i])) {
                        continue;
                    }
                    $tmp = CB_DATA . '/tmp/sysop_' . bin2hex(random_bytes(8));
                    move_uploaded_file($up['tmp_name'][$i], $tmp);
                    $desc = a_in('updesc', 2000);
                    if ($desc === '') {
                        $desc = a_diz($tmp);
                    }
                    $err = a_store_file($target, $tmp, (string)$n, $desc, (int)$admin['id'], $admin['handle'], true);
                    if ($err) {
                        @unlink($tmp);
                        a_flash($err, 'bad');
                    } else {
                        $count++;
                    }
                }
            }
            a_flash(t('{1} file(s) added.', $count));
            a_go('files', ['area' => $target]);
        }
        a_go('files', $back);
    }

    echo '<h1>' . h(t('Files')) . '</h1>';
    $pending = DB::all('SELECT * FROM {files} WHERE approved=0 ORDER BY added');
    if ($pending) {
        echo '<h2>' . h(t('Uploads waiting for your check')) . '</h2><div class="tablewrap"><table><tr><th>' . h(t('File')) . '</th><th>' . h(t('Area')) .
            '</th><th>' . h(t('From')) . '</th><th class="num">' . h(t('Size')) . '</th><th>' . h(t('Description')) . '</th><th></th></tr>';
        foreach ($pending as $f) {
            echo '<tr class="pending"><td>' . h($f['filename']) . '</td><td>' . h($names[(int)$f['area_id']] ?? '?') . '</td><td>' . h($f['uploader']) .
                '</td><td class="num">' . cb_kb((int)$f['size']) . '</td><td>' . nl2br(h($f['description'])) . '</td><td><form method="post" class="row-actions">' . a_csrf() .
                '<button class="btn small" name="approve" value="' . (int)$f['id'] . '">' . h(t('Approve')) . '</button>' .
                '<button class="btn small danger ghost" name="del" value="' . (int)$f['id'] . '" onclick="return confirm(' . h(json_encode(t('Delete this file?'))) . ')">' .
                h(t('Delete')) . '</button></form></td></tr>';
        }
        echo '</table></div>';
    }

    echo '<section class="panel"><h2>' . h(t('Add files')) . '</h2><form method="post" enctype="multipart/form-data" class="form">' . a_csrf() .
        '<div class="grid2"><label>' . h(t('File area')) . '<select name="target">';
    foreach ($names as $id => $n) {
        echo '<option value="' . $id . '"' . ($id === $area ? ' selected' : '') . '>' . h($n) . '</option>';
    }
    echo '</select></label><label>' . h(t('Files')) . '<input type="file" name="files[]" multiple></label></div>' .
        '<label>' . h(t('Description (empty = FILE_ID.DIZ from the ZIP)')) . '<input name="updesc" maxlength="2000"></label>' .
        '<p class="note">' . h(t('For many files use the file import instead. The PHP limit of your webspace is {1} per upload.', cb_kb(Engine::uploadMaxBytes()))) . '</p>' .
        '<button class="btn" name="upload" value="1">' . h(t('Upload')) . '</button></form></section>';

    echo '<form method="get" class="form row-actions"><input type="hidden" name="p" value="files"><select name="area" style="max-width:320px;margin:0">';
    foreach ($names as $id => $n) {
        echo '<option value="' . $id . '"' . ($id === $area ? ' selected' : '') . '>' . h($n) . '</option>';
    }
    echo '</select><button class="btn" type="submit">' . h(t('Show')) . '</button></form><br>';
    if (!$area || !isset($names[$area])) {
        return;
    }
    $edit = DB::row('SELECT * FROM {files} WHERE id=? AND area_id=?', [(int)($_GET['edit'] ?? 0), $area]);
    if ($edit) {
        echo '<form method="post" class="form panel">' . a_csrf() . '<h2>' . h(t('Edit file')) . '</h2>' .
            '<label>' . h(t('File name')) . '<input name="filename" class="mono" maxlength="80" value="' . h($edit['filename']) . '">' .
            '<span class="hint">' . h(t('Letters, digits, dot, dash and underscore. Other characters become an underscore.')) . '</span></label>' .
            '<label>' . h(t('Description')) . '<textarea name="desc" class="mono" style="min-height:120px">' . h($edit['description']) . '</textarea></label>' .
            '<p class="row-actions"><button class="btn" name="save_file" value="' . (int)$edit['id'] . '">' . h(t('Save')) . '</button> ' .
            '<a class="btn ghost" href="' . h(a_url('files', ['area' => $area])) . '">' . h(t('Back')) . '</a></p></form>';
    }
    $files = DB::all('SELECT * FROM {files} WHERE area_id=? ORDER BY filename', [$area]);
    if (!$files) {
        echo '<div class="panel"><p>' . h(t('No files in this area.')) . '</p></div>';
        return;
    }
    $all = 'for(const c of this.form.querySelectorAll(\'input[name="ids[]"]\'))c.checked=this.checked';
    echo '<form method="post" action="' . h(a_url('files', ['area' => $area])) . '">' . a_csrf() .
        '<div class="tablewrap"><table><tr><th class="check"><input type="checkbox" onclick="' . h($all) . '" aria-label="' . h(t('Select all')) . '"></th><th>' .
        h(t('File')) . '</th><th class="num">' . h(t('Size')) . '</th><th>' . h(t('Date')) . '</th><th class="num">DL</th><th>' . h(t('Description')) . '</th><th></th></tr>';
    foreach ($files as $f) {
        $id = (int)$f['id'];
        echo '<tr' . ((int)$f['approved'] ? '' : ' class="pending"') . '><td class="check"><input type="checkbox" name="ids[]" value="' . $id . '" aria-label="' .
            h($f['filename']) . '"></td><td>' . h($f['filename']) . '</td><td class="num">' . cb_kb((int)$f['size']) .
            '</td><td class="num">' . date('d.m.y', (int)$f['added']) . '</td><td class="num">' . (int)$f['downloads'] . '</td><td>' .
            nl2br(h($f['description'])) . '</td><td><a class="btn small ghost" href="' . h(a_url('files', ['area' => $area, 'edit' => $id])) . '">' .
            h(t('Edit')) . '</a></td></tr>';
    }
    echo '</table></div><div class="bulk"><span class="note">' . h(t('Selected files:')) . '</span>' .
        '<button class="btn small" name="bulk" value="approve">' . h(t('Approve')) . '</button>' .
        '<button class="btn small danger ghost" name="bulk" value="delete" onclick="return confirm(' . h(json_encode(t('Delete the selected files?'))) . ')">' .
        h(t('Delete')) . '</button><span class="move"><select name="move_to" aria-label="' . h(t('Move to')) . '"><option value="0">' . h(t('Move to')) . '</option>';
    foreach ($names as $aid => $n) {
        if ($aid !== $area) {
            echo '<option value="' . $aid . '">' . h($n) . '</option>';
        }
    }
    echo '</select><button class="btn small ghost" name="bulk" value="move">' . h(t('Move')) . '</button></span></div></form>';
}

/** Read FILES.BBS or DESCRIPT.ION in a folder: filename => description. */
function a_read_desc_file(string $dir): array
{
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if (!in_array(strtolower($f), ['files.bbs', 'descript.ion'], true)) {
            continue;
        }
        $last = null;
        foreach (preg_split("/\r\n|\n|\r/", CP437::toUtf8((string)file_get_contents("$dir/$f"))) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s+[|+]?\s*(.*)$/u', $line, $m) && $last !== null) {
                $out[$last] .= "\n" . trim($m[1]);
                continue;
            }
            if (preg_match('/^"?([^\s"]+)"?\s+(.*)$/u', $line, $m)) {
                $last = strtolower($m[1]);
                $out[$last] = trim(preg_replace('/^\[\s*\d+\]\s*/', '', $m[2]) ?? '');
            }
        }
    }
    return $out;
}

function a_import_dirs(): array
{
    $base = CB_DATA . '/import';
    $dirs = [];
    if (!is_dir($base)) {
        return [];
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $all = [$base];
    foreach ($it as $p) {
        if ($p->isDir()) {
            $all[] = $p->getPathname();
        }
    }
    foreach ($all as $d) {
        $n = count(a_import_files($d));
        if ($n > 0) {
            $rel = ltrim(substr($d, strlen($base)), '/\\');
            $dirs[$rel === '' ? '.' : $rel] = $n;
        }
    }
    ksort($dirs);
    return $dirs;
}

function a_import_files(string $dir): array
{
    $skip = ['files.bbs', 'descript.ion', 'index.html', 'index.htm', '.htaccess', 'thumbs.db'];
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f[0] === '.' || in_array(strtolower($f), $skip, true) || !is_file("$dir/$f")) {
            continue;
        }
        $out[] = $f;
    }
    return $out;
}

function page_import(array $admin): void
{
    $names = a_farea_names();
    $base = CB_DATA . '/import';
    if (a_post()) {
        @set_time_limit(600);
        $move = isset($_POST['move']);
        $total = 0;
        $errors = 0;
        foreach ((array)($_POST['map'] ?? []) as $rel => $area) {
            $area = (int)$area;
            if ($area <= 0 || !isset($names[$area])) {
                continue;
            }
            $rel = (string)$rel;
            $dir = realpath($rel === '.' ? $base : $base . '/' . $rel);
            $rb = realpath($base);
            if (!$dir || !$rb || ($dir !== $rb && !str_starts_with($dir, $rb . DIRECTORY_SEPARATOR))) {
                continue;
            }
            $desc = a_read_desc_file($dir);
            foreach (a_import_files($dir) as $f) {
                $d = $desc[strtolower($f)] ?? '';
                if ($d === '') {
                    $d = a_diz("$dir/$f");
                }
                $err = a_store_file($area, "$dir/$f", $f, $d, (int)$admin['id'], $admin['handle'], $move);
                if ($err) {
                    $errors++;
                } else {
                    $total++;
                }
            }
        }
        cb_log((int)$admin['id'], $admin['handle'], "Imported $total files");
        a_flash(t('{1} file(s) imported, {2} skipped (duplicates or errors).', $total, $errors), $errors ? 'warn' : 'ok');
        a_go('import');
    }
    echo '<h1>' . h(t('File import')) . '</h1>';
    echo '<p class="note">' . h(t('Copy files by FTP into data/import, one folder per file area if you like. Descriptions are taken from FILES.BBS or DESCRIPT.ION in the folder, otherwise from FILE_ID.DIZ inside ZIP files.')) . '</p>';
    $dirs = a_import_dirs();
    if (!$dirs) {
        echo '<div class="panel"><p>' . h(t('data/import is empty.')) . '</p></div>';
        return;
    }
    echo '<form method="post" class="form">' . a_csrf() . '<div class="tablewrap"><table><tr><th>' . h(t('Folder')) . '</th><th class="num">' . h(t('Files')) .
        '</th><th>' . h(t('Import into')) . '</th></tr>';
    foreach ($dirs as $rel => $n) {
        echo '<tr><td><code>' . h((string)$rel) . '</code></td><td class="num">' . (int)$n . '</td><td><select name="map[' . h((string)$rel) . ']"><option value="0">' .
            h(t('skip')) . '</option>';
        foreach ($names as $id => $name) {
            echo '<option value="' . $id . '">' . h($name) . '</option>';
        }
        echo '</select></td></tr>';
    }
    echo '</table></div><label class="inline"><input type="checkbox" name="move" checked> ' . h(t('Move files (saves space). Without this they are copied.')) . '</label>' .
        '<p><button class="btn" type="submit">' . h(t('Start import')) . '</button></p></form>';
}
