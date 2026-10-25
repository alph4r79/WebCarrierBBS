<?php
/**
 * WebCarrier BBS tests: install.php BASEURL [mysql DBNAME [PORT]] runs the web installer of a fresh
 * throwaway instance. The sysop account Sysop / geheim123 exists only in these test instances.
 * MariaDB: user root, password from CB_MYSQL_PASS (see env.sh).
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$base = rtrim($argv[1], '/') . '/';
$post = ['bbs_lang' => 'de', 'driver' => 'sqlite', 'bbs_name' => 'Die Ölkanne! BBS', 'bbs_location' => 'Köln',
    'sysop' => 'Sysop', 'pass' => 'geheim123', 'pass2' => 'geheim123', 'prefix' => 'cb_'];
if (($argv[2] ?? '') === 'mysql') {
    $post = array_merge($post, ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $argv[4] ?? (getenv('CB_MYSQL_PORT') ?: '3399'),
        'dbname' => $argv[3], 'dbuser' => 'root', 'dbpass' => getenv('CB_MYSQL_PASS') ?: 'wegwerf']);
}
$ch = curl_init($base . 'install/index.php');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_RETURNTRANSFER => true]);
$out = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (preg_match_all('/class="flash bad"[^>]*>(.*?)<\/div>/s', (string)$out, $m)) {
    echo "ERRORS: " . strip_tags(implode(' | ', $m[1])) . "\n";
}
echo "install HTTP $code, " . (str_contains((string)$out, 'sysop/') ? 'done' : 'unclear') . "\n";
