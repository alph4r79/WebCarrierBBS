<?php
/**
 * WebCarrier BBS: terminal endpoint. The browser terminal posts one input per request.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!cb_installed()) {
    http_response_code(503);
    echo json_encode(['err' => 'not installed']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

cb_boot();
if (cb_maintenance()) {
    $w = new AnsiWriter();
    $w->write(cb_maintenance_text());
    echo json_encode(['o' => CP437::transport($w->buf), 'ask' => null, 'hang' => true, 'csrf' => cb_csrf()], JSON_UNESCAPED_UNICODE);
    exit;
}
Lang::load(Settings::get('language', 'en'));
require __DIR__ . '/core/engine.php';

$in = json_decode((string)file_get_contents('php://input'), true);
$a = is_array($in) ? (string)($in['a'] ?? '') : '';
$v = is_array($in) ? (string)($in['v'] ?? '') : '';
// max. message is 20000 chars, up to 4 bytes each
$v = substr($v, 0, 100000);

if (!in_array($a, ['start', 'in', 'wait', 'idle', 'bye', 'ping', 'poll'], true)) {
    http_response_code(400);
    echo json_encode(['err' => 'bad request']);
    exit;
}
if ($a !== 'start' && !cb_csrf_ok(is_array($in) ? (string)($in['csrf'] ?? '') : '')) {
    echo json_encode(['o' => '', 'ask' => null, 'hang' => true, 'csrf' => cb_csrf()]);
    exit;
}

try {
    $engine = new Engine();
    $res = match ($a) {
        'ping' => $engine->ping(),
        'poll' => $engine->poll(),
        default => $engine->run($a, $v),
    };
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('WebCarrier BBS: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['err' => 'internal error']);
}
