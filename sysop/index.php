<?php
/**
 * WebCarrier BBS: sysop backend (router, login, layout and helpers).
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
if (!cb_installed()) {
    header('Location: ../install/');
    exit;
}
cb_boot();
Lang::load(Settings::get('language', 'en'));
require CB_ROOT . '/core/engine.php';
require __DIR__ . '/pages_main.php';
require __DIR__ . '/pages_users.php';
require __DIR__ . '/pages_msgs.php';
require __DIR__ . '/pages_files.php';
require __DIR__ . '/pages_menus.php';
require __DIR__ . '/pages_backup.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/* ------------------------------------------------------------------ helpers */

function t(string $s, ...$a): string
{
    static $de = null;
    if (Lang::$code === 'de') {
        $de ??= require __DIR__ . '/lang_de.php';
        $s = $de[$s] ?? $s;
    }
    foreach ($a as $i => $v) {
        $s = str_replace('{' . ($i + 1) . '}', (string)$v, $s);
    }
    return $s;
}

function a_url(string $p, array $q = []): string
{
    return '?' . http_build_query(['p' => $p] + $q);
}

function a_go(string $p, array $q = []): never
{
    header('Location: ' . a_url($p, $q));
    exit;
}

function a_flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['cb_flash'][] = [$type, $msg];
}

/** True for a POST with a valid CSRF token. */
function a_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    if (!cb_csrf_ok($_POST['csrf'] ?? null)) {
        a_flash(t('The form has expired. Please try again.'), 'bad');
        return false;
    }
    return true;
}

function a_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h(cb_csrf()) . '">';
}

function a_in(string $k, int $max = 255): string
{
    $v = (string)($_POST[$k] ?? '');
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function a_int(string $k, int $min = 0, int $max = 1000000): int
{
    return max($min, min($max, (int)($_POST[$k] ?? 0)));
}

function a_confirm(string $msg): string
{
    return ' onsubmit="return confirm(' . h(json_encode($msg, JSON_UNESCAPED_UNICODE)) . ')"';
}

function a_admin(): ?array
{
    $id = (int)($_SESSION['cb_admin'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $u = DB::row('SELECT * FROM {users} WHERE id=? AND locked=0', [$id]);
    if (!$u || (int)$u['level'] < Settings::int('sysop_level', 255)) {
        unset($_SESSION['cb_admin']);
        return null;
    }
    return $u;
}

function a_levels(): array
{
    $out = [];
    foreach (DB::all('SELECT level, name FROM {levels} ORDER BY level') as $l) {
        $out[(int)$l['level']] = $l['level'] . ' ' . $l['name'];
    }
    return $out;
}

function a_level_select(string $name, int $cur, int $max = 255): string
{
    $levels = a_levels();
    if (!isset($levels[$cur])) {
        $levels[$cur] = (string)$cur;
        ksort($levels);
    }
    $h = '<select name="' . h($name) . '">';
    foreach ($levels as $l => $label) {
        if ($l > $max) {
            continue;
        }
        $h .= '<option value="' . $l . '"' . ($l === $cur ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $h . '</select>';
}

/* ------------------------------------------------------------------ login */

$admin = a_admin();
$page = (string)($_GET['p'] ?? 'dash');

if ($page === 'logout') {
    if (a_post()) {
        unset($_SESSION['cb_admin']);
    }
    a_go('dash');
}

if (!$admin) {
    $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && cb_csrf_ok($_POST['csrf'] ?? null)) {
        $u = DB::row('SELECT * FROM {users} WHERE handle_lc=?', [mb_strtolower(a_in('handle', 30))]);
        $blocked = $u && cb_login_blocked((int)$u['id']);
        if ($u && !$blocked && password_verify((string)($_POST['pass'] ?? ''), $u['pass']) && (int)$u['locked'] === 0
            && (int)$u['level'] >= Settings::int('sysop_level', 255)) {
            // terminal in another tab uses the same session, keep its node
            $old = session_id();
            session_regenerate_id(true);
            DB::q('UPDATE {nodes} SET sid=? WHERE sid=?', [session_id(), $old]);
            $_SESSION['cb_admin'] = (int)$u['id'];
            cb_log((int)$u['id'], $u['handle'], 'Sysop backend login');
            a_go('dash');
        }
        if ($u && !$blocked) {
            cb_log((int)$u['id'], $u['handle'], 'Wrong password (sysop backend)');
        }
        sleep(1);
        $err = t('Login failed.');
    }
    ?><!DOCTYPE html>
<html lang="<?= h(Lang::$code) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title><?= h(t('Sysop login')) ?></title><link rel="stylesheet" href="../assets/sysop.css?v=<?= CB_VERSION ?>"></head>
<body><header class="band"><span class="brand"><?= h(Settings::get('bbs_name', 'WebCarrier BBS')) ?></span><span class="where"><?= h(t('Sysop backend')) ?></span></header>
<main class="page"><form method="post" class="panel form login">
<h1><?= h(t('Sysop login')) ?></h1>
<?php if ($err): ?><div class="flash bad"><p><?= h($err) ?></p></div><?php endif; ?>
<?= a_csrf() ?>
<label><?= h(t('Handle')) ?><input name="handle" required autofocus autocomplete="username"></label>
<label><?= h(t('Password')) ?><input type="password" name="pass" required autocomplete="current-password"></label>
<p><button class="btn" type="submit"><?= h(t('Log in')) ?></button></p>
<p class="note"><a href="../"><?= h(t('Back to the BBS')) ?></a></p>
</form></main><?= cb_credit(t('Source code')) ?></body></html>
<?php
    exit;
}

/* ------------------------------------------------------------------ routing */

$pages = [
    'dash' => ['Overview', 'page_dash'],
    'settings' => ['Settings', 'page_settings'],
    'legal' => ['Imprint and privacy', 'page_legal'],
    '-1' => null,
    'users' => ['Users', 'page_users'],
    'user' => [null, 'page_user'],
    'levels' => ['Levels', 'page_levels'],
    '-2' => null,
    'msgareas' => ['Message areas', 'page_msgareas'],
    'messages' => ['Messages', 'page_messages'],
    'oneliners' => ['Oneliners', 'page_oneliners'],
    '-3' => null,
    'fileareas' => ['File areas', 'page_fileareas'],
    'files' => ['Files', 'page_files'],
    'import' => ['File import', 'page_import'],
    '-4' => null,
    'menus' => ['Menus', 'page_menus'],
    'menu' => [null, 'page_menu'],
    'screens' => ['Screens', 'page_screens'],
    'screen' => [null, 'page_screen'],
    'doors' => ['Doors', 'page_doors'],
    'log' => ['Log', 'page_log'],
    'backup' => ['Backup', 'page_backup'],
];
if (!isset($pages[$page]) || $pages[$page] === null) {
    $page = 'dash';
}

ob_start();
call_user_func($pages[$page][1], $admin);
$content = (string)ob_get_clean();
$flash = $_SESSION['cb_flash'] ?? [];
unset($_SESSION['cb_flash']);
$current = $page === 'user' ? 'users' : ($page === 'menu' ? 'menus' : ($page === 'screen' ? 'screens' : $page));
?><!DOCTYPE html>
<html lang="<?= h(Lang::$code) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h(t($pages[$current][0] ?? 'Overview')) ?>: <?= h(Settings::get('bbs_name', 'WebCarrier BBS')) ?></title>
<link rel="stylesheet" href="../assets/sysop.css?v=<?= CB_VERSION ?>">
</head>
<body class="admin">
<header class="band">
  <span class="brand"><?= h(Settings::get('bbs_name', 'WebCarrier BBS')) ?></span>
  <span class="where"><?= h($admin['handle']) ?>
    <form method="post" action="<?= h(a_url('logout')) ?>" style="display:inline"><?= a_csrf() ?>
      <button class="btn small ghost" style="color:#fff;border-color:#aab" type="submit"><?= h(t('Log out')) ?></button></form>
  </span>
</header>
<nav class="nav" aria-label="<?= h(t('Sysop backend')) ?>">
<?php foreach ($pages as $key => $pg): ?>
  <?php if ($pg === null): ?><span class="sep"></span><?php continue; endif; ?>
  <?php if ($pg[0] === null) { continue; } ?>
  <a href="<?= h(a_url((string)$key)) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= h(t($pg[0])) ?></a>
<?php endforeach; ?>
  <span class="sep"></span>
  <a href="../" target="_blank" rel="noopener"><?= h(t('Open the BBS')) ?></a>
</nav>
<main class="page">
<?php foreach ($flash as [$type, $msg]): ?>
  <div class="flash <?= h($type) ?>"><p><?= h($msg) ?></p></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<?= cb_credit(t('Source code')) ?>
</body>
</html>
