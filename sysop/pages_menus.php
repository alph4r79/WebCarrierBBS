<?php
/**
 * Sysop backend: menu editor and screen editor (with preview in the real terminal renderer).
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

function a_command_help(): array
{
    return [
        'MENU' => t('Go to another menu. Data: menu name'),
        'SCREEN' => t('Show a screen. Data: screen name'),
        'LEGAL' => t('Imprint or privacy policy. Data: impressum or privacy'),
        'MSG_AREA' => t('Choose a message area'),
        'MSG_READ' => t('Read messages in the current area'),
        'MSG_NEW' => t('Read new messages in all areas'),
        'MSG_POST' => t('Write a message in the current area'),
        'MSG_MAIL' => t('Read private mail'),
        'MSG_SEND' => t('Send private mail'),
        'FILE_AREA' => t('Choose a file area'),
        'FILE_LIST' => t('List files of the current area'),
        'FILE_NEW' => t('New files since the last call'),
        'FILE_SEARCH' => t('Search files'),
        'FILE_DOWNLOAD' => t('Download a file'),
        'FILE_UPLOAD' => t('Upload a file'),
        'ONELINERS' => t('Oneliners'),
        'LASTCALLERS' => t('Last callers'),
        'WHO' => t("Who's online"),
        'USERLIST' => t('User list'),
        'USERINFO' => t('Statistics of the caller'),
        'SETTINGS' => t('Personal settings'),
        'PAGE' => t('Page the sysop'),
        'COMMENT' => t('Private message to the sysop'),
        'DOOR' => t('Start a door. Data: door id'),
        'DOORTOP' => t('High score lists of the doors'),
        'SYSOP' => t('Sysop menu in the terminal (only for users with sysop level)'),
        'LOGOFF' => t('Log off'),
    ];
}

function page_menus(array $admin): void
{
    if (a_post()) {
        if (isset($_POST['del'])) {
            $m = DB::row('SELECT * FROM {menus} WHERE id=?', [(int)$_POST['del']]);
            if ($m && $m['name'] === 'main') {
                a_flash(t('The main menu cannot be deleted.'), 'bad');
            } elseif ($m) {
                DB::q('DELETE FROM {menu_items} WHERE menu_id=?', [(int)$m['id']]);
                DB::q('DELETE FROM {menus} WHERE id=?', [(int)$m['id']]);
                a_flash(t('Menu deleted.'));
            }
            a_go('menus');
        }
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', a_in('name', 20)) ?? '');
        if ($name === '' || DB::val('SELECT COUNT(*) FROM {menus} WHERE name=?', [$name])) {
            a_flash(t('Please choose a new, unique menu name (letters, digits, dash, underscore).'), 'bad');
            a_go('menus');
        }
        $id = DB::insert('menus', ['name' => $name, 'title' => a_in('title', 60) ?: $name, 'screen' => '', 'min_level' => 0]);
        DB::insert('menu_items', ['menu_id' => $id, 'hotkey' => 'Q', 'label' => Lang::$code === 'de' ? 'Hauptmenü' : 'Main menu',
            'command' => 'MENU', 'data' => 'main', 'min_level' => 0, 'sort' => 100]);
        a_go('menu', ['id' => $id]);
    }
    echo '<h1>' . h(t('Menus')) . '</h1>';
    echo '<p class="note">' . h(t('Every menu is a list of hotkeys. Callers start in the menu called main. A menu either shows its own ANSI screen or is drawn automatically from its items.')) . '</p>';
    echo '<div class="tablewrap"><table><tr><th>' . h(t('Name')) . '</th><th>' . h(t('Title')) . '</th><th>' . h(t('Screen')) . '</th><th class="num">' . h(t('Items')) . '</th><th></th></tr>';
    foreach (DB::all('SELECT * FROM {menus} ORDER BY name') as $m) {
        $n = (int)DB::val('SELECT COUNT(*) FROM {menu_items} WHERE menu_id=?', [(int)$m['id']]);
        echo '<tr><td><a href="' . h(a_url('menu', ['id' => $m['id']])) . '"><code>' . h($m['name']) . '</code></a></td><td>' . h($m['title']) . '</td><td>' .
            h($m['screen'] ?: t('automatic')) . '</td><td class="num">' . $n . '</td><td class="row-actions"><a class="btn small ghost" href="' .
            h(a_url('menu', ['id' => $m['id']])) . '">' . h(t('Edit')) . '</a>';
        if ($m['name'] !== 'main') {
            echo '<form method="post"' . a_confirm(t('Delete menu {1}?', $m['name'])) . '>' . a_csrf() . '<button class="btn small danger ghost" name="del" value="' .
                (int)$m['id'] . '">' . h(t('Delete')) . '</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
    echo '<form method="post" class="form panel">' . a_csrf() . '<h2>' . h(t('New menu')) . '</h2><div class="grid2"><label>' . h(t('Name (used in MENU commands)')) .
        '<input name="name" maxlength="20" class="mono"></label><label>' . h(t('Title')) . '<input name="title" maxlength="60"></label></div><button class="btn" type="submit">' .
        h(t('Create menu')) . '</button></form>';
}

function page_menu(array $admin): void
{
    $id = (int)($_GET['id'] ?? 0);
    $m = DB::row('SELECT * FROM {menus} WHERE id=?', [$id]);
    if (!$m) {
        a_go('menus');
    }
    $cmds = a_command_help();
    if (a_post()) {
        if (isset($_POST['delitem'])) {
            DB::q('DELETE FROM {menu_items} WHERE id=? AND menu_id=?', [(int)$_POST['delitem'], $id]);
            a_flash(t('Item deleted.'));
            a_go('menu', ['id' => $id]);
        }
        DB::update('menus', ['title' => a_in('title', 60), 'screen' => strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', a_in('screen', 40)) ?? ''),
            'min_level' => a_int('min_level', 0, 255)], 'id=?', [$id]);
        $rows = (array)($_POST['it'] ?? []);
        if (trim((string)($_POST['new']['hotkey'] ?? '')) !== '') {
            $rows['new'] = $_POST['new'];
        }
        foreach ($rows as $iid => $r) {
            $key = mb_strtoupper(mb_substr(trim((string)($r['hotkey'] ?? '')), 0, 1));
            $cmd = strtoupper((string)($r['command'] ?? ''));
            if ($key === '' || !isset($cmds[$cmd]) || $key === '?') {
                continue;
            }
            $data = [
                'hotkey' => $key, 'label' => mb_substr(trim((string)($r['label'] ?? '')), 0, 40), 'command' => $cmd,
                'data' => mb_substr(trim((string)($r['data'] ?? '')), 0, 60), 'min_level' => max(0, min(255, (int)($r['min_level'] ?? 0))),
                'sort' => (int)($r['sort'] ?? 0),
            ];
            if ($iid === 'new') {
                $data['menu_id'] = $id;
                if ($data['sort'] === 0) {
                    $data['sort'] = (int)DB::val('SELECT COALESCE(MAX(sort),0)+10 FROM {menu_items} WHERE menu_id=?', [$id]);
                }
                DB::insert('menu_items', $data);
            } else {
                DB::update('menu_items', $data, 'id=? AND menu_id=?', [(int)$iid, $id]);
            }
        }
        a_flash(t('Menu saved.'));
        a_go('menu', ['id' => $id]);
    }
    $sel = static function (string $name, string $cur) use ($cmds): string {
        $h = '<select name="' . h($name) . '">';
        foreach ($cmds as $c => $desc) {
            $h .= '<option value="' . $c . '"' . ($c === $cur ? ' selected' : '') . ' title="' . h($desc) . '">' . $c . '</option>';
        }
        return $h . '</select>';
    };
    echo '<h1>' . h(t('Menu {1}', $m['name'])) . '</h1><form method="post" class="form">' . a_csrf();
    echo '<section class="panel"><div class="grid2"><label>' . h(t('Title')) . '<input name="title" value="' . h($m['title']) . '"></label>' .
        '<label>' . h(t('Own ANSI screen (empty = draw automatically)')) . '<input name="screen" class="mono" value="' . h($m['screen']) . '"></label>' .
        '<label>' . h(t('Minimum level to enter')) . '<input type="number" name="min_level" value="' . (int)$m['min_level'] . '"></label></div></section>';
    echo '<div class="tablewrap"><table><tr><th>' . h(t('Key')) . '</th><th>' . h(t('Label')) . '</th><th>' . h(t('Command')) . '</th><th>' . h(t('Data')) .
        '</th><th>' . h(t('Min. level')) . '</th><th>' . h(t('Sort')) . '</th><th></th></tr>';
    foreach (DB::all('SELECT * FROM {menu_items} WHERE menu_id=? ORDER BY sort, id', [$id]) as $it) {
        $iid = (int)$it['id'];
        echo "<tr><td><input class=\"key\" name=\"it[$iid][hotkey]\" value=\"" . h($it['hotkey']) . "\" maxlength=\"1\"></td>" .
            "<td><input name=\"it[$iid][label]\" value=\"" . h($it['label']) . "\"></td><td>" . $sel("it[$iid][command]", (string)$it['command']) . '</td>' .
            "<td><input name=\"it[$iid][data]\" value=\"" . h($it['data']) . "\" class=\"mono\"></td>" .
            "<td><input type=\"number\" name=\"it[$iid][min_level]\" value=\"" . (int)$it['min_level'] . "\" style=\"width:5em\"></td>" .
            "<td><input type=\"number\" name=\"it[$iid][sort]\" value=\"" . (int)$it['sort'] . "\" style=\"width:5em\"></td>" .
            '<td><button class="btn small danger ghost" name="delitem" value="' . $iid . '">' . h(t('Delete')) . '</button></td></tr>';
    }
    echo '<tr><td><input class="key" name="new[hotkey]" maxlength="1" placeholder="+"></td><td><input name="new[label]" placeholder="' . h(t('New item')) . '"></td><td>' .
        $sel('new[command]', 'SCREEN') . '</td><td><input name="new[data]" class="mono"></td><td><input type="number" name="new[min_level]" value="0" style="width:5em"></td>' .
        '<td><input type="number" name="new[sort]" value="0" style="width:5em"></td><td></td></tr></table></div>';
    echo '<p class="row-actions"><button class="btn" type="submit">' . h(t('Save menu')) . '</button> <a class="btn ghost" href="' . h(a_url('menus')) . '">' . h(t('Back')) . '</a></p></form>';
    echo '<section class="panel"><h2>' . h(t('Commands')) . '</h2><div class="tablewrap" style="border:0"><table>';
    foreach ($cmds as $c => $desc) {
        echo '<tr><td><code>' . $c . '</code></td><td>' . h($desc) . '</td></tr>';
    }
    echo '</table></div><p class="note">' . h(t('The key ? is reserved: it shows the menu again, which matters in expert mode.')) . '</p></section>';
}

/* ------------------------------------------------------------------ screens */

function a_screen_list(): array
{
    $out = [];
    foreach (['ans', 'txt'] as $ext) {
        foreach (glob(CB_DATA . '/screens/*.' . $ext) ?: [] as $f) {
            $out[basename($f)] = $f;
        }
    }
    ksort($out);
    return $out;
}

function a_screen_name(string $n): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $n) ?? '');
}

function page_screens(array $admin): void
{
    if (a_post()) {
        if (isset($_POST['del'])) {
            $f = basename((string)$_POST['del']);
            $list = a_screen_list();
            if (isset($list[$f])) {
                @unlink($list[$f]);
                a_flash(t('Screen deleted.'));
            }
            a_go('screens');
        }
        if (isset($_POST['create'])) {
            $n = a_screen_name(a_in('name', 40));
            if ($n === '' || cb_screen_path($n)) {
                a_flash(t('Please choose a new, unique screen name.'), 'bad');
                a_go('screens');
            }
            file_put_contents(CB_DATA . "/screens/$n.txt", "|15@BBSNAME@|07\n\n");
            a_go('screen', ['n' => "$n.txt"]);
        }
        $up = $_FILES['ans'] ?? null;
        if ($up && ($up['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($up['tmp_name'])) {
            $base = pathinfo((string)$up['name'], PATHINFO_FILENAME);
            $ext = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION));
            $n = a_screen_name(a_in('upname', 40) ?: $base);
            if ($n === '' || !in_array($ext, ['ans', 'asc', 'txt'], true) || (int)$up['size'] > 512 * 1024) {
                a_flash(t('Please upload an .ans or .txt file up to 512 KB.'), 'bad');
                a_go('screens');
            }
            foreach (['ans', 'txt'] as $e) {
                @unlink(CB_DATA . "/screens/$n.$e");
            }
            move_uploaded_file($up['tmp_name'], CB_DATA . "/screens/$n." . ($ext === 'txt' ? 'txt' : 'ans'));
            a_flash(t('Screen {1} saved.', $n));
            a_go('screen', ['n' => $n . '.' . ($ext === 'txt' ? 'txt' : 'ans')]);
        }
        a_go('screens');
    }
    echo '<h1>' . h(t('Screens')) . '</h1>';
    echo '<p class="note">' . h(t('Screens are ANSI files (.ans, CP437, draw them with PabloDraw or Moebius) or text files with pipe colour codes (.txt). Special names: welcome (before login), newuser, logon (after login), logoff. Bulletins and menu screens can have any name.')) . '</p>';
    echo '<div class="tablewrap"><table><tr><th>' . h(t('Screen')) . '</th><th>' . h(t('Type')) . '</th><th class="num">' . h(t('Size')) . '</th><th></th></tr>';
    foreach (a_screen_list() as $f => $path) {
        echo '<tr><td><a href="' . h(a_url('screen', ['n' => $f])) . '"><code>' . h(pathinfo($f, PATHINFO_FILENAME)) . '</code></a></td><td>' .
            (str_ends_with($f, '.ans') ? 'ANSI' : t('Text with pipe codes')) . '</td><td class="num">' . number_format((int)filesize($path)) .
            ' B</td><td class="row-actions"><a class="btn small ghost" href="' . h(a_url('screen', ['n' => $f])) . '">' . h(t('Show')) . '</a><form method="post"' .
            a_confirm(t('Delete screen {1}?', $f)) . '>' . a_csrf() . '<button class="btn small danger ghost" name="del" value="' . h($f) . '">' . h(t('Delete')) . '</button></form></td></tr>';
    }
    echo '</table></div>';
    echo '<div class="grid2"><form method="post" enctype="multipart/form-data" class="form panel">' . a_csrf() . '<h2>' . h(t('Upload a screen')) . '</h2>' .
        '<label>' . h(t('File (.ans or .txt)')) . '<input type="file" name="ans" accept=".ans,.asc,.txt"></label><label>' . h(t('Name (empty = file name)')) .
        '<input name="upname" class="mono"></label><button class="btn" type="submit">' . h(t('Upload')) . '</button></form>';
    echo '<form method="post" class="form panel">' . a_csrf() . '<h2>' . h(t('New text screen')) . '</h2><label>' . h(t('Name')) .
        '<input name="name" class="mono"></label><button class="btn" name="create" value="1">' . h(t('Create')) . '</button></form></div>';
}

function page_screen(array $admin): void
{
    $f = basename((string)($_GET['n'] ?? ''));
    $list = a_screen_list();
    if (!isset($list[$f])) {
        a_go('screens');
    }
    $path = $list[$f];
    $isTxt = str_ends_with($f, '.txt');
    if (a_post() && $isTxt) {
        $text = str_replace("\r\n", "\n", (string)($_POST['text'] ?? ''));
        file_put_contents($path, mb_substr($text, 0, 200000));
        a_flash(t('Screen saved.'));
        a_go('screen', ['n' => $f]);
    }
    $raw = (string)file_get_contents($path);
    if ($isTxt) {
        $w = new AnsiWriter();
        $w->write('|07|16');
        $w->write(str_replace("\n", '|CR', str_replace(["\r\n", "\r"], "\n", $raw)));
        $bytes = $w->buf;
    } else {
        $bytes = cb_strip_sauce($raw);
    }
    $bytes = "\e[0m\e[2J\e[H" . $bytes;
    echo '<h1>' . h(t('Screen {1}', pathinfo($f, PATHINFO_FILENAME))) . '</h1>';
    echo '<p class="note">' . h(t('Preview with the real terminal. Macros like @BBSNAME@ are filled in when a caller sees the screen.')) . '</p>';
    echo '<div class="preview"><canvas id="pv"></canvas></div>';
    if ($isTxt) {
        echo '<form method="post" class="form panel">' . a_csrf() . '<label>' . h(t('Text with pipe codes')) . '<textarea name="text" class="mono" style="min-height:360px">' .
            h($raw) . '</textarea></label><p class="note">' .
            h(t('|00 to |15 set the text colour, |16 to |23 the background, |CL clears the screen. Macros: @BBSNAME@ @SYSOP@ @BBSLOC@ @USER@ @USERLOC@ @CALLS@ @LASTCALL@ @TIMELEFT@ @LEVEL@ @LEVELNAME@ @NODE@ @NODES@ @DATE@ @TIME@ @USERS@ @MSGS@ @FILES@ @TOTALCALLS@ @VERSION@. Width and alignment: @BBSNAME:40C@ (L, R or C).')) .
            '</p><button class="btn" type="submit">' . h(t('Save screen')) . '</button></form>';
    } else {
        echo '<p class="note">' . h(t('ANSI screens are edited with an ANSI editor and uploaded again under the same name.')) . '</p>';
    }
    echo '<p><a class="btn ghost" href="' . h(a_url('screens')) . '">' . h(t('Back')) . '</a></p>';
    echo '<script src="../assets/font.js?v=' . CB_VERSION . '"></script><script src="../assets/terminal.js?v=' . CB_VERSION . '"></script>';
    echo '<script>(function(){var t=new CarrierTerm(document.getElementById("pv"));t.setBaud(0);t.write(' .
        json_encode(CP437::transport($bytes), JSON_HEX_TAG | JSON_HEX_AMP) . ');}());</script>';
}
