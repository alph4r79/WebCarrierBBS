<?php
/**
 * WebCarrier BBS: database migrations.
 * cb_migrate_N() brings the database from version N-1 to N. New installations get the
 * current schema from DB::schema() and start at CB_DB_VERSION, so every schema change
 * goes into both places.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

define('CB_DB_VERSION', 9);

/** Run all pending steps. Costs only the (cached) settings lookup when up to date. */
function cb_migrate(): void
{
    if (Settings::int('db_version', 1) >= CB_DB_VERSION) {
        return;
    }
    // serialize concurrent requests right after an update
    $lock = is_dir(CB_DATA . '/tmp') ? @fopen(CB_DATA . '/tmp/migrate.lock', 'c') : false;
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    try {
        // re-read uncached, another request may have migrated while we waited
        $v = DB::val("SELECT value FROM {settings} WHERE name='db_version'");
        $v = is_numeric($v) ? (int)$v : 1;
        for ($n = $v + 1; $n <= CB_DB_VERSION; $n++) {
            $fn = 'cb_migrate_' . $n;
            if (!function_exists($fn)) {
                throw new RuntimeException("migration step $n missing");
            }
            $fn();
            Settings::set('db_version', (string)$n);
        }
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

function cb_column_exists(string $table, string $column): bool
{
    if (DB::$driver === 'mysql') {
        return (bool)DB::val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            [DB::$prefix . $table, $column]);
    }
    foreach (DB::all('PRAGMA table_info({' . $table . '})') as $c) {
        if ($c['name'] === $column) {
            return true;
        }
    }
    return false;
}

/**
 * Add a column unless it exists (an aborted run can be repeated).
 * SQLite needs a DEFAULT for NOT NULL columns, e.g. "INT NOT NULL DEFAULT 0".
 */
function cb_add_column(string $table, string $column, string $definition): void
{
    if (!cb_column_exists($table, $column)) {
        DB::q('ALTER TABLE {' . $table . '} ADD COLUMN ' . $column . ' ' . $definition);
    }
}

function cb_table_exists(string $table): bool
{
    if (DB::$driver === 'mysql') {
        return (bool)DB::val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [DB::$prefix . $table]);
    }
    return (bool)DB::val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?", [DB::$prefix . $table]);
}

/** Create a table and its indexes from DB::schema() unless it exists. */
function cb_create_table(string $table): void
{
    if (cb_table_exists($table)) {
        return;
    }
    foreach (DB::schema() as $sql) {
        if (preg_match('/^CREATE (TABLE \{' . $table . '\} |(UNIQUE )?INDEX \S+ ON \{' . $table . '\} )/', $sql)) {
            DB::$pdo->exec(DB::sql($sql));
        }
    }
}

/** Copy a screen from core/defaults (language of the board) unless data/screens has one with that name. */
function cb_copy_default_screen(string $name): void
{
    if (glob(CB_DATA . '/screens/' . $name . '.*')) {
        return;
    }
    $lang = Settings::get('language', 'en') === 'de' ? 'de' : 'en';
    foreach (['ans', 'txt'] as $ext) {
        $src = CB_ROOT . "/core/defaults/$lang/$name.$ext";
        if (is_file($src)) {
            if (!is_dir(CB_DATA . '/screens')) {
                @mkdir(CB_DATA . '/screens', 0775, true);
            }
            @copy($src, CB_DATA . "/screens/$name.$ext");
            return;
        }
    }
}

/* ------------------------------------------------------------------ steps */

/** 2: no schema change, introduces db_version. */
function cb_migrate_2(): void
{
}

/** 3: update check, switched off for existing installations too. */
function cb_migrate_3(): void
{
    if (DB::val("SELECT COUNT(*) FROM {settings} WHERE name='update_check'") == 0) {
        Settings::set('update_check', '0');
    }
}

/** 4: sysop menu in the terminal, kick, chat and broadcast (nodes.kicked, node_msgs), validation of new users. */
function cb_migrate_4(): void
{
    cb_add_column('users', 'pending', 'INT NOT NULL DEFAULT 0');
    cb_add_column('nodes', 'kicked', 'INT NOT NULL DEFAULT 0');
    cb_create_table('node_msgs');
    if (DB::val("SELECT COUNT(*) FROM {settings} WHERE name='new_validate'") == 0) {
        Settings::set('new_validate', '0');
    }
    $main = (int)DB::val("SELECT id FROM {menus} WHERE name='main'");
    if ($main > 0 && !DB::val("SELECT COUNT(*) FROM {menu_items} WHERE menu_id=? AND command='SYSOP'", [$main])) {
        DB::insert('menu_items', ['menu_id' => $main, 'hotkey' => '!', 'label' => Settings::get('language', 'en') === 'de' ? 'Sysop-Menü' : 'Sysop menu',
            'command' => 'SYSOP', 'data' => '', 'min_level' => Settings::int('sysop_level', 255),
            'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+10 FROM {menu_items} WHERE menu_id=?', [$main])]);
    }
    cb_copy_default_screen('pending');
}

/** 5: settings.value as MEDIUMTEXT on MySQL (TEXT ends at 64 KB, too small for long legal texts and own texts). */
function cb_migrate_5(): void
{
    if (DB::$driver === 'mysql' && strtolower((string)DB::val('SELECT DATA_TYPE FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', [DB::$prefix . 'settings', 'value'])) !== 'mediumtext') {
        DB::q('ALTER TABLE {settings} MODIFY value MEDIUMTEXT');
    }
}

/** 6: high score lists of the doors (door_scores) and the item DOORTOP in the menu doors. */
function cb_migrate_6(): void
{
    cb_create_table('door_scores');
    $menu = (int)DB::val("SELECT id FROM {menus} WHERE name='doors'");
    if ($menu <= 0 || DB::val("SELECT COUNT(*) FROM {menu_items} WHERE command='DOORTOP'")) {
        return;
    }
    cb_add_doortop_item($menu);
}

/**
 * Menu item for a new command: only if the menu exists, the command is not in it yet and there is
 * a free key ($key, otherwise the next free one of A-Z without Q and G). Sorted in before Q or G.
 */
function cb_add_menu_command(string $menuName, string $cmd, string $key, string $de, string $en, int $minLevel = 0): void
{
    $menu = (int)DB::val('SELECT id FROM {menus} WHERE name=?', [$menuName]);
    if ($menu <= 0 || DB::val('SELECT COUNT(*) FROM {menu_items} WHERE menu_id=? AND command=?', [$menu, $cmd])) {
        return;
    }
    $used = array_map('mb_strtoupper', array_column(DB::all('SELECT hotkey FROM {menu_items} WHERE menu_id=?', [$menu]), 'hotkey'));
    $free = null;
    foreach (array_merge([$key], array_diff(range('A', 'Z'), [$key, 'Q', 'G'])) as $k) {
        if (!in_array($k, $used, true)) {
            $free = $k;
            break;
        }
    }
    if ($free === null) {
        return;
    }
    $end = DB::row("SELECT sort FROM {menu_items} WHERE menu_id=? AND UPPER(hotkey) IN ('Q', 'G') ORDER BY sort, id", [$menu]);
    if ($end) {
        $sort = (int)$end['sort'];
        DB::q('UPDATE {menu_items} SET sort=sort+10 WHERE menu_id=? AND sort>=?', [$menu, $sort]);
    } else {
        $sort = (int)DB::val('SELECT COALESCE(MAX(sort),0)+10 FROM {menu_items} WHERE menu_id=?', [$menu]);
    }
    DB::insert('menu_items', ['menu_id' => $menu, 'hotkey' => $free, 'label' => Settings::get('language', 'en') === 'de' ? $de : $en,
        'command' => $cmd, 'data' => '', 'min_level' => $minLevel, 'sort' => $sort]);
}

/** 7: meeting point, node messages, profiles and signatures, polls; indexes for the statistics. */
function cb_migrate_7(): void
{
    cb_add_column('users', 'allow_msgs', 'INT NOT NULL DEFAULT 1');
    cb_add_column('users', 'about', "VARCHAR(200) NOT NULL DEFAULT ''");
    cb_add_column('users', 'signature', "VARCHAR(150) NOT NULL DEFAULT ''");
    cb_add_column('nodes', 'tele', 'INT NOT NULL DEFAULT 0');
    foreach (['tele_lines', 'polls', 'poll_options', 'poll_votes'] as $t) {
        cb_create_table($t);
    }
    foreach (['calls', 'log'] as $t) {
        if (!cb_index_exists($t, $t . '_time')) {
            DB::q('CREATE INDEX {' . $t . '}_time ON {' . $t . '} (time)');
        }
    }
    cb_add_menu_command('main', 'TELECONF', 'T', 'Treffpunkt', 'Meeting point', Settings::int('new_level', 10));
    cb_add_menu_command('main', 'POLLS', 'V', 'Umfragen', 'Polls');
    cb_add_menu_command('msg', 'MSG_SEARCH', 'F', 'Suchen', 'Find');
}

/**
 * 8: what is new since the last call (indexes on the time columns), questionnaire, automatic promotion,
 * second factor, tube effect, id of the board and of every message.
 */
function cb_migrate_8(): void
{
    cb_add_column('users', 'no_auto', 'INT NOT NULL DEFAULT 0');
    cb_add_column('users', 'totp_secret', "VARCHAR(40) NOT NULL DEFAULT ''");
    cb_add_column('users', 'totp_on', 'INT NOT NULL DEFAULT 0');
    cb_add_column('users', 'totp_last', 'BIGINT NOT NULL DEFAULT 0');
    cb_add_column('users', 'crt', 'INT NOT NULL DEFAULT 1');
    cb_add_column('messages', 'msgid', "VARCHAR(64) NOT NULL DEFAULT ''");
    cb_add_column('messages', 'origin', "VARCHAR(32) NOT NULL DEFAULT ''");
    cb_add_column('messages', 'reply_msgid', "VARCHAR(64) NOT NULL DEFAULT ''");
    foreach (['nu_questions', 'nu_answers', 'level_rules', 'totp_codes'] as $t) {
        cb_create_table($t);
    }
    foreach ([['users', 'created'], ['messages', 'posted'], ['files', 'added'], ['oneliners', 'time'], ['door_scores', 'time'], ['polls', 'created']] as [$t, $c]) {
        if (!cb_index_exists($t, $t . '_' . $c)) {
            DB::q('CREATE INDEX {' . $t . '}_' . $c . ' ON {' . $t . '} (' . $c . ')');
        }
    }
    foreach (['newsince' => '1', 'nu_questions_on' => '0', 'auto_level' => '0', 'tab_notify' => '1', 'crt' => 'off',
                 'wansidraw_url' => CB_WANSIDRAW_URL] as $k => $v) {
        if (DB::val('SELECT COUNT(*) FROM {settings} WHERE name=?', [$k]) == 0) {
            Settings::set($k, $v);
        }
    }
    cb_migrate_msgids();
    if (!cb_index_exists('messages', 'messages_msgid')) {
        DB::q('CREATE UNIQUE INDEX {messages}_msgid ON {messages} (msgid)');
    }
}

/**
 * Ids for the messages written before version 1.8.0, 500 per step and transaction. An aborted run
 * (time limit) continues with the next request, rows that have an id are left alone.
 */
function cb_migrate_msgids(): void
{
    $box = cb_box_id();
    do {
        $ids = array_column(DB::all("SELECT id FROM {messages} WHERE msgid='' ORDER BY id LIMIT 500"), 'id');
        DB::$pdo->beginTransaction();
        foreach ($ids as $id) {
            DB::q('UPDATE {messages} SET msgid=?, origin=? WHERE id=?', [cb_new_msgid(), $box, (int)$id]);
        }
        DB::$pdo->commit();
    } while (count($ids) === 500);
    $last = 0;
    do {
        $rows = DB::all("SELECT m.id, p.msgid FROM {messages} m LEFT JOIN {messages} p ON p.id=m.reply_to
            WHERE m.id>? AND m.reply_to>0 AND m.reply_msgid='' ORDER BY m.id LIMIT 500", [$last]);
        DB::$pdo->beginTransaction();
        foreach ($rows as $r) {
            $last = (int)$r['id'];
            if ((string)$r['msgid'] !== '') {
                DB::q('UPDATE {messages} SET reply_msgid=? WHERE id=?', [(string)$r['msgid'], $last]);
            }
        }
        DB::$pdo->commit();
    } while (count($rows) === 500);
}

/** 9: roles in the backend, reachability and visits of backend accounts, calls to the sysop, reports, end of a call. */
function cb_migrate_9(): void
{
    cb_add_column('levels', 'backend', "VARCHAR(10) NOT NULL DEFAULT 'none'");
    cb_add_column('users', 'backend_seen', 'BIGINT NOT NULL DEFAULT 0');
    cb_add_column('users', 'backend_visit', 'BIGINT NOT NULL DEFAULT 0');
    cb_add_column('users', 'backend_prev', 'BIGINT NOT NULL DEFAULT 0');
    cb_add_column('calls', 'ended', 'BIGINT NOT NULL DEFAULT 0');
    cb_add_column('menus', 'head_screen', "VARCHAR(40) NOT NULL DEFAULT ''");
    cb_add_column('menus', 'head_bar', 'INT NOT NULL DEFAULT 1');
    foreach (['sysop_pages', 'msg_reports'] as $t) {
        cb_create_table($t);
    }
}

function cb_index_exists(string $table, string $name): bool
{
    if (DB::$driver === 'mysql') {
        return (bool)DB::val('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
            [DB::$prefix . $table, DB::$prefix . $name]);
    }
    return (bool)DB::val("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name=?", [DB::$prefix . $name]);
}
