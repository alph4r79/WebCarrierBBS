<?php
// A message longer than one screen, for the F5 test in the reader.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
$area = (int)val('SELECT MIN(id) FROM {msg_areas}');
$body = '';
for ($i = 1; $i <= 45; $i++) { $body .= "Zeile $i einer langen Nachricht über Mailboxen, Modems und Ölkannen.\n"; }
q("INSERT INTO {messages} (area_id, from_id, from_name, to_id, to_name, subject, body, posted, msgid, origin) VALUES (?,1,'Sysop',0,'Alle','Lange Nachricht',?,?,?,?)",
  [$area, $body, time(), bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8)), bin2hex(random_bytes(16))]);
echo "seeded\n";
