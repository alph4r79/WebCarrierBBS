<?php
/**
 * WebCarrier BBS: the terminal page.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
if (!cb_installed()) {
    header('Location: install/');
    exit;
}
cb_boot();
Lang::load(Settings::get('language', 'en'));
if (cb_maintenance()) {
    http_response_code(503);
    header('Retry-After: 300');
    $msg = trim(preg_replace('/\|\d\d|\|CL/', '', str_replace('|CR', "\n", Lang::get('maintenance'))) ?? '');
    ?><!DOCTYPE html>
<html lang="<?= h(Lang::$code) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title><?= h(Settings::get('bbs_name', 'WebCarrier BBS')) ?></title>
<link rel="stylesheet" href="assets/terminal.css?v=<?= CB_VERSION ?>"></head>
<body class="legal"><article><h1><?= h(Settings::get('bbs_name', 'WebCarrier BBS')) ?></h1><p><?= h($msg) ?></p></article></body></html>
<?php
    exit;
}

$js = [];
foreach (['js_title', 'js_press', 'js_dialing', 'js_nocarrier', 'js_redial', 'js_uploading', 'js_netfail',
             'js_ed_saved', 'js_ed_aborted', 'js_ed_help', 'js_ed_full'] as $k) {
    $js[substr($k, 3)] = Lang::get($k);
}
$host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$cfg = [
    'name' => Settings::get('bbs_name', 'WebCarrier BBS'),
    'host' => $host,
    'sound' => Settings::get('sound', '1') === '1',
    'baud' => Settings::int('baud', 14400),
    'base' => cb_base_path(),
    'version' => CB_VERSION,
    'author' => CB_AUTHOR,
    'L' => $js,
];
$footer = Settings::get('show_footer', '1') === '1';
$lang = Lang::$code;
?><!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="<?= Settings::get('noindex', '0') === '1' ? 'noindex' : 'index' ?>">
<title><?= h($cfg['name']) ?></title>
<link rel="stylesheet" href="assets/terminal.css?v=<?= CB_VERSION ?>">
</head>
<body>
<main id="screen">
  <canvas id="term" aria-label="<?= h($cfg['name']) ?> terminal" tabindex="0"></canvas>
</main>
<footer id="legal">
<?php if ($footer): ?>
  <a href="legal.php?p=impressum"><?= h(Lang::get('legal_impressum')) ?></a>
  <a href="legal.php?p=privacy"><?= h(Lang::get('legal_privacy')) ?></a>
<?php endif; ?>
  <a href="<?= h(CB_SOURCE_URL) ?>" rel="noopener"><?= h(Lang::get('legal_source')) ?></a>
</footer>
<input type="file" id="upfile" hidden>
<iframe id="dlframe" title="download" hidden></iframe>
<script>window.CBCFG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/font.js?v=<?= CB_VERSION ?>"></script>
<script src="assets/terminal.js?v=<?= CB_VERSION ?>"></script>
<script src="assets/app.js?v=<?= CB_VERSION ?>"></script>
</body>
</html>
