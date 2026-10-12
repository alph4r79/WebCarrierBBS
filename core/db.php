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
            // LOWER() in SQLite only knows ASCII, cb_lower() also umlauts
            if (method_exists(self::$pdo, 'sqliteCreateFunction')) {
                self::$pdo->sqliteCreateFunction('cb_lower', static fn($s) => mb_strtolower((string)$s), 1);
            }
        }
    }

    /** Lower case function for case-insensitive search with umlauts on both databases. */
    public static function lower(string $expr): string
    {
        return (self::$driver === 'mysql' || !method_exists(self::$pdo, 'sqliteCreateFunction') ? 'LOWER(' : 'cb_lower(') . $expr . ')';
    }

    /** Integer bucket of a timestamp column ($size seconds), the same on SQLite and MySQL. */
    public static function bucket(string $col, int $size): string
    {
        return self::$driver === 'mysql' ? "($col DIV $size)" : "($col / $size)";
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
            "CREATE TABLE {settings} (name VARCHAR(64) NOT NULL PRIMARY KEY, value " . ($my ? 'MEDIUMTEXT' : 'TEXT') . ")",
            "CREATE TABLE {levels} (level INT NOT NULL PRIMARY KEY, name VARCHAR(40) NOT NULL DEFAULT '',
                minutes INT NOT NULL DEFAULT 60, dl_kb INT NOT NULL DEFAULT 0, ratio INT NOT NULL DEFAULT 0)",
            "CREATE TABLE {users} (id $pk, handle VARCHAR(30) NOT NULL, handle_lc VARCHAR(30) NOT NULL,
                pass VARCHAR(255) NOT NULL, location VARCHAR(40) NOT NULL DEFAULT '', level INT NOT NULL DEFAULT 10,
                calls INT NOT NULL DEFAULT 0, last_call BIGINT NOT NULL DEFAULT 0, created BIGINT NOT NULL DEFAULT 0,
                ul_files INT NOT NULL DEFAULT 0, ul_kb INT NOT NULL DEFAULT 0, dl_files INT NOT NULL DEFAULT 0,
                dl_kb INT NOT NULL DEFAULT 0, dl_kb_today INT NOT NULL DEFAULT 0, time_today INT NOT NULL DEFAULT 0,
                today VARCHAR(10) NOT NULL DEFAULT '', posts INT NOT NULL DEFAULT 0, expert INT NOT NULL DEFAULT 0,
                baud INT NOT NULL DEFAULT -1, locked INT NOT NULL DEFAULT 0, note VARCHAR(255) NOT NULL DEFAULT '',
                pending INT NOT NULL DEFAULT 0, allow_msgs INT NOT NULL DEFAULT 1, about VARCHAR(200) NOT NULL DEFAULT '',
                signature VARCHAR(150) NOT NULL DEFAULT '', no_auto INT NOT NULL DEFAULT 0, totp_secret VARCHAR(40) NOT NULL DEFAULT '',
                totp_on INT NOT NULL DEFAULT 0, totp_last BIGINT NOT NULL DEFAULT 0, crt INT NOT NULL DEFAULT 1)",
            "CREATE UNIQUE INDEX {users}_handle ON {users} (handle_lc)",
            "CREATE INDEX {users}_created ON {users} (created)",
            "CREATE TABLE {msg_areas} (id $pk, name VARCHAR(60) NOT NULL, description VARCHAR(120) NOT NULL DEFAULT '',
                read_level INT NOT NULL DEFAULT 10, write_level INT NOT NULL DEFAULT 10, sort INT NOT NULL DEFAULT 0)",
            "CREATE TABLE {messages} (id $pk, area_id INT NOT NULL DEFAULT 0, from_id INT NOT NULL DEFAULT 0,
                from_name VARCHAR(30) NOT NULL DEFAULT '', to_id INT NOT NULL DEFAULT 0, to_name VARCHAR(30) NOT NULL DEFAULT '',
                subject VARCHAR(80) NOT NULL DEFAULT '', body TEXT, posted BIGINT NOT NULL DEFAULT 0,
                reply_to INT NOT NULL DEFAULT 0, private INT NOT NULL DEFAULT 0, is_read INT NOT NULL DEFAULT 0,
                msgid VARCHAR(64) NOT NULL DEFAULT '', origin VARCHAR(32) NOT NULL DEFAULT '', reply_msgid VARCHAR(64) NOT NULL DEFAULT '')",
            "CREATE INDEX {messages}_area ON {messages} (area_id, id)",
            "CREATE INDEX {messages}_to ON {messages} (to_id, private)",
            "CREATE INDEX {messages}_posted ON {messages} (posted)",
            "CREATE UNIQUE INDEX {messages}_msgid ON {messages} (msgid)",
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
            "CREATE INDEX {files}_added ON {files} (added)",
            "CREATE TABLE {menus} (id $pk, name VARCHAR(20) NOT NULL, title VARCHAR(60) NOT NULL DEFAULT '',
                screen VARCHAR(40) NOT NULL DEFAULT '', min_level INT NOT NULL DEFAULT 0)",
            "CREATE UNIQUE INDEX {menus}_name ON {menus} (name)",
            "CREATE TABLE {menu_items} (id $pk, menu_id INT NOT NULL, hotkey VARCHAR(1) NOT NULL, label VARCHAR(40) NOT NULL DEFAULT '',
                command VARCHAR(20) NOT NULL, data VARCHAR(60) NOT NULL DEFAULT '', min_level INT NOT NULL DEFAULT 0,
                sort INT NOT NULL DEFAULT 0)",
            "CREATE TABLE {calls} (id $pk, user_id INT NOT NULL, handle VARCHAR(30) NOT NULL DEFAULT '',
                location VARCHAR(40) NOT NULL DEFAULT '', node INT NOT NULL DEFAULT 1, time BIGINT NOT NULL DEFAULT 0)",
            "CREATE INDEX {calls}_time ON {calls} (time)",
            "CREATE TABLE {nodes} (node INT NOT NULL PRIMARY KEY, sid VARCHAR(128) NOT NULL DEFAULT '', user_id INT NOT NULL DEFAULT 0,
                handle VARCHAR(30) NOT NULL DEFAULT '', activity VARCHAR(60) NOT NULL DEFAULT '', since BIGINT NOT NULL DEFAULT 0,
                seen BIGINT NOT NULL DEFAULT 0, kicked INT NOT NULL DEFAULT 0, tele INT NOT NULL DEFAULT 0)",
            "CREATE TABLE {node_msgs} (id $pk, node INT NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT '', from_node INT NOT NULL DEFAULT 0,
                from_handle VARCHAR(30) NOT NULL DEFAULT '', text VARCHAR(255) NOT NULL DEFAULT '', time BIGINT NOT NULL DEFAULT 0)",
            "CREATE INDEX {node_msgs}_node ON {node_msgs} (node)",
            "CREATE TABLE {oneliners} (id $pk, user_id INT NOT NULL DEFAULT 0, handle VARCHAR(30) NOT NULL DEFAULT '',
                text VARCHAR(80) NOT NULL DEFAULT '', time BIGINT NOT NULL DEFAULT 0)",
            "CREATE INDEX {oneliners}_time ON {oneliners} (time)",
            "CREATE TABLE {door_data} (door VARCHAR(30) NOT NULL, user_id INT NOT NULL, k VARCHAR(30) NOT NULL, v TEXT,
                PRIMARY KEY (door, user_id, k))",
            "CREATE TABLE {door_scores} (door VARCHAR(30) NOT NULL, user_id INT NOT NULL, value BIGINT NOT NULL DEFAULT 0,
                label VARCHAR(20) NOT NULL DEFAULT '', time BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (door, user_id))",
            "CREATE INDEX {door_scores}_value ON {door_scores} (door, value)",
            "CREATE INDEX {door_scores}_time ON {door_scores} (time)",
            "CREATE TABLE {tele_lines} (id $pk, user_id INT NOT NULL DEFAULT 0, handle VARCHAR(30) NOT NULL DEFAULT '',
                text VARCHAR(120) NOT NULL DEFAULT '', time BIGINT NOT NULL DEFAULT 0)",
            "CREATE TABLE {polls} (id $pk, question VARCHAR(80) NOT NULL DEFAULT '', created BIGINT NOT NULL DEFAULT 0,
                closes VARCHAR(10) NOT NULL DEFAULT '', closed INT NOT NULL DEFAULT 0, created_by INT NOT NULL DEFAULT 0)",
            "CREATE INDEX {polls}_created ON {polls} (created)",
            "CREATE TABLE {poll_options} (id $pk, poll_id INT NOT NULL, text VARCHAR(50) NOT NULL DEFAULT '', sort INT NOT NULL DEFAULT 0)",
            "CREATE INDEX {poll_options}_poll ON {poll_options} (poll_id)",
            "CREATE TABLE {poll_votes} (poll_id INT NOT NULL, user_id INT NOT NULL, option_id INT NOT NULL, time BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (poll_id, user_id))",
            "CREATE TABLE {nu_questions} (id $pk, sort INT NOT NULL DEFAULT 0, text VARCHAR(70) NOT NULL DEFAULT '',
                type VARCHAR(10) NOT NULL DEFAULT 'text', options TEXT, maxlen INT NOT NULL DEFAULT 40,
                required INT NOT NULL DEFAULT 0, active INT NOT NULL DEFAULT 1)",
            "CREATE TABLE {nu_answers} (id $pk, user_id INT NOT NULL, question_id INT NOT NULL DEFAULT 0,
                question VARCHAR(70) NOT NULL DEFAULT '', answer VARCHAR(60) NOT NULL DEFAULT '', time BIGINT NOT NULL DEFAULT 0)",
            "CREATE INDEX {nu_answers}_user ON {nu_answers} (user_id)",
            "CREATE TABLE {level_rules} (id $pk, from_level INT NOT NULL DEFAULT 0, to_level INT NOT NULL DEFAULT 0,
                min_calls INT NOT NULL DEFAULT 0, min_days INT NOT NULL DEFAULT 0, min_posts INT NOT NULL DEFAULT 0,
                min_uploads INT NOT NULL DEFAULT 0, active INT NOT NULL DEFAULT 1)",
            "CREATE TABLE {totp_codes} (id $pk, user_id INT NOT NULL, hash VARCHAR(255) NOT NULL DEFAULT '', used INT NOT NULL DEFAULT 0)",
            "CREATE INDEX {totp_codes}_user ON {totp_codes} (user_id)",
            "CREATE TABLE {log} (id $pk, time BIGINT NOT NULL DEFAULT 0, user_id INT NOT NULL DEFAULT 0,
                handle VARCHAR(30) NOT NULL DEFAULT '', text VARCHAR(255) NOT NULL DEFAULT '')",
            "CREATE INDEX {log}_time ON {log} (time)",
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
