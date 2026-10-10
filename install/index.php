<?php
/**
 * WebCarrier BBS installer.
 * Checks the server, writes core/config.php, creates the tables and default content.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

/** Installer language: ?lang=, else the browser's first choice (German or English). */
function in_lang(): string
{
    $q = $_GET['lang'] ?? null;
    if ($q === 'de' || $q === 'en') {
        return $q;
    }
    $best = '';
    $bestQ = -1.0;
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $p = explode(';', $part);
        $tag = strtolower(trim($p[0]));
        $weight = isset($p[1]) && preg_match('/q\s*=\s*([0-9.]+)/', $p[1], $m) ? (float)$m[1] : 1.0;
        if ($tag !== '' && $weight > $bestQ) {
            $best = $tag;
            $bestQ = $weight;
        }
    }
    return str_starts_with($best, 'de') ? 'de' : 'en';
}

define('CB_INSTALL_LANG', in_lang());

/** Installer text, English source, German from lang_de.php. */
function it(string $s, ...$a): string
{
    static $de = null;
    if (CB_INSTALL_LANG === 'de') {
        $de ??= require __DIR__ . '/lang_de.php';
        $s = $de[$s] ?? $s;
    }
    foreach ($a as $i => $v) {
        $s = str_replace('{' . ($i + 1) . '}', (string)$v, $s);
    }
    return $s;
}

/** Escaped installer text, {1}, {2} ... become <code> elements. */
function ith(string $s, string ...$code): string
{
    $out = h(it($s));
    foreach ($code as $i => $c) {
        $out = str_replace('{' . ($i + 1) . '}', '<code>' . h($c) . '</code>', $out);
    }
    return $out;
}

$errors = [];
$done = false;
$drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
// label, ok, required
$checks = [
    [it('PHP 8.1 or newer'), version_compare(PHP_VERSION, '8.1.0', '>='), true],
    [it('PDO extension'), class_exists('PDO'), true],
    [it('PDO SQLite or PDO MySQL'), in_array('sqlite', $drivers, true) || in_array('mysql', $drivers, true), true],
    [it('mbstring extension'), function_exists('mb_strlen'), true],
    [it('core/ writable'), is_writable(CB_ROOT . '/core'), true],
    [it('data/ writable'), is_writable(CB_DATA), true],
    [it('ZipArchive (optional, reads FILE_ID.DIZ)'), class_exists('ZipArchive'), false],
];
$locked = cb_installed();

/** POST value, strings only (array input breaks trim() etc.) */
$in = static fn(string $k, string $def = ''): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : $def;
$f = [
    'lang' => $in('bbs_lang', CB_INSTALL_LANG),
    'driver' => $in('driver', in_array('sqlite', $drivers, true) ? 'sqlite' : 'mysql'),
    'host' => $in('host', 'localhost'),
    'port' => $in('port'),
    'dbname' => $in('dbname'),
    'dbuser' => $in('dbuser'),
    'dbpass' => $in('dbpass'),
    'prefix' => $in('prefix', 'cb_'),
    'bbs_name' => $in('bbs_name'),
    'bbs_location' => $in('bbs_location'),
    'sysop' => $in('sysop'),
    'pass' => $in('pass'),
    'pass2' => $in('pass2'),
];

if (!$locked && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($checks as [$label, $ok, $required]) {
        if (!$ok && $required) {
            $errors[] = it('Requirement missing: {1}', $label);
        }
    }
    $f['prefix'] = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$f['prefix'])) ?: 'cb_';
    if (mb_strlen(trim($f['bbs_name'])) < 2) {
        $errors[] = it('Please enter a name for your BBS.');
    }
    if (!preg_match(CB_HANDLE_RE, trim($f['sysop']))) {
        $errors[] = it('Sysop handle: 3 to 20 letters, digits, spaces, dots, dashes or underscores.');
    }
    if (mb_strlen($f['pass']) < 8) {
        $errors[] = it('The sysop password needs at least 8 characters.');
    } elseif ($f['pass'] !== $f['pass2']) {
        $errors[] = it('The passwords do not match.');
    }
    if (!in_array($f['lang'], ['de', 'en'], true)) {
        $f['lang'] = 'en';
    }
    if (!$errors) {
        if ($f['driver'] === 'mysql') {
            $db = ['driver' => 'mysql', 'host' => trim($f['host']), 'port' => trim($f['port']), 'name' => trim($f['dbname']),
                'user' => trim($f['dbuser']), 'pass' => $f['dbpass'], 'prefix' => $f['prefix']];
        } else {
            $db = ['driver' => 'sqlite', 'file' => 'data/carrier_' . bin2hex(random_bytes(6)) . '.sqlite', 'prefix' => $f['prefix']];
        }
        $created = false;
        try {
            DB::connect($db);
            if (DB::$driver === 'mysql' && DB::val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$f['prefix'] . 'settings'])) {
                throw new RuntimeException(it('There are already WebCarrier BBS tables with this prefix in the database.'));
            }
            $created = true;
            foreach (DB::schema() as $sql) {
                DB::$pdo->exec(DB::sql($sql));
            }
            cb_install_defaults($f);
            $cfg = "<?php\n// WebCarrier BBS configuration, written by the installer.\nreturn " . var_export(['db' => $db], true) . ";\n";
            if (file_put_contents(CB_ROOT . '/core/config.php', $cfg) === false) {
                throw new RuntimeException(it('Could not write core/config.php.'));
            }
            @chmod(CB_ROOT . '/core/config.php', 0640);
            $done = true;
        } catch (Throwable $e) {
            $errors[] = it('Database error: {1}', $e->getMessage());
            // drop half created tables, else the next try fails with "tables exist"
            if ($created && DB::$driver === 'mysql') {
                foreach (DB::schema() as $sql) {
                    if (preg_match('/^CREATE TABLE \{([a-z_]+)\}/', $sql, $m)) {
                        try {
                            DB::$pdo->exec(DB::sql('DROP TABLE IF EXISTS {' . $m[1] . '}'));
                        } catch (Throwable $ignored) {
                        }
                    }
                }
            }
            if (($db['driver'] ?? '') === 'sqlite' && !empty($db['file']) && is_file(CB_ROOT . '/' . $db['file'])) {
                @unlink(CB_ROOT . '/' . $db['file']);
            }
        }
    }
}

/** Default settings, levels, areas, menus, screens and the sysop account. */
function cb_install_defaults(array $f): void
{
    $de = $f['lang'] === 'de';
    $now = time();
    $sysop = trim($f['sysop']);
    $settings = [
        'bbs_name' => trim($f['bbs_name']), 'sysop_name' => $sysop, 'bbs_location' => trim($f['bbs_location']),
        'language' => $f['lang'], 'timezone' => $de ? 'Europe/Berlin' : 'UTC', 'max_nodes' => '4', 'idle_minutes' => '5',
        'baud' => '14400', 'sound' => '1', 'allow_new' => '1', 'new_level' => '10', 'sysop_level' => '255',
        'upload_max_kb' => '8192', 'upload_ext' => 'zip,arj,lzh,rar,7z,lha,txt,ans,asc,nfo,diz,gif,png,jpg',
        'upload_auto_approve' => '0', 'max_msg_lines' => '200', 'logon_oneliners' => '1', 'show_footer' => '1',
        'noindex' => '0', 'legal_impressum' => '', 'legal_privacy' => '', 'update_check' => '0', 'new_validate' => '0', 'db_version' => (string)CB_DB_VERSION,
    ];
    foreach ([[10, $de ? 'Neuer User' : 'New user', 30, 2048, 0], [20, $de ? 'Mitglied' : 'Member', 60, 10240, 0],
                 [50, $de ? 'Stammgast' : 'Regular', 120, 0, 0], [100, 'Co-Sysop', 240, 0, 0], [255, 'Sysop', 0, 0, 0]] as $l) {
        DB::insert('levels', ['level' => $l[0], 'name' => $l[1], 'minutes' => $l[2], 'dl_kb' => $l[3], 'ratio' => $l[4]]);
    }
    $sid = DB::insert('users', [
        'handle' => $sysop, 'handle_lc' => mb_strtolower($sysop), 'pass' => password_hash($f['pass'], PASSWORD_DEFAULT),
        'location' => trim($f['bbs_location']) ?: 'Sysop', 'level' => 255, 'created' => $now, 'today' => date('Y-m-d'), 'baud' => -1,
    ]);
    $settings['sysop_id'] = (string)$sid;
    foreach ($settings as $k => $v) {
        DB::insert('settings', ['name' => $k, 'value' => $v]);
    }

    $mareas = $de
        ? [['Allgemeines', 'Alles, was sonst nirgends hinpasst'], ['Retro-Computer', 'C64, Amiga, DOS und alles mit Diskettenlaufwerk'], ['Mailbox-Szene', 'BBS, FidoNet, Modems und alte Zeiten']]
        : [['General', 'Anything that fits nowhere else'], ['Retro computing', 'C64, Amiga, DOS and everything with a floppy drive'], ['BBS scene', 'BBS, FidoNet, modems and the good old days']];
    $first = 0;
    foreach ($mareas as $i => [$n, $d]) {
        $id = DB::insert('msg_areas', ['name' => $n, 'description' => $d, 'read_level' => 10, 'write_level' => 10, 'sort' => ($i + 1) * 10]);
        $first = $first ?: $id;
    }
    $fareas = $de
        ? [['Mailbox-Software', 'Mailer, BBS-Programme und Tools', 0], ['Utilities', 'Nützliches für DOS und Co.', 0], ['ANSI-Art', 'Bildschirme und Logos', 0], ['Uploads', 'Neue Dateien von Usern', 1]]
        : [['BBS software', 'Mailers, BBS programs and tools', 0], ['Utilities', 'Useful things for DOS and friends', 0], ['ANSI art', 'Screens and logos', 0], ['Uploads', 'New files from users', 1]];
    foreach ($fareas as $i => [$n, $d, $up]) {
        DB::insert('file_areas', ['name' => $n, 'description' => $d, 'dl_level' => 10, 'ul_level' => 10, 'uploads' => $up, 'sort' => ($i + 1) * 10]);
    }

    $menus = [
        'main' => [$de ? 'Hauptmenü' : 'Main menu', [
            ['M', $de ? 'Nachrichten' : 'Messages', 'MENU', 'msg'],
            ['F', $de ? 'Dateien' : 'Files', 'MENU', 'file'],
            ['B', $de ? 'Bulletins' : 'Bulletins', 'MENU', 'bull'],
            ['D', $de ? 'Doors (Spiele)' : 'Doors (games)', 'MENU', 'doors'],
            ['O', $de ? 'Oneliner' : 'Oneliners', 'ONELINERS', ''],
            ['L', $de ? 'Letzte Anrufer' : 'Last callers', 'LASTCALLERS', ''],
            ['W', $de ? 'Wer ist online' : "Who's online", 'WHO', ''],
            ['U', $de ? 'Userliste' : 'User list', 'USERLIST', ''],
            ['Y', $de ? 'Deine Statistik' : 'Your statistics', 'USERINFO', ''],
            ['S', $de ? 'Einstellungen' : 'Settings', 'SETTINGS', ''],
            ['C', $de ? 'Nachricht an den Sysop' : 'Comment to sysop', 'COMMENT', ''],
            ['P', $de ? 'Sysop rufen' : 'Page sysop', 'PAGE', ''],
            ['I', $de ? 'Impressum' : 'Imprint', 'LEGAL', 'impressum'],
            ['X', $de ? 'Datenschutz' : 'Privacy policy', 'LEGAL', 'privacy'],
            ['G', $de ? 'Ausloggen' : 'Goodbye', 'LOGOFF', ''],
            ['!', $de ? 'Sysop-Menü' : 'Sysop menu', 'SYSOP', '', 255],
        ]],
        'msg' => [$de ? 'Nachrichten' : 'Messages', [
            ['A', $de ? 'Bereich wählen' : 'Select area', 'MSG_AREA', ''],
            ['R', $de ? 'Nachrichten lesen' : 'Read messages', 'MSG_READ', ''],
            ['N', $de ? 'Neue Nachrichten (alle Bereiche)' : 'New messages (all areas)', 'MSG_NEW', ''],
            ['E', $de ? 'Nachricht schreiben' : 'Enter a message', 'MSG_POST', ''],
            ['M', $de ? 'Private Post lesen' : 'Read your mail', 'MSG_MAIL', ''],
            ['S', $de ? 'Private Nachricht senden' : 'Send private mail', 'MSG_SEND', ''],
            ['Q', $de ? 'Hauptmenü' : 'Main menu', 'MENU', 'main'],
        ]],
        'file' => [$de ? 'Dateien' : 'Files', [
            ['A', $de ? 'Bereich wählen' : 'Select area', 'FILE_AREA', ''],
            ['L', $de ? 'Dateien auflisten' : 'List files', 'FILE_LIST', ''],
            ['N', $de ? 'Neue Dateien' : 'New files', 'FILE_NEW', ''],
            ['S', $de ? 'Dateien suchen' : 'Search files', 'FILE_SEARCH', ''],
            ['D', 'Download', 'FILE_DOWNLOAD', ''],
            ['U', 'Upload', 'FILE_UPLOAD', ''],
            ['Q', $de ? 'Hauptmenü' : 'Main menu', 'MENU', 'main'],
        ]],
        'bull' => ['Bulletins', [
            ['1', $de ? 'Systeminformationen' : 'System information', 'SCREEN', 'bull1'],
            ['2', $de ? 'Hausregeln' : 'House rules', 'SCREEN', 'rules'],
            ['Q', $de ? 'Hauptmenü' : 'Main menu', 'MENU', 'main'],
        ]],
        'doors' => ['Doors', [
            ['1', $de ? 'Hi-Lo (Zahlenraten)' : 'Hi-Lo (guess the number)', 'DOOR', 'hilo'],
            ['B', $de ? 'Bestenliste' : 'High scores', 'DOORTOP', ''],
            ['Q', $de ? 'Hauptmenü' : 'Main menu', 'MENU', 'main'],
        ]],
    ];
    foreach ($menus as $name => [$title, $items]) {
        $mid = DB::insert('menus', ['name' => $name, 'title' => $title, 'screen' => '', 'min_level' => 0]);
        foreach ($items as $i => $it) {
            DB::insert('menu_items', ['menu_id' => $mid, 'hotkey' => $it[0], 'label' => $it[1], 'command' => $it[2], 'data' => $it[3],
                'min_level' => $it[4] ?? 0, 'sort' => ($i + 1) * 10]);
        }
    }

    DB::insert('messages', [
        'area_id' => $first, 'from_id' => $sid, 'from_name' => $sysop, 'to_id' => 0, 'to_name' => $de ? 'Alle' : 'All',
        'subject' => $de ? 'Die Leitung steht!' : 'The line is open!',
        'body' => $de
            ? "Willkommen in meiner Mailbox!\n\nSchreibt hier rein, was euch bewegt. Dateien findet ihr im Dateimenü, Uploads sind ausdrücklich erwünscht.\n\nViel Spaß, und denkt an die Telefonrechnung. ;-)"
            : "Welcome to my BBS!\n\nWrite whatever is on your mind. You find files in the file menu, uploads are very welcome.\n\nHave fun, and mind the phone bill. ;-)",
        'posted' => $now, 'reply_to' => 0, 'private' => 0, 'is_read' => 0,
    ]);
    DB::insert('oneliners', ['user_id' => $sid, 'handle' => $sysop, 'text' => $de ? 'Die Box ist online!' : 'The board is online!', 'time' => $now]);

    $src = CB_ROOT . '/core/defaults/' . ($de ? 'de' : 'en');
    @mkdir(CB_DATA . '/screens', 0775, true);
    foreach (array_merge(glob($src . '/*.ans') ?: [], glob($src . '/*.txt') ?: []) as $file) {
        $dst = CB_DATA . '/screens/' . basename($file);
        if (!is_file($dst)) {
            copy($file, $dst);
        }
    }
    foreach (['files', 'import', 'tmp'] as $d) {
        @mkdir(CB_DATA . '/' . $d, 0775, true);
    }
}

?><!DOCTYPE html>
<html lang="<?= CB_INSTALL_LANG ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h(it('WebCarrier BBS setup')) ?></title>
<link rel="stylesheet" href="../assets/sysop.css?v=<?= CB_VERSION ?>">
</head>
<body class="setup">
<header class="band"><span class="brand">WebCarrier BBS</span>
  <span class="where"><?= h(it('Setup {1}', CB_VERSION)) ?>
    <span class="langs" aria-label="<?= h(it('Language')) ?>">
      <a href="?lang=de"<?= CB_INSTALL_LANG === 'de' ? ' aria-current="true"' : '' ?>>Deutsch</a>
      <a href="?lang=en"<?= CB_INSTALL_LANG === 'en' ? ' aria-current="true"' : '' ?>>English</a>
    </span>
  </span>
</header>
<main class="page narrow">
<?php if ($locked): ?>
  <h1><?= h(it('Already installed')) ?></h1>
  <p><?= ith('This BBS is already set up. To install again, delete {1} first. You can delete the {2} folder now.', 'core/config.php', 'install') ?></p>
  <p><a class="btn" href="../"><?= h(it('Open the BBS')) ?></a> <a class="btn ghost" href="../sysop/"><?= h(it('Sysop backend')) ?></a></p>
<?php elseif ($done): ?>
  <h1><?= h(it('Your BBS is online')) ?></h1>
  <p><?= h(it('Everything is set up. Log in to the terminal with your sysop handle, or open the backend to fill in imprint and privacy policy before you tell anyone about your box.')) ?></p>
  <p class="note"><?= ith('Delete the {1} folder from your webspace now. It refuses to run again anyway, but there is no reason to keep it online.', 'install') ?></p>
  <p><a class="btn" href="../"><?= h(it('Dial in')) ?></a> <a class="btn ghost" href="../sysop/"><?= h(it('Sysop backend')) ?></a></p>
<?php else: ?>
  <h1><?= h(it('Set up your BBS')) ?></h1>
  <section class="panel">
    <h2><?= h(it('Server check')) ?></h2>
    <ul class="checks">
      <?php foreach ($checks as [$label, $ok, $required]): ?>
        <li class="<?= $ok ? 'ok' : ($required ? 'bad' : 'warn') ?>"><?= h($label) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php if ($errors): ?>
    <div class="flash bad"><?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div>
  <?php endif; ?>
  <form method="post" action="?lang=<?= CB_INSTALL_LANG ?>" class="panel form" autocomplete="off">
    <h2><?= h(it('Your box')) ?></h2>
    <label><?= h(it('Name of the BBS')) ?><input name="bbs_name" required maxlength="60" value="<?= h($f['bbs_name']) ?>"></label>
    <label><?= h(it('Location')) ?><input name="bbs_location" maxlength="40" value="<?= h($f['bbs_location']) ?>"></label>
    <label><?= h(it('Language of the BBS')) ?>
      <select name="bbs_lang">
        <option value="de"<?= $f['lang'] === 'de' ? ' selected' : '' ?>>Deutsch</option>
        <option value="en"<?= $f['lang'] === 'en' ? ' selected' : '' ?>>English</option>
      </select>
    </label>

    <h2><?= h(it('Sysop account')) ?></h2>
    <label><?= h(it('Sysop handle')) ?><input name="sysop" required maxlength="20" value="<?= h($f['sysop']) ?>"></label>
    <label><?= h(it('Password (at least 8 characters)')) ?><input type="password" name="pass" required minlength="8"></label>
    <label><?= h(it('Repeat password')) ?><input type="password" name="pass2" required minlength="8"></label>

    <h2><?= h(it('Database')) ?></h2>
    <fieldset class="choice">
      <?php if (in_array('sqlite', $drivers, true)): ?>
        <label class="inline"><input type="radio" name="driver" value="sqlite"<?= $f['driver'] === 'sqlite' ? ' checked' : '' ?>> <?= h(it('SQLite (one file in data/, nothing to configure)')) ?></label>
      <?php endif; ?>
      <?php if (in_array('mysql', $drivers, true)): ?>
        <label class="inline"><input type="radio" name="driver" value="mysql"<?= $f['driver'] === 'mysql' ? ' checked' : '' ?>> <?= h(it('MySQL or MariaDB')) ?></label>
      <?php endif; ?>
    </fieldset>
    <div class="mysql">
      <label><?= h(it('Host')) ?><input name="host" value="<?= h($f['host']) ?>"></label>
      <label><?= h(it('Port (empty = default)')) ?><input name="port" value="<?= h($f['port']) ?>"></label>
      <label><?= h(it('Database name')) ?><input name="dbname" value="<?= h($f['dbname']) ?>"></label>
      <label><?= h(it('User')) ?><input name="dbuser" value="<?= h($f['dbuser']) ?>"></label>
      <label><?= h(it('Password')) ?><input type="password" name="dbpass" value=""></label>
    </div>
    <label><?= h(it('Table prefix')) ?><input name="prefix" value="<?= h($f['prefix']) ?>" maxlength="12"></label>
    <p><button class="btn" type="submit"><?= h(it('Install')) ?></button></p>
  </form>
<?php endif; ?>
</main>
<?= cb_credit(it('Source code')) ?>
</body>
</html>
