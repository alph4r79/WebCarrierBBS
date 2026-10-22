<?php
/**
 * WebCarrier BBS: receives an upload while the engine waits in the upload state.
 * The file is checked at once (signature, damaged ZIP, double by checksum), a rejected one is
 * deleted. Otherwise it gets the BBS ad and is parked in data/tmp until the user has entered
 * a description.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!cb_installed() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(400);
    exit;
}
cb_boot();
Lang::load(Settings::get('language', 'en'));
require __DIR__ . '/core/engine.php';

function up_fail(string $msg): never
{
    echo json_encode(['ok' => false, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Rejected by the check: file gone, log entry, the engine shows the reason (see i_fup()). */
function up_reject(string $tmp, array $u, string $name, string $why, string $key, array $args = []): never
{
    @unlink($tmp);
    // not "Upload ...", the statistics count those lines
    cb_log((int)$u['id'], (string)$u['handle'], 'Rejected upload ' . $name . ': ' . $why);
    $_SESSION['cb_up_rej'] = ['k' => $key, 'a' => $args];
    echo json_encode(['ok' => false, 'rej' => true]);
    exit;
}

if (!cb_csrf_ok((string)($_POST['csrf'] ?? ''))) {
    up_fail('session');
}
$S = $_SESSION['cb'] ?? [];
$area = (int)($S['up']['area'] ?? 0);
if (empty($S['uid']) || ($S['st'] ?? '') !== 'fup' || $area <= 0) {
    up_fail('state');
}
$u = DB::row('SELECT * FROM {users} WHERE id=?', [(int)$S['uid']]);
$a = DB::row('SELECT * FROM {file_areas} WHERE id=?', [$area]);
if (!$u || !$a || (int)$a['uploads'] !== 1 || (int)$a['ul_level'] > (int)$u['level']) {
    up_fail('denied');
}
unset($_SESSION['cb_up_rej']);
$file = $_FILES['file'] ?? null;
if (!$file || !is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    up_fail(Lang::get('fup_failed'));
}
if ((int)$file['size'] > Engine::uploadMaxBytes()) {
    up_fail('too large');
}

$name = cb_safe_filename((string)$file['name']);
if ($name === '') {
    up_fail('bad name');
}
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$allowed = array_filter(array_map('trim', explode(',', strtolower(Settings::get('upload_ext', 'zip,arj,lzh,rar,7z,txt,ans')))));
// dangerous names are blocked regardless of upload_ext
if ($ext === '' || !in_array($ext, $allowed, true) || cb_dangerous_filename($name)) {
    up_fail('type not allowed');
}
if (DB::val('SELECT COUNT(*) FROM {files} WHERE area_id=? AND LOWER(filename)=?', [$area, strtolower($name)])) {
    up_fail('exists');
}

if (!is_dir(CB_DATA . '/tmp')) {
    mkdir(CB_DATA . '/tmp', 0775, true);
}
foreach (glob(CB_DATA . '/tmp/up_*') ?: [] as $old) {
    if (filemtime($old) < time() - 3600) {
        @unlink($old);
    }
}
$tmp = CB_DATA . '/tmp/up_' . bin2hex(random_bytes(10));
if (!move_uploaded_file($file['tmp_name'], $tmp)) {
    up_fail(Lang::get('fup_failed'));
}

$why = cb_upload_check($tmp, $name);
if ($why !== null) {
    $msg = ['type' => ['content does not match the extension', 'fup_rej_type'], 'damaged' => ['damaged archive', 'fup_rej_damaged'],
        'limit' => ['archive over the limits', 'fup_rej_limit']][$why];
    up_reject($tmp, $u, $name, $msg[0], $msg[1]);
}
$orig = (string)hash_file('sha256', $tmp);
$dupe = cb_file_dupe($orig);
if ($dupe !== null) {
    [$key, $args, $why] = cb_dupe_message($dupe, (int)$u['level']);
    up_reject($tmp, $u, $name, $why, $key, $args);
}

$diz = '';
if ($ext === 'zip' && class_exists('ZipArchive')) {
    $z = new ZipArchive();
    if ($z->open($tmp) === true) {
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = (string)$z->getNameIndex($i);
            if (strtolower(basename($n)) === 'file_id.diz') {
                $raw = (string)$z->getFromIndex($i, 4096);
                $diz = CP437::toUtf8($raw);
                $diz = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', str_replace("\r", '', $diz)) ?? '');
                break;
            }
        }
        $z->close();
    }
}

// the ad after FILE_ID.DIZ was read, size and checksum afterwards
if ($ext === 'zip' && Settings::get('bbs_ad_on', '0') === '1') {
    $err = cb_ad_insert($tmp, (string)$u['handle']);
    if ($err !== null) {
        cb_log((int)$u['id'], (string)$u['handle'], 'BBS ad not added to upload ' . $name . ': ' . $err);
    }
    clearstatcache(true, $tmp);
}
$_SESSION['cb_up'] = ['tmp' => $tmp, 'name' => $name, 'size' => (int)filesize($tmp), 'diz' => mb_substr($diz, 0, 2000),
    'sha256' => (string)hash_file('sha256', $tmp), 'sha256_orig' => $orig];
echo json_encode(['ok' => true]);
