<?php
// seed.php: test data in the instance CB_ROOT (works on 1.11.0 and 1.12.0, only old columns).
// Areas: 1 Allgemein (read 10), 2 Technik (read 10), 3 Sysops (read 100). Users Regina (10), Peter (20),
// Paula (pending), messages with replies, private mail, pipe codes and HTML in a body.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
q('DELETE FROM {messages}');
q('DELETE FROM {msg_areas}');
foreach ([[1, 'Allgemein', 'Plaudern über alles', 10, 10, 10], [2, 'Technik', 'Computer, Modems, Software', 10, 10, 20], [3, 'Sysops', 'Nur für Sysops', 100, 100, 30]] as $a) {
    q('INSERT INTO {msg_areas} (id, name, description, read_level, write_level, sort) VALUES (?,?,?,?,?,?)', $a);
}
$regina = mkuser('Regina', 'regina12', 10);
$peter = mkuser('Peter', 'peter123', 20);
$paula = mkuser('Paula', 'paula123', 10);
q('UPDATE {users} SET pending=1 WHERE id=?', [$paula]);
$box = val("SELECT value FROM {settings} WHERE name='box_id'");
$n = 0;
function msg(int $area, int $from, string $fromName, string $subj, string $body, int $reply = 0, int $private = 0, int $to = 0, string $toName = 'Alle'): int
{
    global $box, $n;
    $n++;
    q('INSERT INTO {messages} (area_id, from_id, from_name, to_id, to_name, subject, body, posted, reply_to, private, is_read, msgid, origin, reply_msgid)
        VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?)', [$area, $from, $fromName, $to, $toName, $subj, $body, time() - 86400 * 40 + $n * 3600, $reply, $private,
        $box . '.' . bin2hex(random_bytes(8)), $box, '']);
    return (int)tdb()->lastInsertId();
}
$first = msg(1, $regina, 'Regina', 'Hallo zusammen', "Hallo an alle!\nIch bin neu hier und freue mich.\n\n-- \nRegina aus Testhausen");
msg(1, $peter, 'Peter', 'Re: Hallo zusammen', "Regina schrieb:\nRE> Hallo an alle!\n\nWillkommen, Regina!", $first, 0, $regina, 'Regina');
msg(1, $peter, 'Peter', 'Farben |14gelb|07 und <b>fett</b>', "Pipe |12rot|07 und <script>alert(1)</script> und &amp; bleiben Text.\n" . str_repeat('x', 100));
for ($i = 1; $i <= 64; $i++) {
    msg(2, $i % 2 ? $regina : $peter, $i % 2 ? 'Regina' : 'Peter', "Technikfrage $i", "Frage Nummer $i zum Modem.\nZeile zwei.");
}
msg(3, 1, 'Sysop', 'Geheim', 'Nur für Sysops.');
msg(0, $peter, 'Peter', 'Private Post', 'Psst, privat.', 0, 1, $regina, 'Regina');
echo "seeded: ", val('SELECT COUNT(*) FROM {messages}'), " messages\n";
