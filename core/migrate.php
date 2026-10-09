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

define('CB_DB_VERSION', 5);

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
