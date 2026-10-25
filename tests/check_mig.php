<?php
// After the update of a 1.10.0 installation (seed_old.php): DB version, new columns, Hi-Lo takeover, defaults. Writes the schema to argv[1].
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/tc.php';
$r = (new TC())->req('index.php');
ok($r['code'] === 200, 'first request after the update');
// the version the updated code expects, so the check does not age with every new step
preg_match("/define\('CB_DB_VERSION', (\d+)\)/", (string)file_get_contents(getenv('CB_ROOT') . '/core/migrate.php'), $dv);
$want = (int)($dv[1] ?? 0);
ok($want > 0 && (int)val("SELECT value FROM {settings} WHERE name='db_version'") === $want, "DB version $want");
$cfg = require getenv('CB_ROOT') . '/core/config.php';
$my = $cfg['db']['driver'] === 'mysql';
$schema = [];
$tables = $my ? array_column(q("SELECT table_name AS t FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'cb\\_%' ORDER BY table_name")->fetchAll(), 't')
    : array_column(q("SELECT name AS t FROM sqlite_master WHERE type='table' AND name LIKE 'cb\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(), 't');
foreach ($tables as $t) {
    if ($my) {
        $cols = q('SELECT column_name AS n, column_type AS ty, is_nullable AS nl, column_default AS d FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY column_name', [$t])->fetchAll();
        $idx = array_column(q('SELECT DISTINCT index_name AS i FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? ORDER BY index_name', [$t])->fetchAll(), 'i');
    } else {
        $cols = array_map(static fn($c) => ['n' => $c['name'], 'ty' => strtoupper($c['type']), 'nl' => $c['notnull'] ? 'NO' : 'YES', 'd' => $c['dflt_value']], tdb()->query("PRAGMA table_info($t)")->fetchAll());
        usort($cols, static fn($x, $y) => strcmp($x['n'], $y['n']));
        $idx = array_column(tdb()->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='$t' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(), 'name');
    }
    $schema[$t] = ['cols' => array_map(static fn($c) => $c['n'] . ' ' . $c['ty'] . ' ' . $c['nl'] . ' ' . var_export($c['d'], true), $cols), 'idx' => $idx];
}
file_put_contents($argv[1], json_encode($schema, JSON_PRETTY_PRINT));
foreach (['nodes' => ['away', 'screen'], 'files' => ['sha256', 'sha256_orig'], 'msg_areas' => ['public'], 'users' => ['web_anon']] as $t => $cs) {
    foreach ($cs as $c) {
        ok((bool)array_filter($schema["cb_$t"]['cols'], static fn($x) => str_starts_with($x, "$c ")), "column $t.$c");
    }
}
ok(in_array('cb_files_sha256', $schema['cb_files']['idx'], true) && in_array('cb_files_sha256_orig', $schema['cb_files']['idx'], true), 'indexes on the checksums');
if (getenv('CB_MIGRATED')) {
    $s = static fn($h) => q("SELECT s.value, s.label FROM {door_scores} s JOIN {users} u ON u.id=s.user_id WHERE s.door='hilo' AND u.handle=?", [$h])->fetch();
    ok(($s('Alt1')['value'] ?? null) == 4 && str_contains((string)($s('Alt1')['label'] ?? ''), '4 Versuche'), 'Hi-Lo: value only in door_data taken over (4)');
    ok(($s('Alt2')['value'] ?? null) == 3, 'Hi-Lo: worse list value replaced by the better door_data value (5 to 3)');
    ok(($s('Alt3')['value'] ?? null) == 2, 'Hi-Lo: better list value kept (2)');
    ok((int)val("SELECT COUNT(*) FROM {files} WHERE sha256<>''") === 0, 'the migration computes no checksums');
}
foreach (['auto_backup' => '1', 'bbs_ad_on' => '0', 'upload_zip_entries' => '2000', 'upload_zip_mb' => '256', 'public_index' => '1', 'bbs_url' => ''] as $k => $v) {
    ok(val('SELECT value FROM {settings} WHERE name=?', [$k]) === $v, "setting $k = $v");
}
summary();
