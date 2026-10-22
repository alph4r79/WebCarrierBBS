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
require __DIR__ . '/engine_sysop.php';
require __DIR__ . '/engine_social.php';
require __DIR__ . '/engine_logon.php';

final class Engine
{
    use EngineMessages;
    use EngineFiles;
    use EngineMisc;
    use EngineSysop;
    use EngineSocial;
    use EngineLogon;

    /** Menu commands available to the sysop in the menu editor. */
    public const COMMANDS = [
        'MENU', 'SCREEN', 'LEGAL', 'MSG_AREA', 'MSG_READ', 'MSG_NEW', 'MSG_POST', 'MSG_MAIL', 'MSG_SEND',
        'FILE_AREA', 'FILE_LIST', 'FILE_NEW', 'FILE_SEARCH', 'FILE_DOWNLOAD', 'FILE_UPLOAD',
        'ONELINERS', 'LASTCALLERS', 'WHO', 'USERLIST', 'USERINFO', 'SETTINGS', 'PAGE', 'COMMENT',
        'DOOR', 'DOORTOP', 'TELECONF', 'PROFILE', 'MSG_SEARCH', 'POLLS', 'SYSOP', 'LOGOFF',
    ];

    public array $S;
    public ?array $user = null;
    public AnsiWriter $w;
    private ?array $ask = null;
    private ?string $dl = null;
    private ?string $open = null;
    private ?string $notify = null;
    private bool $hang = false;
    private ?array $levelRow = null;
    private array $mcache = [];
    private bool $preview = false;
    private bool $cancel = false;
    /** Resume token of a fresh login, sent to the terminal once. */
    private ?string $rt = null;
    /** Answer to a resume request: true resumed, false a new call instead. */
    private ?bool $resumed = null;
    /** Seconds since the last key, sent with a resumed line so the idle timer goes on. */
    private ?int $il = null;
    /** What the terminal echoed locally for the current input, see echoOf(). */
    private string $echo = '';

    /** A node stays reserved for a reload this many seconds after its last sign of life. */
    public const RESUME_GRACE = 120;
    /** Output kept per node since the last clear screen, for a reload. */
    private const SCREEN_MAX = 65536;

    public function __construct()
    {
        if (!isset($_SESSION['cb']) || !is_array($_SESSION['cb'])) {
            $_SESSION['cb'] = [];
        }
        $this->S = &$_SESSION['cb'];
        $this->w = new AnsiWriter();
    }

    /** Engine without session for the previews in the backend. It renders only, run(), ping() and poll() refuse. */
    public static function preview(): self
    {
        $e = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $e->S = [];
        $e->preview = true;
        $e->w = new AnsiWriter();
        return $e;
    }

    private function live(): void
    {
        if ($this->preview) {
            throw new LogicException('preview engine cannot handle requests');
        }
    }

    /* ============================================================ request cycle */

    public function run(string $a, string $v): array
    {
        $this->live();
        $this->cleanupNodes();
        if ($a === 'start') {
            $this->newCall();
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
            if ($this->kicked()) {
                $this->nl();
                $this->say('kicked');
                $this->hangup();
            } elseif ($a === 'bye') {
                $this->hangup();
            } elseif ($a === 'idle') {
                $this->nl();
                $this->say('idle_timeout');
                $this->hangup();
            } elseif ($this->user && $this->timeLeft() <= 0) {
                $this->nl();
                $this->say('time_up');
                $this->hangup();
            } elseif (!empty($this->S['wt']) || $a === 'wait') {
                if (!empty($this->S['wt']) && $a === 'wait') {
                    // end of a wait(): the handler gets an empty input
                    unset($this->S['wt']);
                    $this->input('');
                    // a door that waited and now asks for a key: the caller is at turn
                    if (!empty($this->S['door']) && $this->ask !== null && $this->ask['t'] !== 'wait') {
                        $this->hint('turn');
                    }
                } else {
                    // keys during a wait, or a wait nobody asked for: same prompt again
                    $this->ask = $this->S['ask'] ?? null;
                }
            } else {
                // Escape in a line asked for with $esc: an abort instead of a line, see cancelled()
                $ask = $this->S['ask'] ?? null;
                $this->echo = $this->echoOf(is_array($ask) ? $ask : null, $v);
                $this->S['lk'] = time();
                if ($v === "\x1b" && is_array($ask) && ($ask['t'] ?? '') === 'line' && !empty($ask['x'])) {
                    $this->cancel = true;
                    $v = '';
                }
                $this->input($v);
            }
        }
        if ($this->ask === null && !$this->hang) {
            $this->menu();
        }
        if (!$this->hang) {
            $this->recordScreen($this->echo . $this->w->buf);
            $this->touch();
        }
        return $this->resp();
    }

    /** New call. One from the same session ends the old call and frees its node, also a node kept for a reload. */
    private function newCall(): void
    {
        if (!empty($this->S['uid']) && $this->ownsNode()) {
            $this->user = DB::row('SELECT * FROM {users} WHERE id=?', [(int)$this->S['uid']]);
            $this->teleLeave();
            $this->endCall();
            $this->user = null;
        }
        $this->dropNode();
        $this->S = [];
        $this->go('connect');
    }

    /**
     * Reload of the terminal page with the resume token of the tab. The line goes on with the screen
     * and the prompt it had if the token belongs to this session, the node is still ours and neither
     * disconnected nor expired. Otherwise a normal new call starts.
     */
    public function resume(string $rt): array
    {
        $this->live();
        $this->cleanupNodes();
        $node = $this->resumableNode($rt);
        if ($node === null) {
            $this->user = null;
            $this->levelRow = null;
            $this->resumed = false;
            $this->newCall();
            if (!$this->hang) {
                $this->touch();
            }
            return $this->resp();
        }
        $this->resumed = true;
        DB::q('UPDATE {nodes} SET seen=?, away=0 WHERE node=? AND sid=?', [time(), (int)$this->S['node'], session_id()]);
        cb_log((int)$this->user['id'], $this->user['handle'], 'Line resumed on node ' . (int)$this->S['node']);
        $this->w->buf .= "\e[0m\e[2J\e[H" . self::screenBytes($node['screen'] ?? null);
        $this->ask = is_array($this->S['ask'] ?? null) ? $this->S['ask'] : null;
        if ($this->ask === null) {
            $this->menu();
            $this->recordScreen($this->w->buf);
        }
        $this->il = max(0, time() - (int)($this->S['lk'] ?? time()));
        $this->touch();
        return $this->resp();
    }

    /** Row of the node to resume, or null. Loads the user on success. */
    private function resumableNode(string $rt): ?array
    {
        if ($rt === '' || strlen($rt) > 64 || empty($this->S['uid']) || empty($this->S['rt']) || empty($this->S['node'])
            || empty($this->S['st']) || !hash_equals((string)$this->S['rt'], hash('sha256', $rt))) {
            return null;
        }
        $n = DB::row('SELECT * FROM {nodes} WHERE node=? AND sid=? AND user_id=?', [(int)$this->S['node'], session_id(), (int)$this->S['uid']]);
        if (!$n || (int)$n['kicked'] === 1 || max((int)$n['seen'], (int)$n['away']) < time() - self::RESUME_GRACE) {
            return null;
        }
        $u = DB::row('SELECT * FROM {users} WHERE id=?', [(int)$this->S['uid']]);
        if (!$u || (int)$u['locked'] === 1 || (int)$u['pending'] === 1) {
            return null;
        }
        // idle time and time limit go on during a reload
        $this->user = $u;
        $this->levelRow = null;
        $idle = max(1, Settings::int('idle_minutes', 5)) * 60;
        if ($this->timeLeft() <= 0 || time() - (int)($this->S['lk'] ?? 0) >= $idle) {
            return null;
        }
        return $n;
    }

    /** Pagehide of a logged in terminal: closed tab or reload. The node is freed after RESUME_GRACE unless the tab comes back. */
    public function away(): array
    {
        $this->live();
        if (!empty($this->S['uid']) && $this->ownsNode()) {
            DB::q('UPDATE {nodes} SET away=? WHERE node=? AND sid=?', [time(), (int)$this->S['node'], session_id()]);
        }
        return ['ok' => true, 'csrf' => cb_csrf()];
    }

    /** Stored screen of a node as CP437 bytes. */
    private static function screenBytes(mixed $s): string
    {
        return !is_string($s) || $s === '' ? '' : mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }

    /**
     * Add output to the screen of the node: from the last clear screen on, at most SCREEN_MAX bytes,
     * the oldest part goes first. Only for logged in callers, nothing before the login is kept.
     */
    private function recordScreen(string $out, ?string $base = null): void
    {
        if ($out === '' || empty($this->S['uid']) || empty($this->S['node']) || $this->preview) {
            return;
        }
        $p = strrpos($out, "\e[2J");
        if ($p !== false) {
            $buf = substr($out, $p);
        } else {
            $base ??= self::screenBytes(DB::val('SELECT screen FROM {nodes} WHERE node=? AND sid=?', [(int)$this->S['node'], session_id()]));
            $buf = $base . $out;
        }
        if (strlen($buf) > self::SCREEN_MAX) {
            $buf = substr($buf, -self::SCREEN_MAX);
            // start with a full line, the rest of a cut escape sequence would show up as text
            $nl = strpos($buf, "\n");
            if ($nl !== false && $nl < 4096) {
                $buf = substr($buf, $nl + 1);
            }
        }
        DB::q('UPDATE {nodes} SET screen=? WHERE node=? AND sid=?', [CP437::transport($buf), (int)$this->S['node'], session_id()]);
    }

    /**
     * What the terminal printed itself for an input, the same as assets/app.js does it: the key of a
     * hot prompt, the line (stars for a mask), the own chat line, the lines of the editor.
     */
    private function echoOf(?array $ask, string $v): string
    {
        $clean = static fn(string $s): string => CP437::from(preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '');
        switch ($ask['t'] ?? '') {
            case 'hot':
            case 'upload':
                if ($v === "\x01") {
                    return "\r\n";
                }
                if (!empty($ask['c'])) {
                    return "\r\e[K";
                }
                $k = mb_strtoupper(mb_substr($v, 0, 1));
                return ($k === "\r" || $k === ' ' ? '' : $clean($k)) . "\r\n";
            case 'line':
                if ($v === "\x1b" && !empty($ask['x'])) {
                    return "\r\n";
                }
                $v = mb_substr($v, 0, max(1, (int)($ask['m'] ?? 80)));
                return (!empty($ask['p']) ? str_repeat('*', mb_strlen($v)) : $clean($v)) . "\r\n";
            case 'chat':
                return "\r\e[K\e[0;1;37m> " . $clean(mb_substr($v, 0, 80)) . "\e[0;37m\r\n";
            case 'edit':
                if ($v === "\x00") {
                    return "\e[0;1;31m" . CP437::from(Lang::get('js_ed_aborted')) . "\e[0;37m\r\n";
                }
                $out = '';
                foreach (explode("\n", $v) as $i => $l) {
                    $out .= "\e[0;1;30m" . sprintf('%3d', $i + 1) . ": \e[0;37m" . $clean($l) . "\r\n";
                }
                return $out . "\e[0;1;32m" . CP437::from(Lang::get('js_ed_saved')) . "\e[0;37m\r\n";
        }
        return '';
    }

    private function input(string $v): void
    {
        $st = (string)$this->S['st'];
        // all states of the sysop menu start with "sys": out as soon as the level is gone
        if (str_starts_with($st, 'sys') && !$this->isSysop()) {
            $this->S['menu'] = 'main';
            $this->menu();
            return;
        }
        $m = 'i_' . $st;
        if (method_exists($this, $m)) {
            $this->$m($v);
        } else {
            $this->menu();
        }
    }

    /** Keepalive, keeps the node alive while the caller is in the editor or uploading. */
    public function ping(): array
    {
        $this->live();
        if (empty($this->S['st']) || !$this->ownsNode()) {
            return ['ok' => false, 'csrf' => cb_csrf()];
        }
        DB::q('UPDATE {nodes} SET seen=?, away=0 WHERE node=? AND sid=? AND user_id>0', [time(), (int)$this->S['node'], session_id()]);
        return ['ok' => true, 'csrf' => cb_csrf()];
    }

    /**
     * Poll from the terminal: delivers messages for this node (broadcast, page, chat).
     * Keeps the state, except that a chat request switches to the chat. Not counted as activity.
     */
    public function poll(): array
    {
        $this->live();
        $this->cleanupNodes();
        if (empty($this->S['st']) || !$this->ownsNode()) {
            $this->S = [];
            $this->hang = true;
            return $this->resp();
        }
        if (!empty($this->S['uid'])) {
            $this->user = DB::row('SELECT * FROM {users} WHERE id=?', [(int)$this->S['uid']]);
            if (!$this->user || (int)$this->user['locked'] === 1) {
                $this->user = null;
                $this->hangup();
                return $this->resp();
            }
        }
        if ($this->kicked()) {
            $this->nl();
            $this->say('kicked');
            $this->hangup();
            return $this->resp();
        }
        DB::q('UPDATE {nodes} SET seen=?, away=0 WHERE node=? AND sid=? AND user_id>0', [time(), (int)$this->S['node'], session_id()]);
        $this->deliverNodeMsgs();
        if (!$this->hang) {
            $this->pageWaitCheck();
            $this->pollExtras();
        }
        if (!$this->hang && $this->w->buf !== '') {
            // the terminal clears the prompt line, prints the output and the prompt again, unless a new prompt came
            $base = self::screenBytes(DB::val('SELECT screen FROM {nodes} WHERE node=? AND sid=?', [(int)$this->S['node'], session_id()]));
            $nl = strrpos($base, "\n");
            $row = $this->ask === null ? ($nl === false ? $base : substr($base, $nl + 1)) : '';
            $this->recordScreen("\r\e[K" . $this->w->buf . $row, $base);
        }
        return $this->resp();
    }

    private function resp(): array
    {
        if ($this->ask !== null && !$this->hang) {
            // kept for a repeated prompt, wt marks a running wait()
            $this->S['ask'] = $this->ask;
            if ($this->ask['t'] === 'wait') {
                $this->S['wt'] = 1;
            } else {
                unset($this->S['wt']);
            }
        }
        $r = [
            'o' => CP437::transport($this->w->buf),
            'ask' => $this->hang ? null : $this->ask,
            'dl' => $this->dl,
            'hang' => $this->hang,
            'baud' => $this->baud(),
            // before the login the terminal hangs up a little earlier than cleanupNodes() frees the node
            'idle' => $this->user ? max(1, Settings::int('idle_minutes', 5)) * 60 : min(170, max(1, Settings::int('idle_minutes', 5)) * 60),
            'crt' => $this->crtMode(),
            'csrf' => cb_csrf(),
        ];
        if ($this->rt !== null && !$this->hang) {
            $r['rt'] = $this->rt;
        }
        if ($this->resumed !== null) {
            $r['resumed'] = $this->resumed && !$this->hang;
        }
        if ($this->il !== null) {
            $r['il'] = $this->il;
        }
        if ($this->open !== null) {
            $r['open'] = $this->open;
        }
        if ($this->notify !== null && Settings::get('tab_notify', '1') === '1') {
            $r['notify'] = $this->notify;
        }
        return $r;
    }

    /** Enter a state: sets it and runs e_<state>(). */
    public function go(string $st, ...$args): void
    {
        // also on the way in, for example after a pause inside the sysop menu
        if (str_starts_with($st, 'sys') && !$this->isSysop()) {
            $this->S['menu'] = 'main';
            $st = 'menu';
            $args = [];
        }
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
        // inside a door the answer to hot() is passed on in upper case
        if (($this->S['st'] ?? '') === 'door') {
            $this->S['dhot'] = true;
        }
        if ($prompt !== '') {
            $this->w->write($prompt);
        }
        $this->ask = ['t' => 'hot', 'k' => $keys, 'c' => $clear];
    }

    /** End of the running call (calls.ended), once. */
    private function endCall(): void
    {
        if (!empty($this->S['callid'])) {
            DB::q('UPDATE {calls} SET ended=? WHERE id=? AND ended=0', [time(), (int)$this->S['callid']]);
            unset($this->S['callid']);
        }
    }

    /** Line prompt. With $esc the caller can cancel it with Escape: the handler gets '' and cancelled() is true. */
    public function line(int $max, string $prompt, bool $mask = false, bool $esc = false): void
    {
        unset($this->S['dhot']);
        $this->w->write($prompt);
        $this->ask = ['t' => 'line', 'm' => $max, 'p' => $mask];
        if ($esc) {
            $this->ask['x'] = true;
        }
    }

    /** True while the current input is the abort of a line(..., true) with Escape. */
    public function cancelled(): bool
    {
        return $this->cancel;
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
        // a door that pauses gets the key itself ("\r"), instead of the menu
        if (($this->S['st'] ?? '') === 'door' && !empty($this->S['door']) && $next === 'menu') {
            $next = '#door';
        }
        $this->S['st'] = 'pause';
        $this->S['pn'] = [$next, $args];
        $this->hot('', $this->L('pause'), true);
    }

    private function i_pause(string $v): void
    {
        [$next, $args] = $this->S['pn'] ?? ['menu', []];
        unset($this->S['pn']);
        if ($next === '#door') {
            $this->S['st'] = 'door';
            $this->i_door("\r");
            return;
        }
        if ($next === 'menu') {
            $this->menu();
        } else {
            $this->go($next, ...$args);
        }
    }

    /**
     * Show the output, wait $ms milliseconds (0 to 5000) and continue without a key: the terminal
     * sends an empty input by itself. Keys during the wait are dropped. Not counted as activity.
     */
    public function wait(int $ms, string $prompt = ''): void
    {
        unset($this->S['dhot']);
        if ($prompt !== '') {
            $this->w->write($prompt);
        }
        $this->ask = ['t' => 'wait', 'ms' => max(0, min(5000, $ms))];
    }

    public function editor(array $lines, int $width = 75, int $max = 200): void
    {
        unset($this->S['dhot']);
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
            $this->w->raw(cb_macros($data, fn($n) => $this->ansMacro($n), true));
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

    /** Macro for an ANSI screen: block macros with pipe codes and |CR become ANSI sequences there. */
    private function ansMacro(string $n): ?string
    {
        $v = $this->macro($n);
        if ($v === null || !in_array($n, ['DOORTOP', 'SYSOPMSG', 'NEWSINCE'], true) || $v === '') {
            return $v;
        }
        $w = new AnsiWriter();
        $w->write($v);
        return CP437::toUtf8($w->buf);
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
            'BBSURL' => cb_bbs_url(),
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
            'DOORTOP' => $u ? $this->doorTopBox() : '',
            'SYSOPMSG' => implode('|CR', cb_sysop_msg()),
            'NEWSINCE' => ($c = $u ? $this->newSinceCounts() : null) !== null ? $this->newSinceBox($c) : '',
            default => null,
        };
        return $this->mcache[$n] = $v;
    }

    /* ============================================================ user, level, time */

    public function lvl(): int
    {
        return $this->user ? (int)$this->user['level'] : 0;
    }

    /** Banned word in $text, null if there is none or the caller is a sysop. */
    public function bannedWord(string $text): ?string
    {
        return $this->isSysop() ? null : cb_banned_word($text);
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
        // vanished: no sign of life for longer than the idle time, or the tab was left (away) and did not come back
        $gone = 'seen<? OR (away>0 AND away<?)';
        $par = [time() - (Settings::int('idle_minutes', 5) * 60 + 120), time() - self::RESUME_GRACE];
        $tele = DB::all("SELECT node, handle FROM {nodes} WHERE ($gone) AND tele=1", $par);
        // the call ends when the node was seen the last time, or when the tab was left
        foreach (DB::all("SELECT node, user_id, seen, away FROM {nodes} WHERE ($gone) AND user_id>0", $par) as $v) {
            cb_end_calls((int)$v['user_id'], (int)$v['node'], (int)$v['away'] > 0 ? (int)$v['away'] : (int)$v['seen']);
        }
        DB::q("DELETE FROM {nodes} WHERE $gone", $par);
        // nodes that never logged in are freed quickly, keepalive does not refresh
        // them (see ping() and poll()), only real input does through touch()
        DB::q('DELETE FROM {nodes} WHERE user_id=0 AND seen<?', [time() - 180]);
        foreach ($tele as $g) {
            $this->teleGone((int)$g['node'], (string)$g['handle']);
        }
        // negative nodes belong to the backend, they have no row in nodes
        DB::q('DELETE FROM {node_msgs} WHERE time<? OR (node>0 AND node NOT IN (SELECT node FROM {nodes}))', [time() - 3600]);
    }

    /** True if the sysop disconnected this node. */
    private function kicked(): bool
    {
        return !empty($this->S['node'])
            && (bool)DB::val('SELECT COUNT(*) FROM {nodes} WHERE node=? AND sid=? AND kicked=1', [(int)$this->S['node'], session_id()]);
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
        DB::q('UPDATE {nodes} SET seen=?, away=0, user_id=?, handle=?, activity=? WHERE node=? AND sid=?', [
            time(), (int)($this->user['id'] ?? 0), (string)($this->user['handle'] ?? ''),
            (string)($this->S['act'] ?? Lang::get('act_login')), (int)$this->S['node'], session_id(),
        ]);
        if ($this->user) {
            DB::q('UPDATE {users} SET time_today=? WHERE id=?', [$this->usedSeconds(), (int)$this->user['id']]);
        }
    }

    public function hangup(): void
    {
        if (!empty($this->S['chat'])) {
            $this->nodeMsg((int)$this->S['chat']['peer'], 'end', '');
        }
        $this->teleLeave();
        $this->pageGone();
        $this->endCall();
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
            if ((int)$u['pending'] === 1) {
                cb_log((int)$u['id'], $u['handle'], 'Login while waiting for validation');
                $this->showPending();
                return;
            }
            if ((int)$u['totp_on'] === 1) {
                $this->S['pwok'] = (int)$u['id'];
                $this->go('totp');
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
        // resume token for a reload of this tab, the session keeps only its hash
        $this->rt = bin2hex(random_bytes(16));
        $this->S['rt'] = hash('sha256', $this->rt);
        $this->S['lk'] = time();
        DB::q('UPDATE {users} SET calls=calls+1, last_call=? WHERE id=?', [time(), $id]);
        $this->S['callid'] = DB::insert('calls', ['user_id' => $id, 'handle' => $u['handle'], 'location' => $u['location'],
            'node' => $node, 'time' => time()]);
        $this->user = DB::row('SELECT * FROM {users} WHERE id=?', [$id]);
        cb_log($id, $u['handle'], 'Login on node ' . $node);
        $auto = cb_auto_level($this->user);
        if ($auto !== null) {
            DB::q('UPDATE {users} SET level=? WHERE id=?', [$auto[0], $id]);
            cb_log($id, $u['handle'], 'Auto level ' . $u['level'] . ' to ' . $auto[0] . ' (rule ' . $auto[1] . ')');
            $this->user['level'] = $auto[0];
            $this->S['promo'] = 1;
        }
        $this->S['mailmax'] = (int)DB::val('SELECT MAX(id) FROM {messages} WHERE private=1 AND to_id=?', [$id]);
        $this->levelRow = null;
        $this->mcache = [];
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
        if (!empty($this->S['promo'])) {
            unset($this->S['promo']);
            $this->say('auto_level', cb_esc((string)$this->level()['name']), $this->lvl());
            $this->w->buf .= "\x07";
            $this->nl();
        }
        $this->say('time_today', $this->minutesLeft());
        $this->nl();
        $mail = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE private=1 AND to_id=? AND is_read=0', [(int)$this->user['id']]);
        if ($mail > 0) {
            $this->nl();
            $this->say('you_have_mail', $mail);
            $this->w->buf .= "\x07";
            $this->nl();
        }
        $msg = cb_sysop_msg();
        if ($msg) {
            $this->nl();
            $this->say('sysmsg_head', cb_esc(Settings::get('sysop_name', 'Sysop')));
            $this->nl();
            foreach ($msg as $l) {
                $this->write('  |15' . $l . '|07');
                $this->nl();
            }
        }
        // new polls are a line of the box, without the box the hint stands alone
        $counts = $this->newSinceCounts();
        if ($counts === null) {
            $polls = $this->pollsGreeting();
            if ($polls !== '') {
                $this->nl();
                $this->write($polls);
                $this->nl();
            }
        } elseif (!$this->logonHasNewSince()) {
            $this->nl();
            $this->write($this->newSinceBox($counts));
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
        $this->newSincePrompt($counts);
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
        $err = cb_handle_check($v) ?? (cb_banned_handle($v) !== null ? 'taken' : null);
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
        if (($w = cb_banned_word($v)) !== null) {
            $this->say('ban_word', cb_esc($w));
            $this->nl();
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
        $this->S['nu']['qa'] = [];
        $this->go('nu_q', 0);
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
        $pending = Settings::get('new_validate', '0') === '1' ? 1 : 0;
        $id = DB::insert('users', [
            'handle' => $nu['handle'], 'handle_lc' => mb_strtolower($nu['handle']), 'pass' => $nu['hash'],
            'location' => $nu['location'], 'level' => Settings::int('new_level', 10), 'created' => time(),
            'today' => date('Y-m-d'), 'baud' => -1, 'pending' => $pending,
        ]);
        $this->nuStoreAnswers($id, (array)($nu['qa'] ?? []));
        unset($this->S['nu']);
        cb_log($id, $nu['handle'], $pending ? 'New user registered, waiting for validation' : 'New user registered');
        if ($pending) {
            foreach ($this->sysopNodes() as $n) {
                $this->nodeMsg($n, 'page', $this->L('nu_page_sysop',
                    cb_esc((string)$nu['handle'])));
            }
            $this->showPending();
            return;
        }
        $this->say('nu_created');
        $this->doLogin($id);
    }

    /** Screen for users waiting for validation, then hang up after a key. The time does not count. */
    private function showPending(): void
    {
        $this->cls();
        if (!$this->screen('pending')) {
            $this->say('pending_text');
        }
        $this->S['st'] = 'pend_end';
        $this->hot('', $this->L('pause'), true);
    }

    private function i_pend_end(string $v): void
    {
        $this->hangup();
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
            $this->drawMenu($menu, $items);
        }
        $keys = '?';
        foreach ($items as $it) {
            $keys .= mb_strtoupper($it['hotkey']);
        }
        $this->nl();
        $this->hot($keys, $this->L('menu_prompt', cb_esc($menu['title']), $this->minutesLeft()));
    }

    /** Clear the screen and draw the menu: its own screen, or the head screen and the automatic list. */
    private function drawMenu(array $menu, array $items): void
    {
        $this->cls();
        if ($menu['screen'] !== '' && $this->screen($menu['screen'])) {
            return;
        }
        $head = (string)$menu['head_screen'];
        $shown = $head !== '' && $this->headScreen($head);
        if ($head !== '' && !$shown && !$this->preview) {
            // missing head: just the list, logged once a day per menu
            $text = 'Head screen ' . $head . ' of menu ' . $menu['name'] . ' is missing';
            if (!DB::val('SELECT COUNT(*) FROM {log} WHERE text=? AND time>?', [$text, time() - 86400])) {
                cb_log(0, '', $text);
            }
        }
        $this->autoMenu($menu, $items, !$shown || (int)$menu['head_bar'] === 1);
    }

    /** Screen above the automatic list. After an ANSI head the cursor stands in the first column below it. */
    private function headScreen(string $name): bool
    {
        $p = cb_screen_path($name);
        if ($p === null) {
            return false;
        }
        if (!str_ends_with($p, '.ans')) {
            return $this->screen($name);
        }
        $data = cb_macros(cb_strip_sauce((string)file_get_contents($p)), fn($n) => $this->ansMacro($n), true);
        $this->w->raw($data);
        $this->w->buf .= "\e[0m";
        $this->w->reset();
        // the screen was cleared before, so a head of up to 24 lines ends in a known row
        $h = cb_ansi_grid($data, 'ans')['h'] ?? 25;
        if ($h < 25) {
            $this->w->buf .= "\e[" . ($h + 1) . ';1H';
        } elseif (!str_ends_with($data, "\n")) {
            $this->nl();
        }
        return true;
    }

    /** The menu as $user sees it, with the prompt, for the preview in the backend. */
    public function menuPreview(array $menu, array $user): string
    {
        $this->user = $user;
        $this->S['menu'] = $menu['name'];
        $items = DB::all('SELECT * FROM {menu_items} WHERE menu_id=? AND min_level<=? ORDER BY sort, id', [(int)$menu['id'], $this->lvl()]);
        $this->drawMenu($menu, $items);
        $this->nl();
        $this->write($this->L('menu_prompt', cb_esc($menu['title']), $this->minutesLeft()));
        return $this->w->buf;
    }

    private function autoMenu(array $menu, array $items, bool $bar = true): void
    {
        if ($bar) {
            $this->bar(Settings::get('bbs_name', 'WebCarrier BBS'), $menu['title']);
            $this->nl();
        }
        if (in_array('DOOR', array_map('strtoupper', array_column($items, 'command')), true)) {
            $box = $this->doorTopBox();
            if ($box !== '') {
                $this->write($box);
                $this->nl(2);
            }
        }
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
                $this->go('who');
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
            case 'DOORTOP':
                $this->go('dtop', 0);
                break;
            case 'TELECONF':
                $this->go('tele');
                break;
            case 'PROFILE':
                $this->S['profback'] = 'menu';
                $this->go('prof_ask');
                break;
            case 'MSG_SEARCH':
                $this->go('msrch');
                break;
            case 'POLLS':
                $this->go('polls');
                break;
            case 'SYSOP':
                if ($this->isSysop()) {
                    $this->go('sys');
                } else {
                    $this->say('cmd_unknown', cb_esc($cmd));
                    $this->pause();
                }
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
