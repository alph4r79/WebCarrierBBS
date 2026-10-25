<?php
// Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
// SPDX-License-Identifier: AGPL-3.0-or-later
require getenv('CB_ROOT') . '/core/bootstrap.php';
$cfg = require CB_ROOT . '/core/config.php';
DB::connect($cfg['db']);
require __DIR__ . '/tc.php';
foreach ([['Die Ölkanne! BBS', 'DIEOELKA.TXT'], ['Über-Mailbox', 'UEBERMAI.TXT'], ['!!!', 'BBS-AD.TXT'], ['', 'BBS-AD.TXT'],
          ['ßtraße 9', 'SSTRASSE.TXT'], ['Café Modem', 'CAFMODEM.TXT'], ['ABC', 'ABC.TXT'], ['日本語', 'BBS-AD.TXT'], ['a.b/c\\d', 'ABCD.TXT']] as [$in, $exp]) {
    ok(cb_ad_default_name($in) === $exp, "name from \"$in\": " . cb_ad_default_name($in));
    ok(cb_ad_name_ok(cb_ad_default_name($in)), "derived name is valid");
}
foreach (['README.TXT' => true, 'AD' => true, 'A~1.$$$' => true, "X'Y.TXT" => true, 'CON' => false, 'lpt1.txt' => false, 'file_id.diz' => false,
          'A B.TXT' => false, 'A.B.C' => false, 'ABCDEFGHI.TXT' => false, 'A.TEXT' => false, 'A*.TXT' => false, '../A.TXT' => false, 'Ä.TXT' => false] as $n => $exp) {
    ok(cb_ad_name_ok((string)$n) === $exp, "name check $n => " . ($exp ? 'valid' : 'invalid'));
}
// default text: every line 64 wide after filling, at most 79
foreach (['de', 'en'] as $l) {
    Lang::load($l);
    $t = cb_ad_text(cb_ad_default_text($l), 'Tester', time());
    $w = array_map('mb_strlen', explode("\n", $t));
    ok(max($w) === 64 && min($w) === 64, "default text $l: all lines 64 wide");
    ok(!preg_match('/[\x{2013}\x{2014}]/u', cb_ad_default_text($l)), "default text $l: no dashes");
}
summary();
