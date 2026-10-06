<?php
/**
 * WebCarrier BBS: imprint and privacy policy as plain HTML pages.
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
$p = ($_GET['p'] ?? '') === 'privacy' ? 'privacy' : 'impressum';
$title = Lang::get($p === 'privacy' ? 'legal_privacy' : 'legal_impressum');
$text = trim(Settings::get('legal_' . $p));
?><!DOCTYPE html>
<html lang="<?= h(Lang::$code) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?>: <?= h(Settings::get('bbs_name', 'WebCarrier BBS')) ?></title>
<link rel="stylesheet" href="assets/terminal.css?v=<?= CB_VERSION ?>">
</head>
<body class="legal">
<article>
  <h1><?= h($title) ?></h1>
  <?php if ($text === ''): ?>
    <p><?= h(strip_tags(preg_replace('/\|\d\d|\|CR/', '', Lang::get('legal_missing')))) ?></p>
  <?php else: ?>
    <p><?= h($text) ?></p>
  <?php endif; ?>
  <p><a href="./">&lt; <?= h(Settings::get('bbs_name', 'WebCarrier BBS')) ?></a></p>
</article>
</body>
</html>
