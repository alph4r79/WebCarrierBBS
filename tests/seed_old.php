<?php
// Data in a 1.10.0 installation before the update: users, Hi-Lo values, files, a node.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
$root = getenv('CB_ROOT');
ok((int)val("SELECT value FROM {settings} WHERE name='db_version'") === 9, 'old installation has DB version 9');
$a = mkuser('Alt1', 'alt12345', 10);
$b = mkuser('Alt2', 'alt12345', 10);
$c = mkuser('Alt3', 'alt12345', 10);
// Alt1: only door_data; Alt2: list has a worse value (5) than door_data (3); Alt3: list better (2) than door_data (4)
q("INSERT INTO {door_data} (door, user_id, k, v) VALUES ('hilo',?,'best','4')", [$a]);
q("INSERT INTO {door_data} (door, user_id, k, v) VALUES ('hilo',?,'best','3')", [$b]);
q("INSERT INTO {door_data} (door, user_id, k, v) VALUES ('hilo',?,'best','4')", [$c]);
q("INSERT INTO {door_scores} (door, user_id, value, label, time) VALUES ('hilo',?,5,'5 Versuche',?)", [$b, time() - 500]);
q("INSERT INTO {door_scores} (door, user_id, value, label, time) VALUES ('hilo',?,2,'2 Versuche',?)", [$c, time() - 500]);
$area = (int)val('SELECT MIN(id) FROM {file_areas}');
@mkdir("$root/data/files/$area", 0775, true);
for ($i = 0; $i < 3; $i++) {
    file_put_contents("$root/data/files/$area/alt$i.txt", "alte Datei $i");
    q("INSERT INTO {files} (area_id, filename, size, description, uploader_id, uploader, added, approved, storage) VALUES (?,?,?,?,0,'',?,1,?)",
        [$area, "alt$i.txt", 12, 'x', time(), "$area/alt$i.txt"]);
}
summary();
