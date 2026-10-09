<?php
/**
 * Sysop backend: doors. Lists the doors folder, adds doors to the doors menu, removes them
 * and resets their stored data.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

const A_DOORS_URL = 'https://webcarrier-bbs.de/doors';

function a_door_error(array $e): string
{
    [$code, $detail, $line] = $e;
    return match ($code) {
        'php' => t('PHP error in line {1}: {2}', (int)$line, $detail),
        'noarray' => t('The file does not return a registration array.'),
        'fields' => t('The registration has no id or no class.'),
        'id' => t('The id {1} is not allowed, only lower case letters and digits, at most 30 characters.', $detail),
        'class' => t('The class {1} does not exist.', $detail),
        'iface' => t('The class {1} does not extend CarrierDoor.', $detail),
        'dupe' => t('The id is already used by {1}.', $detail),
        default => $code,
    };
}

/** Next free hotkey of a menu: 1-9, then A-Z without Q. */
function a_free_hotkey(int $menuId): ?string
{
    $used = array_map('strtoupper', array_column(DB::all('SELECT hotkey FROM {menu_items} WHERE menu_id=?', [$menuId]), 'hotkey'));
    foreach (array_merge(range('1', '9'), array_diff(range('A', 'Z'), ['Q'])) as $k) {
        if (!in_array((string)$k, $used, true)) {
            return (string)$k;
        }
    }
    return null;
}

/** The menu doors, created like the installer does it if it is missing. */
function a_doors_menu(): array
{
    $m = DB::row("SELECT * FROM {menus} WHERE name='doors'");
    if ($m) {
        return $m;
    }
    $de = Settings::get('language', 'en') === 'de';
    $mid = DB::insert('menus', ['name' => 'doors', 'title' => 'Doors', 'screen' => '', 'min_level' => 0]);
    DB::insert('menu_items', ['menu_id' => $mid, 'hotkey' => 'Q', 'label' => $de ? 'Hauptmenü' : 'Main menu', 'command' => 'MENU',
        'data' => 'main', 'min_level' => 0, 'sort' => 990]);
    $main = DB::row("SELECT * FROM {menus} WHERE name='main'");
    if ($main && !DB::val("SELECT COUNT(*) FROM {menu_items} WHERE menu_id=? AND command='MENU' AND data='doors'", [$main['id']])) {
        $used = array_map('strtoupper', array_column(DB::all('SELECT hotkey FROM {menu_items} WHERE menu_id=?', [$main['id']]), 'hotkey'));
        $key = in_array('D', $used, true) ? a_free_hotkey((int)$main['id']) : 'D';
        if ($key !== null) {
            DB::insert('menu_items', ['menu_id' => $main['id'], 'hotkey' => $key, 'label' => $de ? 'Doors (Spiele)' : 'Doors (games)',
                'command' => 'MENU', 'data' => 'doors', 'min_level' => 0,
                'sort' => (int)DB::val('SELECT MAX(sort) FROM {menu_items} WHERE menu_id=?', [$main['id']]) + 10]);
        }
    }
    return DB::row('SELECT * FROM {menus} WHERE id=?', [$mid]);
}

function page_doors(array $admin): void
{
    $doors = cb_doors();
    if (a_post()) {
        $id = (string)($_POST['add'] ?? $_POST['remove'] ?? $_POST['reset'] ?? '');
        $d = $doors[$id] ?? null;
        if ($d && isset($_POST['add'])) {
            $menu = a_doors_menu();
            $key = a_free_hotkey((int)$menu['id']);
            if ($key === null) {
                a_flash(t('The menu {1} has no free hotkey.', $menu['name']), 'bad');
            } else {
                // same level as the doors already offered, otherwise the level of new users
                $min = DB::val("SELECT MIN(min_level) FROM {menu_items} WHERE menu_id=? AND command='DOOR'", [$menu['id']]);
                $min = $min !== null ? (int)$min : Settings::int('new_level', 10);
                // after the last item that is not a menu link, links like Q move back
                $last = (int)DB::val("SELECT MAX(sort) FROM {menu_items} WHERE menu_id=? AND command<>'MENU'", [$menu['id']]);
                $sort = $last + 10;
                DB::q("UPDATE {menu_items} SET sort=sort+10 WHERE menu_id=? AND command='MENU' AND sort>?", [$menu['id'], $last]);
                DB::insert('menu_items', ['menu_id' => $menu['id'], 'hotkey' => $key, 'label' => mb_substr($d['name'], 0, 40), 'command' => 'DOOR',
                    'data' => $id, 'min_level' => $min, 'sort' => $sort]);
                cb_log((int)$admin['id'], $admin['handle'], 'Added door ' . $id . ' to menu ' . $menu['name'] . ' (' . $key . ')');
                a_flash(t('{1} is in the menu {2} with the key {3}.', $d['name'], $menu['name'], $key));
            }
        } elseif ($d && isset($_POST['remove'])) {
            $n = DB::q("DELETE FROM {menu_items} WHERE command='DOOR' AND data=?", [$id])->rowCount();
            cb_log((int)$admin['id'], $admin['handle'], 'Removed door ' . $id . ' from ' . $n . ' menu item(s)');
            a_flash(t('{1} removed from the menus.', $d['name']));
        } elseif ($d && isset($_POST['reset'])) {
            DB::q('DELETE FROM {door_data} WHERE door=?', [$id]);
            cb_log((int)$admin['id'], $admin['handle'], 'Reset the data of door ' . $id);
            a_flash(t('Scores and stored data of {1} reset.', $d['name']));
        }
        a_go('doors');
    }

    echo '<h1>' . h(t('Doors')) . '</h1>';
    echo '<p class="note">' . h(t('Doors are small programs in the folder doors. Upload a door file there, then it shows up in this list. Doors run as PHP code of the board, so only install doors from sources you trust.')) .
        ' <a href="' . h(A_DOORS_URL) . '" target="_blank" rel="noopener">' . h(t('Doors on webcarrier-bbs.de')) . '</a></p>';
    if (!$doors) {
        echo '<div class="panel"><p class="note">' . h(t('There is no door in the folder doors.')) . '</p></div>';
    } else {
        $items = DB::all("SELECT i.data, i.hotkey, m.name, m.id FROM {menu_items} i JOIN {menus} m ON m.id=i.menu_id WHERE i.command='DOOR' ORDER BY m.name, i.hotkey");
        echo '<div class="tablewrap"><table class="doors"><tr><th>' . h(t('Door')) . '</th><th>' . h(t('Version')) . '</th><th>' . h(t('Menu')) . '</th><th>' .
            h(t('Actions')) . '</th></tr>';
        foreach ($doors as $id => $d) {
            $in = array_filter($items, static fn($i) => $i['data'] === $id);
            $menu = $in ? implode(', ', array_map(static fn($i) => '<a href="' . h(a_url('menu', ['id' => $i['id']])) . '">' . h($i['name']) . '</a> (' .
                h($i['hotkey']) . ')', $in)) : '<span class="tag">' . h(t('not offered')) . '</span>';
            echo '<tr><td><strong>' . h($d['name']) . '</strong> <code>' . h($id) . '</code>' .
                ($d['description'] !== '' ? '<br><span class="note">' . h($d['description']) . '</span>' : '') . '</td><td data-label="' . h(t('Version')) . '">' . h($d['version'] ?: '-') .
                '</td><td data-label="' . h(t('Menu')) . '">' . $menu . '</td><td><form method="post" class="row-actions">' . a_csrf() .
                ($in ? '<button class="btn small ghost" name="remove" value="' . h($id) . '">' . h(t('Remove from the menu')) . '</button>'
                    : '<button class="btn small" name="add" value="' . h($id) . '">' . h(t('Add to the menu')) . '</button>') .
                '<button class="btn small danger ghost" name="reset" value="' . h($id) . '" onclick="return confirm(' .
                h((string)json_encode(t('Reset all scores and stored data of {1}? This cannot be undone.', $d['name']), JSON_UNESCAPED_UNICODE)) . ')">' .
                h(t('Reset scores')) . '</button></form></td></tr>';
        }
        echo '</table></div>';
        echo '<p class="note">' . h(t('Add to the menu puts the door into the menu doors with the next free key. Name, key and level can be changed in the menu editor.')) .
            ' <a href="' . h(a_url('menus')) . '">' . h(t('Menus')) . '</a></p>';
    }

    $errors = cb_door_registry()['errors'];
    if ($errors) {
        echo '<h2>' . h(t('Faulty files')) . '</h2><p class="note">' . h(t('These files in the folder doors could not be loaded. Callers do not see them.')) . '</p>';
        echo '<div class="tablewrap"><table><tr><th>' . h(t('File')) . '</th><th>' . h(t('Reason')) . '</th></tr>';
        foreach ($errors as $f => $e) {
            echo '<tr><td><code>' . h((string)$f) . '</code></td><td>' . h(a_door_error($e)) . '</td></tr>';
        }
        echo '</table></div>';
    }
}
