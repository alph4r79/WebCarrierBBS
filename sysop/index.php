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
require_once CB_ROOT . '/core/backup.php';
require __DIR__ . '/pages_main.php';
require __DIR__ . '/pages_users.php';
require __DIR__ . '/pages_msgs.php';
require __DIR__ . '/pages_files.php';
require __DIR__ . '/pages_menus.php';
require __DIR__ . '/pages_backup.php';
require __DIR__ . '/pages_stats.php';
require __DIR__ . '/pages_texts.php';
require __DIR__ . '/pages_doors.php';
require __DIR__ . '/pages_polls.php';
require __DIR__ . '/pages_questions.php';
require __DIR__ . '/pages_account.php';
require __DIR__ . '/pages_mail.php';
require __DIR__ . '/pages_live.php';

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

/** The logged in backend account with its role (full or moderator), checked on every request. */
function a_admin(): ?array
{
    $id = (int)($_SESSION['cb_admin'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $u = DB::row('SELECT * FROM {users} WHERE id=? AND locked=0', [$id]);
    $role = $u ? cb_backend_role($u) : 'none';
    if ($role === 'none') {
        unset($_SESSION['cb_admin']);
        return null;
    }
    return $u + ['role' => $role];
}

function a_full(array $admin): bool
{
    return ($admin['role'] ?? '') === 'full';
}

/** A moderator tried something only full accounts may do: short message, log entry, back to the overview. */
function a_refuse(array $admin, string $what): never
{
    cb_log((int)$admin['id'], $admin['handle'], 'Refused for moderator: ' . mb_substr($what, 0, 120));
    a_flash(t('You are not allowed to do this.'), 'bad');
    a_go('dash');
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
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && cb_csrf_ok($_POST['csrf'] ?? null);
    $enter = static function (array $u): never {
        // terminal in another tab uses the same session, keep its node
        $old = session_id();
        session_regenerate_id(true);
        DB::q('UPDATE {nodes} SET sid=? WHERE sid=?', [session_id(), $old]);
        unset($_SESSION['cb_2fa']);
        $_SESSION['cb_admin'] = (int)$u['id'];
        // a visit starts with the login, the overview counts since the start of the previous one
        DB::q('UPDATE {users} SET backend_prev=backend_visit, backend_visit=?, backend_seen=? WHERE id=?', [time(), time(), (int)$u['id']]);
        cb_log((int)$u['id'], $u['handle'], 'Sysop backend login');
        a_go('dash');
    };
    // between password and code: valid for 5 minutes
    $step = is_array($_SESSION['cb_2fa'] ?? null) ? $_SESSION['cb_2fa'] : null;
    if ($step && time() - (int)$step['t'] > 300) {
        unset($_SESSION['cb_2fa']);
        $step = null;
        if ($post && isset($_POST['code'])) {
            $err = t('That took longer than 5 minutes. Please log in again.');
        }
    }
    if ($post && isset($_POST['cancel'])) {
        unset($_SESSION['cb_2fa']);
        a_go('dash');
    } elseif ($post && $step && isset($_POST['code'])) {
        $u = DB::row('SELECT * FROM {users} WHERE id=? AND locked=0 AND totp_on=1', [(int)$step['uid']]);
        $u = $u && cb_backend_role($u) !== 'none' ? $u : null;
        $blocked = !$u || cb_login_blocked((int)$u['id']);
        $how = $blocked ? null : cb_second_factor($u, (string)$_POST['code']);
        if ($how !== null) {
            if ($how === 'backup') {
                cb_log((int)$u['id'], $u['handle'], 'Backup code used (sysop backend)');
            }
            $enter($u);
        }
        if (!$blocked) {
            cb_log((int)$u['id'], $u['handle'], 'Wrong code (sysop backend)');
        }
        sleep(1);
        if ($blocked || cb_login_blocked((int)$u['id'])) {
            unset($_SESSION['cb_2fa']);
            $step = null;
            $err = t('Login failed.');
        } else {
            $err = t('The code is not correct.');
        }
    } elseif ($post && isset($_POST['handle'])) {
        $u = DB::row('SELECT * FROM {users} WHERE handle_lc=?', [mb_strtolower(a_in('handle', 30))]);
        $blocked = $u && cb_login_blocked((int)$u['id']);
        if ($u && !$blocked && password_verify((string)($_POST['pass'] ?? ''), $u['pass']) && cb_backend_role($u) !== 'none') {
            if (cb_totp_reset_file($u)) {
                $u['totp_on'] = 0;
            }
            if ((int)$u['totp_on'] !== 1) {
                $enter($u);
            }
            $_SESSION['cb_2fa'] = ['uid' => (int)$u['id'], 't' => time()];
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
<?php if ($step): ?>
<label><?= h(t('Code from the app')) ?><input name="code" class="mono" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="12">
<span class="hint"><?= h(t('The 6 digits your authenticator app shows, or one of your backup codes.')) ?></span></label>
<p class="row-actions"><button class="btn" type="submit"><?= h(t('Log in')) ?></button>
<button class="btn ghost" name="cancel" value="1" formnovalidate><?= h(t('Cancel')) ?></button></p>
<?php else: ?>
<label><?= h(t('Handle')) ?><input name="handle" required autofocus autocomplete="username"></label>
<label><?= h(t('Password')) ?><input type="password" name="pass" required autocomplete="current-password"></label>
<p><button class="btn" type="submit"><?= h(t('Log in')) ?></button></p>
<?php endif; ?>
<p class="note"><a href="../"><?= h(t('Back to the BBS')) ?></a></p>
</form></main><?= cb_credit(t('Source code')) ?></body></html>
<?php
    exit;
}

/* ------------------------------------------------------------------ routing */

// title (null: not in the menu), function, roles that may open it
$pages = [
    'dash' => ['Overview', 'page_dash', 'mod'],
    'mail' => ['My mail', 'page_mail', 'mod'],
    'chat' => ['Chat', 'page_chat', 'mod'],
    'reports' => ['Reports', 'page_reports', 'mod'],
    'stats' => ['Statistics', 'page_stats', 'full'],
    'settings' => ['Settings', 'page_settings', 'full'],
    'legal' => ['Imprint and privacy', 'page_legal', 'full'],
    '-1' => null,
    'users' => ['Users', 'page_users', 'mod'],
    'user' => [null, 'page_user', 'full'],
    'levels' => ['Levels', 'page_levels', 'full'],
    'banlist' => ['Ban list', 'page_banlist', 'full'],
    'questions' => ['Questionnaire', 'page_questions', 'full'],
    '-2' => null,
    'msgareas' => ['Message areas', 'page_msgareas', 'full'],
    'messages' => ['Messages', 'page_messages', 'mod'],
    'oneliners' => ['Oneliners', 'page_oneliners', 'mod'],
    'polls' => ['Polls', 'page_polls', 'mod'],
    'poll' => [null, 'page_poll', 'mod'],
    '-3' => null,
    'fileareas' => ['File areas', 'page_fileareas', 'full'],
    'files' => ['Files', 'page_files', 'mod'],
    'import' => ['File import', 'page_import', 'full'],
    '-4' => null,
    'menus' => ['Menus', 'page_menus', 'full'],
    'menu' => [null, 'page_menu', 'full'],
    'screens' => ['Screens', 'page_screens', 'full'],
    'screen' => [null, 'page_screen', 'full'],
    'texts' => ['Texts', 'page_texts', 'full'],
    'doors' => ['Doors', 'page_doors', 'full'],
    'log' => ['Log', 'page_log', 'mod'],
    'backup' => ['Backup', 'page_backup', 'full'],
    'account' => ['My account', 'page_account', 'mod'],
    'wansidraw' => [null, 'page_wansidraw', 'full'],
    'status' => [null, 'page_status', 'mod'],
    'chatpoll' => [null, 'page_chatpoll', 'mod'],
];
/*
 * What a moderator may do on the pages he may open: [allowed POST actions or null for all,
 * forbidden POST actions]. A POST needs one allowed and no forbidden action field.
 */
$modRules = [
    'dash' => [['kick', 'dismiss', 'chat_start'], ['broadcast', 'sysmsg_save', 'sysmsg_clear', 'update_now', 'check_now', 'enable_check', 'skip']],
    'messages' => [null, ['circ_prepare', 'circ_send', 'circ_next']],
    'oneliners' => [['del', 'del_tele', 'clear_tele'], []],
    'polls' => [['create'], []],
    'poll' => [['close'], ['delete', 'save']],
    'files' => [['approve', 'del', 'save_file', 'bulk'], ['upload']],
    'users' => [['approve_one', 'delete_one', 'bulk'], ['create']],
    'log' => [[], ['clear']],
];
if (!isset($pages[$page]) || $pages[$page] === null) {
    $page = 'dash';
}
cb_backend_touch((int)$admin['id']);
if (!a_full($admin)) {
    if ($pages[$page][2] !== 'mod') {
        a_refuse($admin, 'page ' . $page);
    }
    $rule = $modRules[$page] ?? [null, []];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $keys = array_keys($_POST);
        $bad = array_intersect($keys, $rule[1]);
        if ($bad || ($rule[0] !== null && !array_intersect($keys, $rule[0]))) {
            a_refuse($admin, 'page ' . $page . ', ' . implode(', ', array_slice(array_diff($keys, ['csrf']), 0, 3)));
        }
    }
    // users: only the list of users waiting for validation, the log without the export
    if (($page === 'users' && ($_GET['filter'] ?? '') !== 'pending') || ($page === 'log' && isset($_GET['csv']))) {
        a_refuse($admin, 'page ' . $page . ' ' . http_build_query($_GET));
    }
}
// every change in the backend leaves a log entry; actions without an own entry get a general one
// chat lines are no change of the board
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !in_array($page, ['status', 'chatpoll'], true) && !($page === 'chat' && isset($_POST['line']))
    && cb_csrf_ok($_POST['csrf'] ?? null)) {
    $logBefore = (int)DB::val('SELECT COALESCE(MAX(id),0) FROM {log}');
    register_shutdown_function(static function () use ($admin, $page, $logBefore) {
        if (!DB::val('SELECT COUNT(*) FROM {log} WHERE id>? AND user_id=?', [$logBefore, (int)$admin['id']])) {
            $keys = array_slice(array_values(array_diff(array_keys($_POST), ['csrf'])), 0, 3);
            cb_log((int)$admin['id'], $admin['handle'], 'Backend ' . $page . ': ' . implode(', ', $keys));
        }
    });
}

ob_start();
call_user_func($pages[$page][1], $admin);
$content = (string)ob_get_clean();
$flash = $_SESSION['cb_flash'] ?? [];
unset($_SESSION['cb_flash']);
$current = ['user' => 'users', 'menu' => 'menus', 'screen' => 'screens', 'poll' => 'polls'][$page] ?? $page;
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
  <span class="where"><?= h($admin['handle']) ?><?= a_full($admin) ? '' : ' <span class="role">' . h(t('Moderator')) . '</span>' ?>
    <form method="post" action="<?= h(a_url('logout')) ?>" style="display:inline"><?= a_csrf() ?>
      <button class="btn small ghost" style="color:#fff;border-color:#aab" type="submit"><?= h(t('Log out')) ?></button></form>
  </span>
</header>
<nav class="nav" aria-label="<?= h(t('Sysop backend')) ?>">
<?php foreach ($pages as $key => $pg): ?>
  <?php if ($pg === null): ?><span class="sep"></span><?php continue; endif; ?>
  <?php if ($pg[0] === null || ($pg[2] !== 'mod' && !a_full($admin))) { continue; } ?>
  <a href="<?= h(a_url((string)$key)) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= h(t($pg[0])) ?></a>
<?php endforeach; ?>
  <span class="sep"></span>
  <a href="../" target="_blank" rel="noopener"><?= h(t('Open the BBS')) ?></a>
</nav>
<?= a_live_markup($admin) ?>
<main class="page">
<?php foreach ($flash as [$type, $msg]): ?>
  <div class="flash <?= h($type) ?>"><p><?= h($msg) ?></p></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<?= cb_credit(t('Source code')) ?>
<script src="../assets/sysop.js?v=<?= CB_VERSION ?>"></script>
</body>
</html>
