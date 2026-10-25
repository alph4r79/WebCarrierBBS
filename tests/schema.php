<?php
// schema.php ROOT: columns of all tables (name type notnull default) and indexes, one per line, for diffing.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
$root = $argv[1];
$cfg = require $root . '/core/config.php';
$db = $cfg['db'];
$p = $db['prefix'];
if ($db['driver'] === 'mysql') {
    $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?: 3306) . ';dbname=' . $db['name'], $db['user'], $db['pass']);
    $rows = $pdo->query("SELECT table_name, column_name, column_type, is_nullable, column_default FROM information_schema.columns
        WHERE table_schema=DATABASE() ORDER BY table_name, column_name")->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as $r) {
        echo substr($r[0], strlen($p)), '.', $r[1], ' ', $r[2], ' ', $r[3], ' ', var_export($r[4], true), "\n";
    }
    foreach ($pdo->query("SELECT DISTINCT table_name, index_name FROM information_schema.statistics WHERE table_schema=DATABASE() ORDER BY 1, 2")->fetchAll(PDO::FETCH_NUM) as $r) {
        echo 'index ', substr($r[0], strlen($p)), ' ', $r[1], "\n";
    }
} else {
    $pdo = new PDO('sqlite:' . $root . '/' . $db['file']);
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$p}%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($tables as $t) {
        foreach ($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $out[] = substr($t, strlen($p)) . '.' . $c['name'] . ' ' . strtoupper($c['type']) . ' ' . $c['notnull'] . ' ' . var_export($c['dflt_value'], true);
        }
    }
    sort($out);
    echo implode("\n", $out), "\n";
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE '{$p}%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $i) {
        echo "index $i\n";
    }
}
