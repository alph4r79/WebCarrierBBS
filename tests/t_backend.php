<?php
// Backend: daily backup, checksums in steps, BBS ad page, settings of the ZIP check.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
$root = getenv('CB_ROOT');
$bk = $root . '/data/backups';
@mkdir($bk, 0775, true);
foreach (glob("$bk/*") ?: [] as $f) {
    @unlink($f);
}
setting('auto_backup', '1');
setting('auto_backup_last', '0');
setting('auto_backup_error', '');
// a second full account and a moderator
q("UPDATE {levels} SET backend='moderator' WHERE level=50");
q("UPDATE {levels} SET backend='full' WHERE level=100");
mkuser('Cosy', 'cosy1234', 100);
mkuser('Moni', 'moni1234', 50);
file_put_contents("$bk/vor-update-1.10.0-20261001-120000-abcd1234.zip", 'update backup');
file_put_contents("$bk/webcarrierbbs-backup-2026-10-01-120000.zip", 'manual backup');

function autos(): array
{
    return glob(getenv('CB_ROOT') . '/data/backups/auto-*.zip') ?: [];
}

function post(TC $b, string $page, array $data = []): array
{
    return $b->req('sysop/index.php?p=' . $page, null, ['csrf' => $b->csrf] + $data);
}

echo "== daily backup\n";
$s = backend('Sysop', 'geheim123');
ok(str_contains($s->last['page'], 'id="autobackup"') && str_contains($s->last['page'], 'Tägliche Sicherung läuft'), 'overview starts the backup (hint "läuft")');
$r = post($s, 'autobackup');
$j = json_decode($r['body'], true);
ok(($j['state'] ?? '') === 'done' && ($j['text'] ?? '') === 'Tägliche Sicherung erstellt.', 'endpoint: backup created');
$a = autos();
ok(count($a) === 1 && preg_match('/auto-\d{8}-\d{6}-[0-9a-f]{8}\.zip$/', $a[0]), 'file with date and random part, prefix auto-');
$z = new ZipArchive();
$z->open($a[0]);
$names = [];
for ($i = 0; $i < $z->numFiles; $i++) {
    $names[] = $z->getNameIndex($i);
}
$z->close();
ok(count($names) === 1 && (str_ends_with($names[0], '.sqlite') || $names[0] === 'database.sql'), 'only the database in it: ' . implode(', ', $names));
ok((int)val("SELECT COUNT(*) FROM {log} WHERE text LIKE 'Automatic backup auto-% created'") >= 1, 'log entry');
$r = $s->req('sysop/index.php?p=dash');
ok(!str_contains($r['body'], 'id="autobackup"'), 'no second backup within 24 hours (no trigger)');
$j = json_decode(post($s, 'autobackup')['body'], true);
ok(($j['state'] ?? '') === 'skip' && count(autos()) === 1, 'endpoint called again: nothing new');

echo "== two at the same time\n";
setting('auto_backup_last', '0');
$c = backend('Cosy', 'cosy1234');
$mh = curl_multi_init();
$hs = [];
foreach ([$s, $c] as $b) {
    $h = curl_init($GLOBALS['BASE'] . 'sysop/index.php?p=autobackup');
    curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['csrf' => $b->csrf], CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $b->jar, CURLOPT_COOKIEJAR => $b->jar]);
    curl_multi_add_handle($mh, $h);
    $hs[] = $h;
}
do {
    curl_multi_exec($mh, $run);
    curl_multi_select($mh);
} while ($run);
$states = array_map(static fn($h) => json_decode((string)curl_multi_getcontent($h), true)['state'] ?? '?', $hs);
sort($states);
ok(count(autos()) === 2 && $states === ['done', 'skip'], 'two calls at once give one backup (' . implode(', ', $states) . ')');

echo "== keep 7\n";
for ($i = 0; $i < 7; $i++) {
    setting('auto_backup_last', '0');
    post($s, 'autobackup');
    usleep(1100000);
}
ok(count(autos()) === 7, 'after 9 backups 7 are left (' . count(autos()) . ')');
ok(is_file("$bk/vor-update-1.10.0-20261001-120000-abcd1234.zip") && is_file("$bk/webcarrierbbs-backup-2026-10-01-120000.zip"), 'update and manual backups untouched');

echo "== error\n";
setting('auto_backup_last', '0');
// on Windows a file watcher or indexer may hold the folder that was just written for a moment
function retry(callable $f): bool
{
    for ($i = 0; $i < 40; $i++) {
        if (@$f()) {
            return true;
        }
        usleep(250000);
    }
    return false;
}
ok(retry(static fn() => rename($bk, "$bk.away")) && file_put_contents($bk, 'no folder') !== false, 'backup folder replaced by a file');
$j = json_decode(post($s, 'autobackup')['body'], true);
ok(($j['state'] ?? '') === 'error' && str_contains($j['text'] ?? '', 'fehlgeschlagen'), 'failure reported: ' . ($j['text'] ?? ''));
$r = $s->req('sysop/index.php?p=dash');
ok(str_contains($r['body'], 'id="autobackup-err"') && str_contains($r['body'], 'flash warn'), 'yellow hint on the overview');
ok(!str_contains($r['body'], 'id="autobackup"'), 'no new try within the hour');
ok((int)val("SELECT COUNT(*) FROM {log} WHERE text LIKE 'Automatic backup failed:%'") >= 1, 'log entry of the failure');
ok($r['code'] === 200 && str_contains($r['body'], 'Knoten') || str_contains($r['body'], 'Nodes'), 'backend still works');
unlink($bk);
retry(static fn() => rename("$bk.away", $bk));
setting('auto_backup_error', json_encode(['time' => time() - 4000, 'msg' => 'test']));
$r = $s->req('sysop/index.php?p=dash');
ok(str_contains($r['body'], 'id="autobackup"') && str_contains($r['body'], 'id="autobackup-err"'), 'after an hour: new try, hint stays until then');
post($s, 'autobackup');
$r = $s->req('sysop/index.php?p=dash');
ok(!str_contains($r['body'], 'autobackup-err'), 'hint gone after a backup worked');

echo "== backup page\n";
$r = $s->req('sysop/index.php?p=backup');
ok(str_contains($r['body'], 'Automatische Sicherungen') && substr_count($r['body'], 'name="auto_del"') === 7, 'list with 7 backups');
$name = basename(autos()[0]);
$r = $s->req('sysop/index.php?p=backup&auto=' . urlencode($name));
ok(str_contains($r['head'], 'application/zip') && strlen($r['body']) === filesize("$bk/$name"), 'download');
$r = $s->req('sysop/index.php?p=backup&auto=' . urlencode('../config.php'));
ok(!str_contains($r['head'], 'application/zip'), 'download only for names of daily backups');
post($s, 'backup', ['auto_del' => $name]);
clearstatcache();
ok(!is_file("$bk/$name") && count(autos()) === 6, 'delete');
post($s, 'backup', ['auto_del' => 'vor-update-1.10.0-20261001-120000-abcd1234.zip']);
ok(is_file("$bk/vor-update-1.10.0-20261001-120000-abcd1234.zip"), 'other backups cannot be deleted there');
post($s, 'backup', ['auto_save' => '1']);
ok(val("SELECT value FROM {settings} WHERE name='auto_backup'") === '0', 'switched off');
setting('auto_backup_last', '0');
$r = $s->req('sysop/index.php?p=dash');
ok(!str_contains($r['body'], 'id="autobackup"'), 'switched off: no backup from the overview');
post($s, 'backup', ['auto_save' => '1', 'auto_backup' => 'on']);
ok(val("SELECT value FROM {settings} WHERE name='auto_backup'") === '1', 'switched on again');

echo "== moderator\n";
setting('auto_backup_last', '0');
$m = backend('Moni', 'moni1234');
ok(!str_contains($m->last['page'], 'autobackup'), 'moderator: no backup hint on the overview');
$n = count(autos());
$r = post($m, 'autobackup');
ok(count(autos()) === $n && !str_contains($r['body'], '"done"'), 'moderator: endpoint refused');
$r = $m->req('sysop/index.php?p=backup');
ok(!str_contains($r['body'], 'Automatische Sicherungen'), 'moderator: backup page refused');
setting('auto_backup_error', json_encode(['time' => time(), 'msg' => 'x']));
$r = $m->req('sysop/index.php?p=dash');
ok(!str_contains($r['body'], 'autobackup-err'), 'moderator: no failure hint');
setting('auto_backup_error', '');

echo "== checksums in steps\n";
$area = (int)val('SELECT MIN(id) FROM {file_areas}');
@mkdir("$root/data/files/$area", 0775, true);
q("DELETE FROM {files} WHERE filename LIKE 'hash%'");
for ($i = 0; $i < 130; $i++) {
    $fn = sprintf('hash%03d.txt', $i);
    file_put_contents("$root/data/files/$area/$fn", "Datei $i\r\n");
    q("INSERT INTO {files} (area_id, filename, size, description, uploader_id, uploader, added, approved, storage) VALUES (?,?,?,?,0,'',?,1,?)",
        [$area, $fn, 10, 'x', time(), "$area/$fn"]);
}
q("INSERT INTO {files} (area_id, filename, size, description, uploader_id, uploader, added, approved, storage) VALUES (?,'hashgone.txt',1,'x',0,'',?,1,?)",
    [$area, time(), "$area/hashgone.txt"]);
$left0 = (int)val("SELECT COUNT(*) FROM {files} WHERE sha256=''");
$s->req('sysop/index.php?p=dash');
$left1 = (int)val("SELECT COUNT(*) FROM {files} WHERE sha256=''");
ok($left0 - $left1 === 50, "overview: 50 files per visit ($left0 to $left1)");
ok(val('SELECT sha256 FROM {files} WHERE filename=?', ['hash000.txt']) === hash('sha256', "Datei 0\r\n"), 'checksum correct');
$r = $s->req('sysop/index.php?p=files');
ok(str_contains($r['body'], 'Prüfsummen jetzt berechnen') && str_contains($r['body'], "Noch $left1 Datei(en) ohne Prüfsumme."), 'files page: button with the count');
$steps = 0;
do {
    $j = json_decode(post($s, 'hashstep')['body'], true);
    $steps++;
} while (($j['left'] ?? 0) > 0 && $steps < 10);
ok(($j['left'] ?? -1) === 0 && $j['text'] === 'Alle Dateien haben eine Prüfsumme.', "button: done in $steps step(s)");
ok(val('SELECT sha256 FROM {files} WHERE filename=?', ['hashgone.txt']) === '-', 'missing file marked, not tried again');
ok((int)val("SELECT COUNT(*) FROM {log} WHERE text LIKE 'Checksums computed for % file(s), % left'") >= 1, 'log entry per step');
q("UPDATE {files} SET sha256='', sha256_orig='' WHERE filename LIKE 'hash0%'");
$r = post($s, 'files', ['hashes' => '1']);
ok((int)val("SELECT COUNT(*) FROM {files} WHERE sha256=''") === 50, 'without script: one step of 50 per click');
$r = $m->req('sysop/index.php?p=files');
ok(!str_contains($r['body'], 'hash-run'), 'moderator: no button');
$before = (int)val("SELECT COUNT(*) FROM {files} WHERE sha256=''");
post($m, 'files', ['hashes' => '1']);
ok((int)val("SELECT COUNT(*) FROM {files} WHERE sha256=''") === $before, 'moderator: refused');
post($s, 'hashstep');
q("DELETE FROM {files} WHERE filename LIKE 'hash%'");

echo "== BBS ad page\n";
setting('bbs_ad_on', '0');
setting('bbs_ad_name', '');
setting('bbs_ad_text', '');
$r = $s->req('sysop/index.php?p=bbsad');
ok(str_contains($r['body'], 'value="DIEOELKA.TXT"') && str_contains($r['body'], 'id="ad-preview"') && str_contains($r['body'], 'Die Ölkanne! BBS'), 'default name and preview with the name of the board');
ok(str_contains($r['body'], $GLOBALS['BASE']), 'preview with the address of the board');
foreach (['CON.TXT', 'A/B.TXT', 'VIELZULANG.TXT', 'FILE_ID.DIZ', 'A:B.TXT', 'ÄRGER.TXT'] as $bad) {
    post($s, 'bbsad', ['bbs_ad_name' => $bad, 'bbs_ad_text' => 'Hallo']);
    ok(val("SELECT value FROM {settings} WHERE name='bbs_ad_name'") === '', "name $bad refused");
}
post($s, 'bbsad', ['bbs_ad_name' => 'AD.TXT', 'bbs_ad_text' => str_repeat('x', 80)]);
ok(val("SELECT value FROM {settings} WHERE name='bbs_ad_text'") === '', 'line of 80 characters refused');
$r = $s->req('sysop/index.php?p=bbsad');
ok(str_contains($r['body'], 'Zeile 1 hat 80 Zeichen'), 'reason shown, input kept');
post($s, 'bbsad', ['bbs_ad_on' => 'on', 'bbs_ad_name' => "MY_AD!(1).TXT", 'bbs_ad_text' => 'Hallo']);
ok(val("SELECT value FROM {settings} WHERE name='bbs_ad_name'") === '', 'name with 9 characters before the dot refused');
post($s, 'bbsad', ['bbs_ad_on' => 'on', 'bbs_ad_name' => "AD_(1)!.TXT", 'bbs_ad_text' => "Grüße von @BBSNAME@\nam @DATE@"]);
ok(val("SELECT value FROM {settings} WHERE name='bbs_ad_name'") === 'AD_(1)!.TXT' && val("SELECT value FROM {settings} WHERE name='bbs_ad_on'") === '1', 'valid name and text saved');
ok((int)val("SELECT COUNT(*) FROM {log} WHERE text LIKE 'Saved the BBS ad (on)'") >= 1, 'log entry');
$r = $s->req('sysop/index.php?p=bbsad');
ok(str_contains($r['body'], 'Grüße von Die Ölkanne! BBS'), 'preview with the saved text');
post($s, 'bbsad', ['reset' => '1']);
ok(val("SELECT value FROM {settings} WHERE name='bbs_ad_name'") === '' && val("SELECT value FROM {settings} WHERE name='bbs_ad_text'") === '', 'reset to the default');
$r = $m->req('sysop/index.php?p=bbsad');
ok(!str_contains($r['body'], 'ad-preview'), 'moderator: no BBS ad page');

echo "== settings of the ZIP check\n";
$r = $s->req('sysop/index.php?p=settings');
ok(str_contains($r['body'], 'name="upload_zip_entries" value="2000"') && str_contains($r['body'], 'name="upload_zip_mb" value="256"'), 'fields with the defaults');

echo "== backend pages without PHP warnings\n";
foreach (['dash', 'backup', 'bbsad', 'files', 'settings', 'log'] as $p) {
    $r = $s->req('sysop/index.php?p=' . $p);
    ok($r['code'] === 200 && !preg_match('/(Warning|Notice|Deprecated|Fatal error)/', $r['body']), "page $p");
}
summary();
