<?php
// Checks: same keys in lang/de.php and lang/en.php, every t() text in sysop/lang_de.php and every
// it()/ith() text in install/lang_de.php, no orphaned entries.
//
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
$root = $argv[1];
$err = 0;
$de = require "$root/lang/de.php";
$en = require "$root/lang/en.php";
foreach ([array_diff_key($de, $en), array_diff_key($en, $de)] as $i => $d) {
    foreach ($d as $k => $_) {
        echo ($i ? 'only en: ' : 'only de: ') . $k . "\n";
        $err++;
    }
}
function lits(string $root, array $dirs, string $fnRe): array
{
    $out = [];
    foreach ($dirs as $pat) {
        foreach (glob("$root/$pat") as $f) {
            $src = file_get_contents($f);
            preg_match_all("/\\b(?:$fnRe)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
            foreach ($m[1] as $s) {
                $out[stripcslashes(str_replace("\\'", "'", $s))] = basename($f);
            }
        }
    }
    return $out;
}
foreach ([['sysop/lang_de.php', ['sysop/*.php', 'core/*.php'], 't'], ['install/lang_de.php', ['install/*.php'], 'ith|it']] as [$tf, $dirs, $re]) {
    $tr = require "$root/$tf";
    $used = lits($root, $dirs, $re);
    unset($used['']);
    foreach ($used as $s => $f) {
        if (!array_key_exists($s, $tr)) {
            echo "missing in $tf ($f): $s\n";
            $err++;
        }
    }
    foreach ($tr as $k => $_) {
        if (!array_key_exists($k, $used)) {
            echo "orphan in $tf: $k\n";
        }
    }
}
echo "errors: $err\n";
