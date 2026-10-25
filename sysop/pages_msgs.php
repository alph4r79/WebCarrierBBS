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
        $newLevel = Settings::int('new_level', 10);
        foreach ((array)($_POST['a'] ?? []) as $id => $r) {
            $name = is_array($r) ? mb_substr(trim((string)($r['name'] ?? '')), 0, 60) : '';
            $old = DB::row('SELECT * FROM {msg_areas} WHERE id=?', [(int)$id]);
            if ($name === '' || !$old) {
                continue;
            }
            $read = max(0, min(255, (int)($r['read_level'] ?? 10)));
            // public only while a new user may read the area, a higher read level switches it off
            $want = !empty($r['public']);
            $public = $want && $read <= $newLevel ? 1 : 0;
            if ($want && !$public) {
                a_flash(t('{1} cannot be public on the web: new users may not read it.', $name), 'warn');
            }
            DB::update('msg_areas', [
                'name' => $name, 'description' => mb_substr(trim((string)($r['description'] ?? '')), 0, 120),
                'read_level' => $read, 'write_level' => max(0, min(255, (int)($r['write_level'] ?? 10))),
                'sort' => (int)($r['sort'] ?? 0), 'public' => $public,
            ], 'id=?', [(int)$id]);
            if ((int)$old['public'] !== $public) {
                cb_log((int)$admin['id'], $admin['handle'], 'Area ' . (int)$id . ($public ? ' public on the web'
                    : ' no longer public on the web' . ($want ? ', new users cannot read it' : '')));
            }
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
    $newLevel = Settings::int('new_level', 10);
    echo '<h1>' . h(t('Message areas')) . '</h1>';
    echo '<p class="note">' . h(t('Callers see areas whose read level is not above their own level. The list is sorted by the sort number.')) . '</p>';
    if (cb_has_public_areas()) {
        echo '<p class="note">' . h(t('Public boards on the web:')) . ' <a href="../board.php" target="_blank" rel="noopener">' . h(cb_bbs_url() . 'board.php') . '</a></p>';
    }
    echo '<form method="post" class="form">' . a_csrf() . '<div class="tablewrap"><table class="areas"><tr><th>' . h(t('Sort')) . '</th><th>' . h(t('Name')) . '</th><th>' .
        h(t('Description')) . '</th><th>' . h(t('Read level')) . '</th><th>' . h(t('Write level')) . '</th><th>' . h(t('Web')) . '</th><th class="num">' .
        h(t('Messages')) . '</th><th></th></tr>';
    foreach (DB::all('SELECT * FROM {msg_areas} ORDER BY sort, id') as $a) {
        $id = (int)$a['id'];
        $n = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE area_id=? AND private=0', [$id]);
        $allowed = cb_area_public_allowed($a);
        $public = $allowed && (int)$a['public'] === 1;
        $web = '<label class="inline"><input type="checkbox" name="a[' . $id . '][public]" value="1"' . ($public ? ' checked' : '') . ($allowed ? '' : ' disabled') . '> ' .
            h(t('Readable by anyone on the web')) . '</label>';
        if (!$allowed) {
            $web .= '<span class="hint">' . h(t('Only for areas new users may read (read level {1} or lower).', $newLevel)) . '</span>';
        }
        $web .= '<span class="row-actions"><button type="button" class="btn small ghost" data-copy="' . h(cb_direct_link('area', $id)) . '" data-done="' .
            h(t('Link copied')) . '" data-manual="' . h(t('Copy the link:')) . '">' . h(t('Copy link')) . '</button>' .
            ($public ? '<a href="../board.php?a=' . $id . '" target="_blank" rel="noopener">' . h(t('Web page')) . '</a>' : '') . '</span>';
        echo "<tr><td><input type=\"number\" name=\"a[$id][sort]\" value=\"" . (int)$a['sort'] . "\" style=\"width:5em\"></td>" .
            "<td><input name=\"a[$id][name]\" value=\"" . h($a['name']) . "\"></td><td><input name=\"a[$id][description]\" value=\"" . h($a['description']) . "\"></td>" .
            "<td><input type=\"number\" name=\"a[$id][read_level]\" value=\"" . (int)$a['read_level'] . "\" style=\"width:5em\"></td>" .
            "<td><input type=\"number\" name=\"a[$id][write_level]\" value=\"" . (int)$a['write_level'] . "\" style=\"width:5em\"></td>" .
            '<td class="web">' . $web . '</td>' .
            '<td class="num"><a href="' . h(a_url('messages', ['area' => $id])) . '">' . $n . '</a></td>' .
            '<td><button class="btn small danger ghost" name="del" value="' . $id . '" onclick="return confirm(' .
            h(json_encode(t('Delete this area with all {1} messages?', $n))) . ')">' . h(t('Delete')) . '</button></td></tr>';
    }
    echo '<tr><td></td><td><input name="new_name" placeholder="' . h(t('New area')) . '"></td><td><input name="new_description" placeholder="' . h(t('Description')) . '"></td>' .
        '<td><input type="number" name="new_read" value="10" style="width:5em"></td><td><input type="number" name="new_write" value="10" style="width:5em"></td><td></td><td></td><td></td></tr>';
    echo '</table></div><p class="note">' . h(t('Readable by anyone on the web: the messages of this board appear with handle, date and text as a web page anyone can read without calling.')) .
        ' ' . h(t('Callers can hide their handle there in their settings. Mention the public boards in your privacy policy.')) . '</p>' .
        '<p><button class="btn" type="submit">' . h(t('Save areas')) . '</button></p></form>';
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
