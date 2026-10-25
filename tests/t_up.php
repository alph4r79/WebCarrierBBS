<?php
// Upload check, doubles and the BBS ad over the real upload path (upload.php and the engine).
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
$A = getenv('CB_ARCH');
$root = getenv('CB_ROOT');
setting('baud', '0');
setting('upload_ext', 'zip,arj,lzh,rar,7z,lha,txt,gz');
setting('upload_auto_approve', '0');
setting('upload_zip_entries', '2000');
setting('upload_zip_mb', '256');
setting('bbs_ad_on', '0');
setting('bbs_ad_name', '');
setting('bbs_ad_text', '');
q('DELETE FROM {nodes}');
// clean start of the file areas
foreach (q('SELECT * FROM {files}')->fetchAll() as $f) {
    @unlink($root . '/data/files/' . $f['storage']);
}
q('DELETE FROM {files}');
$uid = mkuser('Ulla', 'ulla1234', 10);
$upArea = (int)val('SELECT id FROM {file_areas} WHERE uploads=1 ORDER BY sort LIMIT 1');
$otherArea = (int)val('SELECT id FROM {file_areas} WHERE uploads=0 ORDER BY sort LIMIT 1');

$t = new TC();
$t->login('Ulla', 'ulla1234');

/** Upload a file in the terminal: returns [answer of upload.php, output of the engine]. */
function up(TC $t, string $path, string $as = '', bool $useDiz = true): array
{
    $t->in('F');
    $t->in('U');
    $tmp = $path;
    if ($as !== '') {
        $tmp = sys_get_temp_dir() . '/' . $as;
        copy($path, $tmp);
    }
    $r = $t->req('upload.php', null, ['csrf' => $t->csrf], ['file' => $tmp]);
    $j = json_decode($r['body'], true) ?: [];
    $res = $t->in("\x01");
    $out = plain($res['o']);
    if (($res['ask']['t'] ?? '') === 'hot' && str_contains($out, 'FILE_ID.DIZ')) {
        $res = $t->in($useDiz ? 'J' : 'N');
        $out .= plain($res['o']);
    }
    if (($res['ask']['t'] ?? '') === 'line' && ($res['ask']['m'] ?? 0) === 60) {
        $res = $t->in('Beschreibung des Uploads');
        $out .= plain($res['o']);
    }
    // back to the main menu
    for ($i = 0; $i < 4; $i++) {
        $k = (string)($t->ask['k'] ?? '');
        if (str_contains($k, 'G') && str_contains($k, '?')) {
            break;
        }
        $t->in(($t->ask['t'] ?? '') === 'upload' ? 'Q' : ($k === '' ? "\r" : 'Q'));
    }
    if ($as !== '') {
        @unlink($tmp);
    }
    return [$j, $out];
}

function stored(string $name): ?array
{
    $r = q('SELECT * FROM {files} WHERE filename=?', [$name])->fetch();
    return $r ?: null;
}

$ul0 = (int)val('SELECT ul_files FROM {users} WHERE id=?', [$uid]);

echo "== check of the content\n";
[$j, $o] = up($t, "$A/valid.zip");
ok(($j['ok'] ?? false) === true && stored('valid.zip') !== null, 'valid ZIP accepted');
$f = stored('valid.zip');
ok($f && $f['sha256'] === hash_file('sha256', "$A/valid.zip") && $f['sha256_orig'] === $f['sha256'], 'checksums stored (no ad: both the same)');
ok($f && str_contains((string)$f['description'], 'TESTTOOL'), 'FILE_ID.DIZ read as description');
$cases = [
    ['badcrc.zip', 'beschädigt', 'ZIP with a wrong checksum'],
    ['truncated.zip', 'beschädigt', 'truncated ZIP'],
    ['rarinside.zip', 'passt nicht zu ihrer Endung', '.zip with RAR content'],
    ['fake.rar', 'passt nicht zu ihrer Endung', '.rar with ZIP content'],
    ['text.arj', 'passt nicht zu ihrer Endung', '.arj with text'],
    ['bad.tar.gz', 'passt nicht zu ihrer Endung', '.tar.gz with ZIP content'],
];
foreach ($cases as [$file, $msg, $label]) {
    $files = (int)val('SELECT COUNT(*) FROM {files}');
    $tmpBefore = count(glob($root . '/data/tmp/up_*') ?: []);
    [$j, $o] = up($t, "$A/$file");
    ok(($j['rej'] ?? false) === true && str_contains($o, $msg), "$label refused: " . trim(preg_replace('/\s+/', ' ', mb_substr($o, 0, 70))));
    ok((int)val('SELECT COUNT(*) FROM {files}') === $files && count(glob($root . '/data/tmp/up_*') ?: []) === $tmpBefore, "$label: nothing stored, file deleted");
}
ok((int)val("SELECT COUNT(*) FROM {log} WHERE text LIKE 'Rejected upload badcrc.zip: damaged archive'") >= 1, 'log entry with the reason');
foreach (['good.rar', 'good.arj', 'good.lzh', 'good.7z', 'good.tar.gz', 'notes.txt'] as $file) {
    [$j, $o] = up($t, "$A/$file");
    ok(stored($file) !== null, "$file accepted (signature only)");
}

echo "== limits\n";
setting('upload_zip_entries', '20');
[$j, $o] = up($t, "$A/many.zip");
ok(($j['rej'] ?? false) && str_contains($o, 'zu viele Einträge'), 'ZIP with 30 entries over a limit of 20 refused');
setting('upload_zip_entries', '2000');
setting('upload_zip_mb', '2');
[$j, $o] = up($t, "$A/big.zip");
ok(($j['rej'] ?? false) && str_contains($o, 'zu groß'), 'ZIP that unpacks to 3 MB over a limit of 2 MB refused');
setting('upload_zip_mb', '256');
[$j, $o] = up($t, "$A/big.zip");
ok(stored('big.zip') !== null, 'the same ZIP within the default limits accepted');

echo "== doubles\n";
// a file with the same content approved in another area
q('UPDATE {files} SET approved=1, area_id=? WHERE filename=?', [$otherArea, 'valid.zip']);
$areaName = (string)val('SELECT name FROM {file_areas} WHERE id=?', [$otherArea]);
[$j, $o] = up($t, "$A/valid.zip", 'kopie.zip');
ok(($j['rej'] ?? false) && str_contains($o, 'gibt es schon') && str_contains($o, 'valid.zip') && str_contains($o, $areaName), 'double in another area refused with name and area: ' . trim(preg_replace('/\s+/', ' ', $o)));
foreach (explode("\n", $o) as $l) {
    if (mb_strlen(rtrim($l, "\r")) > 79) {
        ok(false, 'line longer than 79: ' . $l);
    }
}
[$j, $o] = up($t, "$A/valid2.zip");
ok(stored('valid2.zip') !== null && (int)stored('valid2.zip')['approved'] === 0, 'valid2.zip waits for approval');
[$j, $o] = up($t, "$A/valid2.zip", 'nochmal.zip');
ok(($j['rej'] ?? false) && str_contains($o, 'wartet schon auf Freigabe') && !str_contains($o, 'valid2'), 'double of a waiting file: no name, no area');
// area the caller may not see
q('UPDATE {file_areas} SET dl_level=200 WHERE id=?', [$otherArea]);
[$j, $o] = up($t, "$A/valid.zip", 'geheim.zip');
ok(($j['rej'] ?? false) && str_contains($o, 'gibt es in der Box schon') && !str_contains($o, $areaName), 'double in an area the caller cannot see: not named');
q('UPDATE {file_areas} SET dl_level=10 WHERE id=?', [$otherArea]);
$ul1 = (int)val('SELECT ul_files FROM {users} WHERE id=?', [$uid]);
$accepted = (int)val('SELECT COUNT(*) FROM {files} WHERE uploader_id=?', [$uid]);
ok($ul1 - $ul0 === $accepted, "upload count only counts accepted uploads ($accepted)");

echo "== BBS ad\n";
setting('bbs_ad_on', '1');
[$j, $o] = up($t, "$A/valid3.zip");
$f = stored('valid3.zip');
$path = $root . '/data/files/' . $f['storage'];
$z = new ZipArchive();
$z->open($path);
$ad = $z->getFromName('DIEOELKA.TXT');
ok($ad !== false, 'ad DIEOELKA.TXT in the top folder (name from "Die Ölkanne! BBS")');
ok($ad !== false && str_contains($ad, "\r\n") && !preg_match("/[^\r]\n/", $ad), 'ad with CRLF');
ok($ad !== false && str_contains($ad, "Die \x99lkanne! BBS"), 'umlaut of the board name as CP437 byte 0x99');
ok($ad !== false && str_contains($ad, date('d.m.y')) && str_contains($ad, parse_url($GLOBALS['BASE'], PHP_URL_HOST) . ':' . parse_url($GLOBALS['BASE'], PHP_URL_PORT)), 'macros filled in (date, address)');
$lines = explode("\r\n", rtrim((string)$ad));
ok(max(array_map('strlen', $lines)) <= 79, 'no line of the ad longer than 79 (' . max(array_map('strlen', $lines)) . ')');
ok($z->getFromName('FILE_ID.DIZ') !== false && $z->statName('FILE_ID.DIZ')['crc'] === crc32(file_get_contents("$A/valid3.zip") ? (function () use ($A) { $q = new ZipArchive(); $q->open("$A/valid3.zip"); return $q->getFromName('FILE_ID.DIZ'); })() : ''), 'FILE_ID.DIZ unchanged');
$z->close();
ok($f['sha256'] === hash_file('sha256', $path) && $f['sha256_orig'] === hash_file('sha256', "$A/valid3.zip") && $f['sha256'] !== $f['sha256_orig'], 'sha256 with ad, sha256_orig before the ad');
ok((int)$f['size'] === filesize($path), 'size after the ad');
// download and upload again: recognised by the checksum with ad
[$j, $o] = up($t, $path, 'wieder.zip');
ok(($j['rej'] ?? false) && (str_contains($o, 'gibt es schon') || str_contains($o, 'wartet schon')), 'downloaded file with ad uploaded again: double');
// existing ad file in the archive (other case) is replaced, the rest stays
[$j, $o] = up($t, "$A/withad.zip");
$f = stored('withad.zip');
$z = new ZipArchive();
$z->open($root . '/data/files/' . $f['storage']);
$names = [];
for ($i = 0; $i < $z->numFiles; $i++) {
    $names[] = $z->getNameIndex($i);
}
$orig = new ZipArchive();
$orig->open("$A/withad.zip");
ok(in_array('DIEOELKA.TXT', $names, true) && !in_array('dieoelka.txt', $names, true), 'old ad file replaced: ' . implode(', ', $names));
ok(!str_contains((string)$z->getFromName('DIEOELKA.TXT'), 'alte Werbung'), 'content of the ad is the new one');
ok($z->getFromName('GAME.EXE') === $orig->getFromName('GAME.EXE') && $z->getFromName('FILE_ID.DIZ') === $orig->getFromName('FILE_ID.DIZ'), 'other entries unchanged');
$z->close();
$orig->close();
// ad off: no ad
setting('bbs_ad_on', '0');
[$j, $o] = up($t, "$A/valid4.zip");
$f = stored('valid4.zip');
$z = new ZipArchive();
$z->open($root . '/data/files/' . $f['storage']);
ok($z->getFromName('DIEOELKA.TXT') === false && $f['sha256'] === $f['sha256_orig'], 'ad switched off: no ad');
$z->close();
// own file name and a text that does not fit: no ad, file kept, log entry
setting('bbs_ad_on', '1');
setting('bbs_ad_name', 'WERBUNG.NFO');
// a character CP437 does not have (U+2013)
setting('bbs_ad_text', "Grüße von @BBSNAME@ \u{2013} bis bald");
file_put_contents("$A/other.rar", file_get_contents("$A/good.rar") . random_bytes(40));
[$j, $o] = up($t, "$A/other.rar");
ok(stored('other.rar') !== null, 'RAR gets no ad and is stored');
copy("$A/valid.zip", "$A/v5.zip");
$zz = new ZipArchive();
$zz->open("$A/v5.zip");
$zz->addFromString('X.TXT', random_bytes(50));
$zz->close();
[$j, $o] = up($t, "$A/v5.zip");
$f = stored('v5.zip');
$z = new ZipArchive();
$z->open($root . '/data/files/' . $f['storage']);
$ad = $z->getFromName('WERBUNG.NFO');
ok($ad !== false && str_contains($ad, "Gr\x81\xe1e") && str_contains($ad, '?'), 'own name and text, umlauts in CP437, unknown characters as ?');
$z->close();
setting('bbs_ad_on', '0');
setting('bbs_ad_name', '');
setting('bbs_ad_text', '');
$t->api('bye');
summary();
