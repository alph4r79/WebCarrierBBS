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

define('CB_DB_VERSION', 3);

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
