<?php
/**
 * Sysop backend: message areas and moderation of public messages.
 * Private mail is intentionally not listed here.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

function page_msgareas(array $admin): void
{
    if (a_post()) {
        if (isset($_POST['del'])) {
            $id = (int)$_POST['del'];
            DB::q('DELETE FROM {messages} WHERE area_id=? AND private=0', [$id]);
            DB::q('DELETE FROM {lastread} WHERE area_id=?', [$id]);
            DB::q('DELETE FROM {msg_areas} WHERE id=?', [$id]);
            a_flash(t('Area and its messages deleted.'));
            a_go('msgareas');
        }
        foreach ((array)($_POST['a'] ?? []) as $id => $r) {
            $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 60);
            if ($name === '') {
                continue;
            }
            DB::update('msg_areas', [
                'name' => $name, 'description' => mb_substr(trim((string)($r['description'] ?? '')), 0, 120),
                'read_level' => max(0, min(255, (int)($r['read_level'] ?? 10))), 'write_level' => max(0, min(255, (int)($r['write_level'] ?? 10))),
                'sort' => (int)($r['sort'] ?? 0),
            ], 'id=?', [(int)$id]);
        }
        $n = a_in('new_name', 60);
        if ($n !== '') {
            DB::insert('msg_areas', ['name' => $n, 'description' => a_in('new_description', 120),
                'read_level' => a_int('new_read', 0, 255), 'write_level' => a_int('new_write', 0, 255),
                'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+10 FROM {msg_areas}')]);
        }
        a_flash(t('Message areas saved.'));
        a_go('msgareas');
    }
    echo '<h1>' . h(t('Message areas')) . '</h1>';
    echo '<p class="note">' . h(t('Callers see areas whose read level is not above their own level. The list is sorted by the sort number.')) . '</p>';
    echo '<form method="post" class="form">' . a_csrf() . '<div class="tablewrap"><table><tr><th>' . h(t('Sort')) . '</th><th>' . h(t('Name')) . '</th><th>' .
        h(t('Description')) . '</th><th>' . h(t('Read level')) . '</th><th>' . h(t('Write level')) . '</th><th class="num">' . h(t('Messages')) . '</th><th></th></tr>';
    foreach (DB::all('SELECT * FROM {msg_areas} ORDER BY sort, id') as $a) {
        $id = (int)$a['id'];
        $n = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE area_id=? AND private=0', [$id]);
        echo "<tr><td><input type=\"number\" name=\"a[$id][sort]\" value=\"" . (int)$a['sort'] . "\" style=\"width:5em\"></td>" .
            "<td><input name=\"a[$id][name]\" value=\"" . h($a['name']) . "\"></td><td><input name=\"a[$id][description]\" value=\"" . h($a['description']) . "\"></td>" .
            "<td><input type=\"number\" name=\"a[$id][read_level]\" value=\"" . (int)$a['read_level'] . "\" style=\"width:5em\"></td>" .
            "<td><input type=\"number\" name=\"a[$id][write_level]\" value=\"" . (int)$a['write_level'] . "\" style=\"width:5em\"></td>" .
            '<td class="num"><a href="' . h(a_url('messages', ['area' => $id])) . '">' . $n . '</a></td>' .
            '<td><button class="btn small danger ghost" name="del" value="' . $id . '" onclick="return confirm(' .
            h(json_encode(t('Delete this area with all {1} messages?', $n))) . ')">' . h(t('Delete')) . '</button></td></tr>';
    }
    echo '<tr><td></td><td><input name="new_name" placeholder="' . h(t('New area')) . '"></td><td><input name="new_description" placeholder="' . h(t('Description')) . '"></td>' .
        '<td><input type="number" name="new_read" value="10" style="width:5em"></td><td><input type="number" name="new_write" value="10" style="width:5em"></td><td></td><td></td></tr>';
    echo '</table></div><p><button class="btn" type="submit">' . h(t('Save areas')) . '</button></p></form>';
}

/** Select with all message areas except $skip, for moving. */
function a_marea_move_select(array $names, int $skip): string
{
    $h = '<select name="move_to" aria-label="' . h(t('Move to')) . '"><option value="0">' . h(t('Move to')) . '</option>';
    foreach ($names as $id => $n) {
        if ($id !== $skip) {
            $h .= '<option value="' . $id . '">' . h($n) . '</option>';
        }
    }
    return $h . '</select>';
}

function page_messages(array $admin): void
{
    $area = (int)($_GET['area'] ?? 0);
    $view = (int)($_GET['view'] ?? 0);
    $names = [];
    foreach (DB::all('SELECT id, name FROM {msg_areas} ORDER BY sort, id') as $a) {
        $names[(int)$a['id']] = $a['name'];
    }
    if (a_post() && isset($_POST['bulk'])) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        $op = (string)$_POST['bulk'];
        $target = a_int('move_to');
        $back = array_filter(['area' => $area, 'view' => $view]);
        if (!$ids) {
            a_flash(t('No messages selected.'), 'warn');
        } elseif ($op === 'delete') {
            $n = 0;
            foreach ($ids as $mid) {
                $n += DB::q('DELETE FROM {messages} WHERE id=? AND private=0', [$mid])->rowCount();
            }
            cb_log((int)$admin['id'], $admin['handle'], 'Deleted ' . $n . ' message(s)');
            a_flash(t('{1} message(s) deleted.', $n));
            unset($back['view']);
        } elseif ($op === 'move') {
            if (!isset($names[$target])) {
                a_flash(t('Please choose a message area.'), 'bad');
            } else {
                $n = 0;
                foreach ($ids as $mid) {
                    $n += DB::q('UPDATE {messages} SET area_id=? WHERE id=? AND private=0', [$target, $mid])->rowCount();
                }
                cb_log((int)$admin['id'], $admin['handle'], 'Moved ' . $n . ' message(s) to area ' . $target);
                a_flash(t('{1} message(s) moved to {2}.', $n, $names[$target]));
            }
        }
        a_go('messages', $back);
    }

    echo '<h1>' . h(t('Messages')) . '</h1>';
    echo '<form method="get" class="form row-actions"><input type="hidden" name="p" value="messages"><select name="area" style="max-width:320px;margin:0">' .
        '<option value="0">' . h(t('All areas')) . '</option>';
    foreach ($names as $aid => $n) {
        echo '<option value="' . $aid . '"' . ($aid === $area ? ' selected' : '') . '>' . h($n) . '</option>';
    }
    echo '</select><button class="btn" type="submit">' . h(t('Show')) . '</button></form><br>';
    if ($view > 0) {
        $m = DB::row('SELECT * FROM {messages} WHERE id=? AND private=0', [$view]);
        if ($m) {
            echo '<section class="panel"><h2>' . h($m['subject']) . '</h2><p class="note">' . h(t('From {1} to {2}, {3}, area {4}', $m['from_name'], $m['to_name'],
                    date('d.m.Y H:i', (int)$m['posted']), $names[(int)$m['area_id']] ?? '?')) . '</p><pre style="white-space:pre-wrap;font:14px/1.5 var(--mono)">' .
                h($m['body']) . '</pre>' .
                '<form method="post" action="' . h(a_url('messages', array_filter(['area' => $area, 'view' => $view]))) . '" class="bulk">' . a_csrf() .
                '<input type="hidden" name="ids[]" value="' . (int)$m['id'] . '"><span class="move">' . a_marea_move_select($names, (int)$m['area_id']) .
                '<button class="btn small ghost" name="bulk" value="move">' . h(t('Move')) . '</button></span>' .
                '<button class="btn small danger ghost" name="bulk" value="delete" onclick="return confirm(' . h(json_encode(t('Delete this message?'))) . ')">' .
                h(t('Delete')) . '</button></form></section>';
        }
    }
    $rows = $area ? DB::all('SELECT * FROM {messages} WHERE private=0 AND area_id=? ORDER BY id DESC LIMIT 200', [$area])
        : DB::all('SELECT * FROM {messages} WHERE private=0 ORDER BY id DESC LIMIT 200');
    if (!$rows) {
        echo '<div class="panel"><p>' . h(t('No messages.')) . '</p></div>';
        return;
    }
    $all = 'for(const c of this.form.querySelectorAll(\'input[name="ids[]"]\'))c.checked=this.checked';
    echo '<form method="post" action="' . h(a_url('messages', array_filter(['area' => $area]))) . '">' . a_csrf() .
        '<div class="tablewrap"><table><tr><th class="check"><input type="checkbox" onclick="' . h($all) . '" aria-label="' . h(t('Select all')) . '"></th><th>' .
        h(t('Date')) . '</th><th>' . h(t('Area')) . '</th><th>' . h(t('From')) . '</th><th>' . h(t('To')) . '</th><th>' . h(t('Subject')) . '</th></tr>';
    foreach ($rows as $m) {
        echo '<tr><td class="check"><input type="checkbox" name="ids[]" value="' . (int)$m['id'] . '" aria-label="' . h($m['subject']) . '"></td>' .
            '<td class="num">' . date('d.m.y H:i', (int)$m['posted']) . '</td><td>' . h($names[(int)$m['area_id']] ?? '?') . '</td><td>' . h($m['from_name']) .
            '</td><td>' . h($m['to_name']) . '</td><td><a href="' . h(a_url('messages', array_filter(['area' => $area, 'view' => (int)$m['id']]))) . '">' .
            h($m['subject']) . '</a></td></tr>';
    }
    echo '</table></div><div class="bulk"><span class="note">' . h(t('Selected messages:')) . '</span>' .
        '<button class="btn small danger ghost" name="bulk" value="delete" onclick="return confirm(' . h(json_encode(t('Delete the selected messages?'))) . ')">' .
        h(t('Delete')) . '</button><span class="move">' . a_marea_move_select($names, 0) .
        '<button class="btn small ghost" name="bulk" value="move">' . h(t('Move')) . '</button></span></div></form>';
}
