<?php
// Test door in the doors menu and settings for the browser tests.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
setting('baud', '0'); setting('sound', '0'); setting('max_nodes', '6'); setting('idle_minutes', '5');
$m = (int)val("SELECT id FROM {menus} WHERE name='doors'");
if (!val("SELECT COUNT(*) FROM {menu_items} WHERE menu_id=? AND data='waittest'", [$m])) {
  q("INSERT INTO {menu_items} (menu_id, hotkey, label, command, data, min_level, sort) VALUES (?, 'W', 'Wartetest', 'DOOR', 'waittest', 0, 5)", [$m]);
}
q('DELETE FROM {nodes}');
echo "prepared\n";
