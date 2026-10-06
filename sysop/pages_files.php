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

function a_safe_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^A-Za-z0-9._\-]/', '_', $name) ?? '';
    return trim($name, '._');
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
    $name = a_safe_name($name);
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

function a_delete_file(array $f): void
{
    $p = CB_DATA . '/files/' . $f['storage'];
    if ($f['storage'] !== '' && is_file($p)) {
        @unlink($p);
    }
    DB::q('DELETE FROM {files} WHERE id=?', [(int)$f['id']]);
}

function page_fileareas(array $admin): void
{
    if (a_post()) {
        if (isset($_POST['del'])) {
            $id = (int)$_POST['del'];
            foreach (DB::all('SELECT * FROM {files} WHERE area_id=?', [$id]) as $f) {
                a_delete_file($f);
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
            if ($f) {
                a_delete_file($f);
                cb_log((int)$admin['id'], $admin['handle'], 'Deleted file ' . $f['filename']);
            }
            a_flash(t('File deleted.'));
        } elseif (isset($_POST['save_desc'])) {
            DB::q('UPDATE {files} SET description=? WHERE id=?', [a_in('desc', 2000), (int)$_POST['save_desc']]);
            a_flash(t('Description saved.'));
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
    if ($area && isset($names[$area])) {
        $edit = (int)($_GET['edit'] ?? 0);
        echo '<div class="tablewrap"><table><tr><th>' . h(t('File')) . '</th><th class="num">' . h(t('Size')) . '</th><th>' . h(t('Date')) . '</th><th class="num">DL</th><th>' .
            h(t('Description')) . '</th><th></th></tr>';
        foreach (DB::all('SELECT * FROM {files} WHERE area_id=? ORDER BY filename', [$area]) as $f) {
            $id = (int)$f['id'];
            echo '<tr' . ((int)$f['approved'] ? '' : ' class="pending"') . '><td>' . h($f['filename']) . '</td><td class="num">' . cb_kb((int)$f['size']) .
                '</td><td class="num">' . date('d.m.y', (int)$f['added']) . '</td><td class="num">' . (int)$f['downloads'] . '</td><td>';
            if ($edit === $id) {
                echo '<form method="post" class="form">' . a_csrf() . '<textarea name="desc" class="mono" style="min-height:120px">' . h($f['description']) .
                    '</textarea><button class="btn small" name="save_desc" value="' . $id . '">' . h(t('Save')) . '</button></form>';
            } else {
                echo nl2br(h($f['description'])) . ' <a href="' . h(a_url('files', ['area' => $area, 'edit' => $id])) . '">' . h(t('Edit')) . '</a>';
            }
            echo '</td><td><form method="post">' . a_csrf() . '<button class="btn small danger ghost" name="del" value="' . $id . '" onclick="return confirm(' .
                h(json_encode(t('Delete this file?'))) . ')">' . h(t('Delete')) . '</button></form></td></tr>';
        }
        echo '</table></div>';
    }
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
