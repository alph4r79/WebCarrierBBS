<?php
/**
 * Sysop backend: message areas. Reading and moderating messages is in pages_mail.php.
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
