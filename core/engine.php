<?php
/**
 * WebCarrier BBS: the mailbox engine.
 *
 * Every request from the terminal carries one input (a key, a line or an edited
 * message). The engine looks at the current state stored in the session, runs the
 * matching i_<state>() handler, writes ANSI output and tells the terminal what kind
 * of input it expects next (hot key, line, editor, upload).
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require __DIR__ . '/engine_msg.php';
require __DIR__ . '/engine_files.php';
require __DIR__ . '/engine_misc.php';

final class Engine
{
    use EngineMessages;
    use EngineFiles;
    use EngineMisc;

    /** Menu commands available to the sysop in the menu editor. */
    public const COMMANDS = [
        'MENU', 'SCREEN', 'LEGAL', 'MSG_AREA', 'MSG_READ', 'MSG_NEW', 'MSG_POST', 'MSG_MAIL', 'MSG_SEND',
        'FILE_AREA', 'FILE_LIST', 'FILE_NEW', 'FILE_SEARCH', 'FILE_DOWNLOAD', 'FILE_UPLOAD',
        'ONELINERS', 'LASTCALLERS', 'WHO', 'USERLIST', 'USERINFO', 'SETTINGS', 'PAGE', 'COMMENT',
        'DOOR', 'LOGOFF',
    ];

    public array $S;
    public ?array $user = null;
    public AnsiWriter $w;
    private ?array $ask = null;
    private ?string $dl = null;
    private bool $hang = false;
    private ?array $levelRow = null;
    private array $mcache = [];

    public function __construct()
    {
        if (!isset($_SESSION['cb']) || !is_array($_SESSION['cb'])) {
            $_SESSION['cb'] = [];
        }
        $this->S = &$_SESSION['cb'];
        $this->w = new AnsiWriter();
    }

    /* ============================================================ request cycle */

    public function run(string $a, string $v): array
    {
        $this->cleanupNodes();
        if ($a === 'start') {
            $this->dropNode();
            $this->S = [];
            $this->go('connect');
        } elseif (empty($this->S['st']) || !$this->ownsNode()) {
            $this->S = [];
            $this->hang = true;
        } else {
            if (!empty($this->S['uid'])) {
                $this->user = DB::row('SELECT * FROM {users} WHERE id=?', [(int)$this->S['uid']]);
                if (!$this->user || (int)$this->user['locked'] === 1) {
                    $this->user = null;
                    $this->hangup();
                    return $this->resp();
                }
            }
            if ($a === 'bye') {
                $this->hangup();
            } elseif ($a === 'idle') {
                $this->nl();
                $this->say('idle_timeout');
                $this->hangup();
            } elseif ($this->user && $this->timeLeft() <= 0) {
                $this->nl();
                $this->say('time_up');
                $this->hangup();
            } else {
                $m = 'i_' . $this->S['st'];
                if (method_exists($this, $m)) {
                    $this->$m($v);
                } else {
                    $this->menu();
                }
            }
        }
        if ($this->ask === null && !$this->hang) {
            $this->menu();
        }
        if (!$this->hang) {
            $this->touch();
        }
        return $this->resp();
    }

    /** Keepalive, keeps the node alive while the caller is in the editor or uploading. */
    public function ping(): array
    {
        if (empty($this->S['st']) || !$this->ownsNode()) {
            return ['ok' => false, 'csrf' => cb_csrf()];
        }
        DB::q('UPDATE {nodes} SET seen=? WHERE node=? AND sid=?', [time(), (int)$this->S['node'], session_id()]);
        return ['ok' => true, 'csrf' => cb_csrf()];
    }

    private function resp(): array
    {
        return [
            'o' => CP437::transport($this->w->buf),
            'ask' => $this->hang ? null : $this->ask,
            'dl' => $this->dl,
            'hang' => $this->hang,
            'baud' => $this->baud(),
            'idle' => max(1, Settings::int('idle_minutes', 5)) * 60,
            'csrf' => cb_csrf(),
        ];
    }

    /** Enter a state: sets it and runs e_<state>(). */
    public function go(string $st, ...$args): void
    {
        $this->S['st'] = $st;
        $m = 'e_' . $st;
        if (method_exists($this, $m)) {
            $this->$m(...$args);
        } else {
            $this->menu();
        }
    }

    public function menu(): void
    {
        if (!$this->user) {
            $this->hangup();
            return;
        }
        $this->go('menu');
    }

    /* ============================================================ output helpers */

    /** Language string with macros. Args are inserted after macro expansion, so @FOO@ in user text stays as is. */
    public function L(string $k, ...$a): string
    {
        $s = $this->macros(Lang::get($k));
        foreach ($a as $i => $v) {
            $s = str_replace('{' . ($i + 1) . '}', (string)$v, $s);
        }
        return $s;
    }

    public function say(string $k, ...$a): void
    {
        $this->w->write($this->L($k, ...$a));
    }

    public function write(string $s): void
    {
        $this->w->write($s);
    }

    public function nl(int $n = 1): void
    {
        $this->w->buf .= str_repeat("\r\n", $n);
    }

    public function cls(): void
    {
        $this->w->write('|07|16|CL');
    }

    /** Full width title bar. */
    public function bar(string $left, string $right = ''): void
    {
        $left = ' ' . $left;
        $right = $right !== '' ? $right . ' ' : '';
        $gap = max(1, 80 - mb_strlen($left) - mb_strlen($right));
        $line = mb_substr($left . str_repeat(' ', $gap) . $right, 0, 80);
        $this->w->write('|17|15' . cb_esc(mb_substr($line, 0, 79)));
        $this->w->buf .= "\e[K";
        $this->w->write('|16|07');
        $this->nl();
    }

    public function rule(int $color = 8): void
    {
        $this->w->write(sprintf('|%02d', $color) . str_repeat('─', 79) . '|07');
        $this->nl();
    }

    /* ============================================================ prompts */

    /** Single key prompt. $keys = allowed keys (uppercase), "" = any key, "\r" = Enter. */
    public function hot(string $keys, string $prompt = '', bool $clear = false): void
    {
        if ($prompt !== '') {
            $this->w->write($prompt);
        }
        $this->ask = ['t' => 'hot', 'k' => $keys, 'c' => $clear];
    }

    public function line(int $max, string $prompt, bool $mask = false): void
    {
        $this->w->write($prompt);
        $this->ask = ['t' => 'line', 'm' => $max, 'p' => $mask];
    }

    public function yn(string $prompt, bool $defYes): void
    {
        $y = Lang::get('key_yes');
        $n = Lang::get('key_no');
        $hint = $defYes ? "|15$y|07/$n" : "$y/|15$n|07";
        $this->hot($y . $n . "\r", $prompt . " |07($hint|07)? |15");
    }

    public function yes(string $v, bool $def): bool
    {
        if ($v === "\r") {
            return $def;
        }
        return strtoupper($v) === Lang::get('key_yes');
    }

    /** "Press Enter" and continue with another state. */
    public function pause(string $next = 'menu', array $args = []): void
    {
        $this->S['st'] = 'pause';
        $this->S['pn'] = [$next, $args];
        $this->hot('', $this->L('pause'), true);
    }

    private function i_pause(string $v): void
    {
        [$next, $args] = $this->S['pn'] ?? ['menu', []];
        unset($this->S['pn']);
        if ($next === 'menu') {
            $this->menu();
        } else {
            $this->go($next, ...$args);
        }
    }

    public function editor(array $lines, int $width = 75, int $max = 200): void
    {
        $this->ask = ['t' => 'edit', 'l' => array_values($lines), 'w' => $width, 'm' => $max];
    }

    public function upload(string $prompt): void
    {
        $this->w->write($prompt);
        $this->ask = ['t' => 'upload', 'k' => Lang::get('key_upload') . 'Q'];
    }

    public function download(string $url): void
    {
        $this->dl = $url;
    }

    /* ============================================================ screens and macros */

    public function screen(string $name): bool
    {
        $p = cb_screen_path($name);
        if ($p === null) {
            return false;
        }
        $data = (string)file_get_contents($p);
        if (str_ends_with($p, '.ans')) {
            $data = cb_strip_sauce($data);
            $this->w->raw(cb_macros($data, fn($n) => $this->macro($n), true));
            $this->w->buf .= "\e[0m";
            $this->w->reset();
        } else {
            $data = str_replace(["\r\n", "\r"], "\n", $data);
            $this->w->write(str_replace("\n", "|CR", $this->macros(rtrim($data, "\n"))));
            $this->nl();
            $this->w->write('|07|16');
        }
        return true;
    }

    public function macros(string $s): string
    {
        return cb_macros($s, fn($n) => $this->macro($n));
    }

    public function macro(string $n): ?string
    {
        if (array_key_exists($n, $this->mcache)) {
            return $this->mcache[$n];
        }
        $u = $this->user;
        $v = match ($n) {
            'BBSNAME' => Settings::get('bbs_name', 'WebCarrier BBS'),
            'SYSOP' => Settings::get('sysop_name', 'Sysop'),
            'BBSLOC' => Settings::get('bbs_location', ''),
            'VERSION' => CB_VERSION,
            'NODE' => (string)($this->S['node'] ?? 1),
            'NODES' => (string)Settings::int('max_nodes', 4),
            'DATE' => date(Lang::get('fmt_date')),
            'TIME' => date('H:i'),
            'USER' => $u['handle'] ?? '',
            'USERLOC' => $u['location'] ?? '',
            'CALLS' => (string)($u['calls'] ?? 0),
            'LEVEL' => (string)($u['level'] ?? 0),
            'LEVELNAME' => $this->level()['name'] ?? '',
            'TIMELEFT' => $u ? (string)$this->minutesLeft() : '',
            'LASTCALL' => $u ? cb_date((int)($this->S['prevcall'] ?? 0)) : '',
            'USERS' => (string)DB::val('SELECT COUNT(*) FROM {users}'),
            'MSGS' => (string)DB::val('SELECT COUNT(*) FROM {messages} WHERE private=0'),
            'FILES' => (string)DB::val('SELECT COUNT(*) FROM {files} WHERE approved=1'),
            'TOTALCALLS' => (string)DB::val('SELECT COUNT(*) FROM {calls}'),
            default => null,
        };
        return $this->mcache[$n] = $v;
    }

    /* ============================================================ user, level, time */

    public function lvl(): int
    {
        return $this->user ? (int)$this->user['level'] : 0;
    }

    public function isSysop(): bool
    {
        return $this->user !== null && $this->lvl() >= Settings::int('sysop_level', 255);
    }

    public function level(): array
    {
        if ($this->levelRow === null) {
            $this->levelRow = DB::row('SELECT * FROM {levels} WHERE level<=? ORDER BY level DESC', [$this->lvl()])
                ?? ['level' => 0, 'name' => '', 'minutes' => 30, 'dl_kb' => 0, 'ratio' => 0];
        }
        return $this->levelRow;
    }

    private function usedSeconds(): int
    {
        return (int)($this->S['tbase'] ?? 0) + (time() - (int)($this->S['tstart'] ?? time()));
    }

    public function timeLeft(): int
    {
        $min = (int)$this->level()['minutes'];
        if ($min <= 0) {
            return PHP_INT_MAX;
        }
        return $min * 60 - $this->usedSeconds();
    }

    public function minutesLeft(): int
    {
        $l = $this->timeLeft();
        return $l === PHP_INT_MAX ? 999 : max(0, intdiv($l + 59, 60));
    }

    private function baud(): int
    {
        if ($this->user && (int)$this->user['baud'] >= 0) {
            return (int)$this->user['baud'];
        }
        return Settings::int('baud', 14400);
    }

    public function act(string $what): void
    {
        $this->S['act'] = mb_substr($what, 0, 60);
    }

    public function sysopId(): int
    {
        return Settings::int('sysop_id', 1);
    }

    /* ============================================================ nodes */

    private function cleanupNodes(): void
    {
        $limit = time() - (Settings::int('idle_minutes', 5) * 60 + 120);
        DB::q('DELETE FROM {nodes} WHERE seen<?', [$limit]);
    }

    private function assignNode(): int
    {
        $max = max(1, Settings::int('max_nodes', 4));
        $used = array_map('intval', array_column(DB::all('SELECT node FROM {nodes}'), 'node'));
        for ($n = 1; $n <= $max; $n++) {
            if (in_array($n, $used, true)) {
                continue;
            }
            try {
                DB::insert('nodes', ['node' => $n, 'sid' => session_id(), 'user_id' => 0, 'handle' => '',
                    'activity' => Lang::get('act_login'), 'since' => time(), 'seen' => time()]);
                return $n;
            } catch (PDOException $e) {
                continue;
            }
        }
        return 0;
    }

    private function ownsNode(): bool
    {
        if (empty($this->S['node'])) {
            return false;
        }
        return (bool)DB::val('SELECT COUNT(*) FROM {nodes} WHERE node=? AND sid=?', [(int)$this->S['node'], session_id()]);
    }

    private function dropNode(): void
    {
        if (!empty($this->S['node'])) {
            DB::q('DELETE FROM {nodes} WHERE node=? AND sid=?', [(int)$this->S['node'], session_id()]);
        }
    }

    private function touch(): void
    {
        if (empty($this->S['node'])) {
            return;
        }
        DB::q('UPDATE {nodes} SET seen=?, user_id=?, handle=?, activity=? WHERE node=? AND sid=?', [
            time(), (int)($this->user['id'] ?? 0), (string)($this->user['handle'] ?? ''),
            (string)($this->S['act'] ?? Lang::get('act_login')), (int)$this->S['node'], session_id(),
        ]);
        if ($this->user) {
            DB::q('UPDATE {users} SET time_today=? WHERE id=?', [$this->usedSeconds(), (int)$this->user['id']]);
        }
    }

    public function hangup(): void
    {
        if ($this->user) {
            DB::q('UPDATE {users} SET time_today=? WHERE id=?', [$this->usedSeconds(), (int)$this->user['id']]);
            cb_log((int)$this->user['id'], $this->user['handle'], 'Logoff');
        }
        $this->dropNode();
        $this->S = [];
        $this->hang = true;
        $this->ask = null;
    }

    /* ============================================================ connect and login */

    private function e_connect(): void
    {
        $node = $this->assignNode();
        $this->cls();
        if ($node === 0) {
            $this->say('all_busy');
            $this->hang = true;
            return;
        }
        $this->S['node'] = $node;
        $this->act(Lang::get('act_login'));
        if (!$this->screen('welcome')) {
            $this->say('welcome_default');
        }
        $this->go('login');
    }

    private function e_login(): void
    {
        $this->nl();
        $this->line(20, $this->L('login_prompt'));
    }

    private function i_login(string $v): void
    {
        $v = CP437::clean($v, 20);
        if ($v === '') {
            $this->go('login');
            return;
        }
        if (in_array(mb_strtoupper($v), ['NEW', mb_strtoupper(Lang::get('kw_new'))], true)) {
            $this->go('newuser');
            return;
        }
        $u = DB::row('SELECT * FROM {users} WHERE handle_lc=?', [mb_strtolower($v)]);
        if (!$u) {
            $this->S['tmpname'] = $v;
            $this->say('login_unknown', cb_esc($v));
            $this->S['st'] = 'login_nf';
            $this->yn($this->L('login_register'), false);
            return;
        }
        $this->S['tmpuid'] = (int)$u['id'];
        $this->go('pass');
    }

    private function i_login_nf(string $v): void
    {
        if ($this->yes($v, false)) {
            $this->go('newuser');
        } else {
            $this->go('login');
        }
    }

    private function e_pass(): void
    {
        $this->line(72, $this->L('pass_prompt'), true);
    }

    private function i_pass(string $v): void
    {
        $u = DB::row('SELECT * FROM {users} WHERE id=?', [(int)($this->S['tmpuid'] ?? 0)]);
        if ($u && cb_login_blocked((int)$u['id'])) {
            $this->say('login_toomany');
            $this->hangup();
            return;
        }
        if ($u && password_verify($v, $u['pass'])) {
            if ((int)$u['locked'] === 1) {
                $this->say('login_locked');
                $this->hangup();
                return;
            }
            $this->doLogin((int)$u['id']);
            return;
        }
        $this->S['tries'] = (int)($this->S['tries'] ?? 0) + 1;
        if ($u) {
            cb_log((int)$u['id'], $u['handle'], 'Wrong password');
        }
        if ($this->S['tries'] >= 3) {
            $this->say('login_toomany');
            $this->hangup();
            return;
        }
        $this->say('login_wrong');
        $this->go('login');
    }

    private function doLogin(int $id): void
    {
        $node = (int)$this->S['node'];
        session_regenerate_id(true);
        DB::q('UPDATE {nodes} SET sid=? WHERE node=?', [session_id(), $node]);
        $u = DB::row('SELECT * FROM {users} WHERE id=?', [$id]);
        $today = date('Y-m-d');
        if ($u['today'] !== $today) {
            DB::q('UPDATE {users} SET time_today=0, dl_kb_today=0, today=? WHERE id=?', [$today, $id]);
            $u['time_today'] = 0;
        }
        $this->S = ['st' => 'menu', 'node' => $node, 'uid' => $id, 'prevcall' => (int)$u['last_call'],
            'tbase' => (int)$u['time_today'], 'tstart' => time(), 'menu' => 'main'];
        DB::q('UPDATE {users} SET calls=calls+1, last_call=? WHERE id=?', [time(), $id]);
        DB::insert('calls', ['user_id' => $id, 'handle' => $u['handle'], 'location' => $u['location'],
            'node' => $node, 'time' => time()]);
        $this->user = DB::row('SELECT * FROM {users} WHERE id=?', [$id]);
        $this->levelRow = null;
        $this->mcache = [];
        cb_log($id, $u['handle'], 'Login on node ' . $node);
        $this->act(Lang::get('act_logon'));
        if ($this->timeLeft() <= 0) {
            $this->nl();
            $this->say('time_used_up');
            $this->hangup();
            return;
        }
        $this->cls();
        if ($this->screen('logon')) {
            $this->pause('postlogon');
        } else {
            $this->go('postlogon');
        }
    }

    private function e_postlogon(): void
    {
        $this->nl();
        $this->say('welcome_back', cb_esc($this->user['handle']), (int)$this->user['calls']);
        $this->nl();
        $this->say('time_today', $this->minutesLeft());
        $this->nl();
        $mail = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE private=1 AND to_id=? AND is_read=0', [(int)$this->user['id']]);
        if ($mail > 0) {
            $this->nl();
            $this->say('you_have_mail', $mail);
            $this->w->buf .= "\x07";
            $this->nl();
        }
        if (Settings::get('logon_oneliners', '1') === '1') {
            $rows = array_reverse(DB::all('SELECT * FROM {oneliners} ORDER BY id DESC LIMIT 5'));
            if ($rows) {
                $this->nl();
                $this->say('oneliners_head');
                $this->nl();
                foreach ($rows as $r) {
                    $this->write('|11' . cb_esc(cb_pad($r['handle'], 16)) . '|08: |07' . cb_esc($r['text']));
                    $this->nl();
                }
            }
        }
        $this->nl();
        $this->pause('menu');
    }

    /* ============================================================ new user */

    private function e_newuser(): void
    {
        if (Settings::get('allow_new', '1') !== '1') {
            $this->nl();
            $this->say('nu_closed');
            $this->go('login');
            return;
        }
        $this->cls();
        if (!$this->screen('newuser')) {
            $this->say('nu_intro');
            $this->nl();
        }
        $this->S['nu'] = [];
        $this->go('nu_handle');
    }

    private function e_nu_handle(): void
    {
        $this->nl();
        $this->line(20, $this->L('nu_handle'));
    }

    private function i_nu_handle(string $v): void
    {
        $v = CP437::clean($v, 20);
        $err = cb_handle_check($v);
        if ($err !== null) {
            $this->say($err === 'bad' ? 'nu_handle_bad' : 'nu_handle_taken');
            $this->go('nu_handle');
            return;
        }
        $this->S['nu']['handle'] = $v;
        $this->go('nu_loc');
    }

    private function e_nu_loc(): void
    {
        $this->line(30, $this->L('nu_location'));
    }

    private function i_nu_loc(string $v): void
    {
        $v = CP437::clean($v, 30);
        if (mb_strlen($v) < 2) {
            $this->go('nu_loc');
            return;
        }
        $this->S['nu']['location'] = $v;
        $this->go('nu_pass');
    }

    private function e_nu_pass(): void
    {
        $this->line(40, $this->L('nu_pass'), true);
    }

    private function i_nu_pass(string $v): void
    {
        if (mb_strlen($v) < 6) {
            $this->say('nu_pass_short');
            $this->go('nu_pass');
            return;
        }
        $this->S['nu']['hash'] = password_hash($v, PASSWORD_DEFAULT);
        $this->S['st'] = 'nu_pass2';
        $this->line(40, $this->L('nu_pass2'), true);
    }

    private function i_nu_pass2(string $v): void
    {
        if (!password_verify($v, (string)($this->S['nu']['hash'] ?? ''))) {
            $this->say('nu_pass_mismatch');
            $this->go('nu_pass');
            return;
        }
        $this->nl();
        $this->say('nu_summary', cb_esc($this->S['nu']['handle']), cb_esc($this->S['nu']['location']));
        $this->S['st'] = 'nu_confirm';
        $this->yn($this->L('nu_confirm'), true);
    }

    private function i_nu_confirm(string $v): void
    {
        if (!$this->yes($v, true)) {
            $this->say('nu_aborted');
            $this->go('login');
            return;
        }
        $nu = $this->S['nu'];
        if (DB::val('SELECT COUNT(*) FROM {users} WHERE handle_lc=?', [mb_strtolower($nu['handle'])])) {
            $this->say('nu_handle_taken');
            $this->go('nu_handle');
            return;
        }
        $id = DB::insert('users', [
            'handle' => $nu['handle'], 'handle_lc' => mb_strtolower($nu['handle']), 'pass' => $nu['hash'],
            'location' => $nu['location'], 'level' => Settings::int('new_level', 10), 'created' => time(),
            'today' => date('Y-m-d'), 'baud' => -1,
        ]);
        unset($this->S['nu']);
        cb_log($id, $nu['handle'], 'New user registered');
        $this->say('nu_created');
        $this->doLogin($id);
    }

    /* ============================================================ menus */

    private function e_menu(bool $force = false): void
    {
        $name = (string)($this->S['menu'] ?? 'main');
        $menu = DB::row('SELECT * FROM {menus} WHERE name=?', [$name]);
        if (!$menu || $this->lvl() < (int)$menu['min_level']) {
            $menu = DB::row("SELECT * FROM {menus} WHERE name='main'");
        }
        if (!$menu) {
            $this->say('menu_missing');
            $this->hangup();
            return;
        }
        $this->S['menu'] = $menu['name'];
        $this->act($menu['title']);
        $items = DB::all('SELECT * FROM {menu_items} WHERE menu_id=? AND min_level<=? ORDER BY sort, id',
            [(int)$menu['id'], $this->lvl()]);
        if ((int)$this->user['expert'] !== 1 || $force) {
            $this->cls();
            if ($menu['screen'] === '' || !$this->screen($menu['screen'])) {
                $this->autoMenu($menu, $items);
            }
        }
        $keys = '?';
        foreach ($items as $it) {
            $keys .= mb_strtoupper($it['hotkey']);
        }
        $this->nl();
        $this->hot($keys, $this->L('menu_prompt', cb_esc($menu['title']), $this->minutesLeft()));
    }

    private function autoMenu(array $menu, array $items): void
    {
        $this->bar(Settings::get('bbs_name', 'WebCarrier BBS'), $menu['title']);
        $this->nl();
        $col = [];
        foreach ($items as $it) {
            $col[] = '|08[|15' . cb_esc(strtoupper($it['hotkey'])) . '|08] |07' . cb_esc(cb_pad($it['label'], 33));
        }
        $half = (int)ceil(count($col) / 2);
        for ($i = 0; $i < $half; $i++) {
            $this->write('  ' . $col[$i] . (isset($col[$i + $half]) ? '  ' . $col[$i + $half] : ''));
            $this->nl();
        }
    }

    private function i_menu(string $v): void
    {
        $k = mb_strtoupper($v);
        if ($k === '?') {
            $this->go('menu', true);
            return;
        }
        // compare in PHP, SQLite's UPPER() is ASCII only (umlaut hotkeys)
        $menu = DB::row('SELECT * FROM {menus} WHERE name=?', [(string)($this->S['menu'] ?? 'main')]);
        $it = null;
        foreach ($menu ? DB::all('SELECT * FROM {menu_items} WHERE menu_id=? AND min_level<=? ORDER BY sort, id',
            [(int)$menu['id'], $this->lvl()]) : [] as $row) {
            if (mb_strtoupper($row['hotkey']) === $k) {
                $it = $row;
                break;
            }
        }
        if (!$it) {
            $this->go('menu');
            return;
        }
        $this->exec((string)$it['command'], (string)$it['data']);
    }

    public function exec(string $cmd, string $data): void
    {
        switch (strtoupper($cmd)) {
            case 'MENU':
                $this->S['menu'] = $data !== '' ? $data : 'main';
                $this->go('menu');
                break;
            case 'SCREEN':
                $this->cls();
                if (!$this->screen($data)) {
                    $this->say('screen_missing', cb_esc($data));
                }
                $this->pause();
                break;
            case 'LEGAL':
                $this->legal($data);
                break;
            case 'MSG_AREA':
                $this->go('marea');
                break;
            case 'MSG_READ':
                $this->go('mread');
                break;
            case 'MSG_NEW':
                $this->newScan();
                break;
            case 'MSG_POST':
                $this->postPublic();
                break;
            case 'MSG_MAIL':
                $this->readMail();
                break;
            case 'MSG_SEND':
                $this->startPost(['private' => 1, 'area_id' => 0]);
                break;
            case 'FILE_AREA':
                $this->go('farea');
                break;
            case 'FILE_LIST':
                $this->fileList();
                break;
            case 'FILE_NEW':
                $this->fileNew();
                break;
            case 'FILE_SEARCH':
                $this->go('fsearch');
                break;
            case 'FILE_DOWNLOAD':
                $this->go('fdl');
                break;
            case 'FILE_UPLOAD':
                $this->fileUpload();
                break;
            case 'ONELINERS':
                $this->go('one');
                break;
            case 'LASTCALLERS':
                $this->lastCallers();
                break;
            case 'WHO':
                $this->whoOnline();
                break;
            case 'USERLIST':
                $this->userList();
                break;
            case 'USERINFO':
                $this->userInfo();
                break;
            case 'SETTINGS':
                $this->go('uset');
                break;
            case 'PAGE':
                $this->pageSysop();
                break;
            case 'COMMENT':
                $this->startPost(['private' => 1, 'area_id' => 0, 'to' => $this->sysopHandle(), 'to_id' => $this->sysopId()]);
                break;
            case 'DOOR':
                $this->openDoor($data);
                break;
            case 'LOGOFF':
                $this->S['st'] = 'logoff';
                $this->nl();
                $this->yn($this->L('logoff_ask'), true);
                break;
            default:
                $this->say('cmd_unknown', cb_esc($cmd));
                $this->pause();
        }
    }

    private function i_logoff(string $v): void
    {
        if (!$this->yes($v, true)) {
            $this->menu();
            return;
        }
        $this->cls();
        if (!$this->screen('logoff')) {
            $this->say('goodbye');
        }
        $this->hangup();
    }

    public function sysopHandle(): string
    {
        return (string)(DB::val('SELECT handle FROM {users} WHERE id=?', [$this->sysopId()]) ?? 'Sysop');
    }
}
