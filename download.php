<?php
/**
 * WebCarrier BBS: sends a file for a one time download token issued by the engine.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
if (!cb_installed()) {
    http_response_code(404);
    exit;
}
cb_boot();

$t = (string)($_GET['t'] ?? '');
$tok = $_SESSION['cb_dl'][$t] ?? null;
if (!$tok || $tok['x'] < time() || empty($_SESSION['cb']['uid'])) {
    http_response_code(403);
    exit('Transfer aborted.');
}
unset($_SESSION['cb_dl'][$t]);
$f = DB::row('SELECT * FROM {files} WHERE id=? AND approved=1', [(int)$tok['f']]);
$path = $f ? realpath(CB_DATA . '/files/' . $f['storage']) : false;
$base = realpath(CB_DATA . '/files');
if (!$f || !$path || !$base || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
session_write_close();

$name = (string)$f['filename'];
$ascii = preg_replace('/[^A-Za-z0-9._\-]/', '_', $name);
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
while (ob_get_level()) {
    ob_end_clean();
}
readfile($path);
