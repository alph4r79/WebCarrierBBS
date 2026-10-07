<?php
/**
 * WebCarrier BBS: database layer.
 * Works with MySQL/MariaDB and SQLite through PDO.
 * Table names are written as {name} and get the configured prefix.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

final class DB
{
    public static ?PDO $pdo = null;
    public static string $prefix = 'cb_';
    public static string $driver = 'sqlite';
    /** Absolute path of the SQLite database, '' for MySQL. */
    public static string $file = '';

    public static function connect(array $c): void
    {
        self::$prefix = (string)($c['prefix'] ?? 'cb_');
        self::$driver = (string)($c['driver'] ?? 'sqlite');
        $opt = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (self::$driver === 'mysql') {
            $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
            if (!empty($c['port'])) {
                $dsn .= ';port=' . (int)$c['port'];
            }
            self::$pdo = new PDO($dsn, (string)$c['user'], (string)$c['pass'], $opt);
        } else {
            $file = (string)$c['file'];
            if ($file !== '' && $file[0] !== '/' && !preg_match('~^[A-Za-z]:~', $file)) {
                $file = CB_ROOT . '/' . $file;
            }
            self::$file = $file;
            self::$pdo = new PDO('sqlite:' . $file, null, null, $opt);
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA foreign_keys=OFF');
            self::$pdo->exec('PRAGMA busy_timeout=5000');
        }
    }

    public static function sql(string $q): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', static fn($m) => self::$prefix . $m[1], $q);
    }

    public static function q(string $q, array $p = []): PDOStatement
    {
        $st = self::$pdo->prepare(self::sql($q));
        $st->execute($p);
        return $st;
    }

    public static function row(string $q, array $p = []): ?array
    {
        $r = self::q($q, $p)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $q, array $p = []): array
    {
        return self::q($q, $p)->fetchAll();
    }

    public static function val(string $q, array $p = [])
    {
        $r = self::q($q, $p)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $q = 'INSERT INTO {' . $table . '} (' . implode(',', $cols) . ') VALUES (' .
            implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($q, array_values($data));
        return (int)self::$pdo->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $wp = []): int
    {
        $set = [];
        foreach (array_keys($data) as $c) {
            $set[] = $c . '=?';
        }
        $q = 'UPDATE {' . $table . '} SET ' . implode(',', $set) . ' WHERE ' . $where;
        return self::q($q, array_merge(array_values($data), $wp))->rowCount();
    }

    /** Statements of the schema for the active driver. */
    public static function schema(): array
    {
        $my = self::$driver === 'mysql';
        $pk = $my ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $opt = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $t = [
            "CREATE TABLE {settings} (name VARCHAR(64) NOT NULL PRIMARY KEY, value TEXT)",
            "CREATE TABLE {levels} (level INT NOT NULL PRIMARY KEY, name VARCHAR(40) NOT NULL DEFAULT '',
                minutes INT NOT NULL DEFAULT 60, dl_kb INT NOT NULL DEFAULT 0, ratio INT NOT NULL DEFAULT 0)",
            "CREATE TABLE {users} (id $pk, handle VARCHAR(30) NOT NULL, handle_lc VARCHAR(30) NOT NULL,
                pass VARCHAR(255) NOT NULL, location VARCHAR(40) NOT NULL DEFAULT '', level INT NOT NULL DEFAULT 10,
                calls INT NOT NULL DEFAULT 0, last_call BIGINT NOT NULL DEFAULT 0, created BIGINT NOT NULL DEFAULT 0,
                ul_files INT NOT NULL DEFAULT 0, ul_kb INT NOT NULL DEFAULT 0, dl_files INT NOT NULL DEFAULT 0,
                dl_kb INT NOT NULL DEFAULT 0, dl_kb_today INT NOT NULL DEFAULT 0, time_today INT NOT NULL DEFAULT 0,
                today VARCHAR(10) NOT NULL DEFAULT '', posts INT NOT NULL DEFAULT 0, expert INT NOT NULL DEFAULT 0,
                baud INT NOT NULL DEFAULT -1, locked INT NOT NULL DEFAULT 0, note VARCHAR(255) NOT NULL DEFAULT '')",
            "CREATE UNIQUE INDEX {users}_handle ON {users} (handle_lc)",
            "CREATE TABLE {msg_areas} (id $pk, name VARCHAR(60) NOT NULL, description VARCHAR(120) NOT NULL DEFAULT '',
                read_level INT NOT NULL DEFAULT 10, write_level INT NOT NULL DEFAULT 10, sort INT NOT NULL DEFAULT 0)",
            "CREATE TABLE {messages} (id $pk, area_id INT NOT NULL DEFAULT 0, from_id INT NOT NULL DEFAULT 0,
                from_name VARCHAR(30) NOT NULL DEFAULT '', to_id INT NOT NULL DEFAULT 0, to_name VARCHAR(30) NOT NULL DEFAULT '',
                subject VARCHAR(80) NOT NULL DEFAULT '', body TEXT, posted BIGINT NOT NULL DEFAULT 0,
                reply_to INT NOT NULL DEFAULT 0, private INT NOT NULL DEFAULT 0, is_read INT NOT NULL DEFAULT 0)",
            "CREATE INDEX {messages}_area ON {messages} (area_id, id)",
            "CREATE INDEX {messages}_to ON {messages} (to_id, private)",
            "CREATE TABLE {lastread} (user_id INT NOT NULL, area_id INT NOT NULL, msg_id INT NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, area_id))",
            "CREATE TABLE {file_areas} (id $pk, name VARCHAR(60) NOT NULL, description VARCHAR(120) NOT NULL DEFAULT '',
                dl_level INT NOT NULL DEFAULT 10, ul_level INT NOT NULL DEFAULT 10, uploads INT NOT NULL DEFAULT 0,
                sort INT NOT NULL DEFAULT 0)",
            "CREATE TABLE {files} (id $pk, area_id INT NOT NULL, filename VARCHAR(120) NOT NULL, size BIGINT NOT NULL DEFAULT 0,
                description TEXT, uploader_id INT NOT NULL DEFAULT 0, uploader VARCHAR(30) NOT NULL DEFAULT '',
                added BIGINT NOT NULL DEFAULT 0, downloads INT NOT NULL DEFAULT 0, approved INT NOT NULL DEFAULT 1,
                storage VARCHAR(255) NOT NULL DEFAULT '')",
            "CREATE INDEX {files}_area ON {files} (area_id, filename)",
            "CREATE TABLE {menus} (id $pk, name VARCHAR(20) NOT NULL, title VARCHAR(60) NOT NULL DEFAULT '',
                screen VARCHAR(40) NOT NULL DEFAULT '', min_level INT NOT NULL DEFAULT 0)",
            "CREATE UNIQUE INDEX {menus}_name ON {menus} (name)",
            "CREATE TABLE {menu_items} (id $pk, menu_id INT NOT NULL, hotkey VARCHAR(1) NOT NULL, label VARCHAR(40) NOT NULL DEFAULT '',
                command VARCHAR(20) NOT NULL, data VARCHAR(60) NOT NULL DEFAULT '', min_level INT NOT NULL DEFAULT 0,
                sort INT NOT NULL DEFAULT 0)",
            "CREATE TABLE {calls} (id $pk, user_id INT NOT NULL, handle VARCHAR(30) NOT NULL DEFAULT '',
                location VARCHAR(40) NOT NULL DEFAULT '', node INT NOT NULL DEFAULT 1, time BIGINT NOT NULL DEFAULT 0)",
            "CREATE TABLE {nodes} (node INT NOT NULL PRIMARY KEY, sid VARCHAR(128) NOT NULL DEFAULT '', user_id INT NOT NULL DEFAULT 0,
                handle VARCHAR(30) NOT NULL DEFAULT '', activity VARCHAR(60) NOT NULL DEFAULT '', since BIGINT NOT NULL DEFAULT 0,
                seen BIGINT NOT NULL DEFAULT 0)",
            "CREATE TABLE {oneliners} (id $pk, user_id INT NOT NULL DEFAULT 0, handle VARCHAR(30) NOT NULL DEFAULT '',
                text VARCHAR(80) NOT NULL DEFAULT '', time BIGINT NOT NULL DEFAULT 0)",
            "CREATE TABLE {door_data} (door VARCHAR(30) NOT NULL, user_id INT NOT NULL, k VARCHAR(30) NOT NULL, v TEXT,
                PRIMARY KEY (door, user_id, k))",
            "CREATE TABLE {log} (id $pk, time BIGINT NOT NULL DEFAULT 0, user_id INT NOT NULL DEFAULT 0,
                handle VARCHAR(30) NOT NULL DEFAULT '', text VARCHAR(255) NOT NULL DEFAULT '')",
        ];
        return array_map(static function ($s) use ($opt) {
            $s = preg_replace('/\s+/', ' ', $s);
            return str_starts_with($s, 'CREATE TABLE') ? $s . $opt : $s;
        }, $t);
    }
}

final class Settings
{
    private static ?array $c = null;

    public static function all(): array
    {
        if (self::$c === null) {
            self::$c = [];
            foreach (DB::all('SELECT name, value FROM {settings}') as $r) {
                self::$c[$r['name']] = (string)$r['value'];
            }
        }
        return self::$c;
    }

    public static function get(string $k, string $def = ''): string
    {
        $a = self::all();
        return array_key_exists($k, $a) ? $a[$k] : $def;
    }

    public static function int(string $k, int $def = 0): int
    {
        $v = self::get($k, (string)$def);
        return is_numeric($v) ? (int)$v : $def;
    }

    public static function set(string $k, string $v): void
    {
        if (DB::val('SELECT COUNT(*) FROM {settings} WHERE name=?', [$k])) {
            DB::q('UPDATE {settings} SET value=? WHERE name=?', [$v, $k]);
        } else {
            DB::q('INSERT INTO {settings} (name, value) VALUES (?,?)', [$k, $v]);
        }
        if (self::$c !== null) {
            self::$c[$k] = $v;
        }
    }
}
