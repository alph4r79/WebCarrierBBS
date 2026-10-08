<?php
/**
 * WebCarrier BBS: oneliners, caller log, who's online, user list, user settings,
 * sysop paging, doors and legal texts.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

interface CarrierDoor
{
    /** Called once when the user enters the door. */
    public function start(Engine $e): void;

    /** Called with every input while the door is active. */
    public function input(Engine $e, string $v): void;
}

trait EngineMisc
{
    /* ------------------------------------------------------------ legal texts */

    private function legal(string $which): void
    {
        $which = $which === 'privacy' ? 'privacy' : 'impressum';
        $text = trim(Settings::get('legal_' . $which));
        if ($text === '') {
            $this->nl();
            $this->say('legal_missing');
            $this->nl();
            $this->pause();
            return;
        }
        $lines = array_map(static fn($l) => '|07' . cb_esc($l), cb_wrap($text, 79));
        $this->startPager('lines', array_keys($lines), $this->L($which === 'privacy' ? 'legal_privacy' : 'legal_impressum'), $lines);
    }

    /* ------------------------------------------------------------ oneliners */

    private function e_one(): void
    {
        $this->act(Lang::get('act_oneliners'));
        $this->cls();
        $this->bar($this->L('oneliners_title'));
        $this->nl();
        $rows = array_reverse(DB::all('SELECT * FROM {oneliners} ORDER BY id DESC LIMIT 15'));
        if (!$rows) {
            $this->say('oneliners_empty');
            $this->nl();
        }
        foreach ($rows as $r) {
            $this->write('|11' . cb_esc(cb_pad($r['handle'], 16)) . '|08: |07' . cb_esc($r['text']));
            $this->nl();
        }
        $this->nl();
        $this->S['st'] = 'one_ask';
        $this->yn($this->L('oneliners_add'), false);
    }

    private function i_one_ask(string $v): void
    {
        if (!$this->yes($v, false)) {
            $this->menu();
            return;
        }
        $this->S['st'] = 'one_add';
        $this->line(60, $this->L('oneliners_prompt'));
    }

    private function i_one_add(string $v): void
    {
        $v = CP437::clean($v, 60);
        if ($v !== '') {
            DB::insert('oneliners', ['user_id' => (int)$this->user['id'], 'handle' => $this->user['handle'], 'text' => $v, 'time' => time()]);
            $keep = (int)(DB::val('SELECT id FROM {oneliners} ORDER BY id DESC LIMIT 1 OFFSET 199') ?? 0);
            if ($keep > 0) {
                DB::q('DELETE FROM {oneliners} WHERE id<?', [$keep]);
            }
        }
        $this->go('one');
    }

    /* ------------------------------------------------------------ callers, who, users */

    private function lastCallers(): void
    {
        $this->act(Lang::get('act_lastcallers'));
        $this->cls();
        $this->bar($this->L('lastcallers_title'));
        $this->nl();
        $this->write('|03' . cb_pad($this->L('col_node'), 6) . cb_pad($this->L('col_user'), 22) . cb_pad($this->L('col_loc'), 32) . $this->L('col_when'));
        $this->nl();
        $this->rule();
        foreach (DB::all('SELECT * FROM {calls} ORDER BY id DESC LIMIT 15') as $c) {
            $this->write('|15' . cb_pad((string)$c['node'], 6) . '|11' . cb_esc(cb_pad($c['handle'], 22)) . '|07' .
                cb_esc(cb_pad($c['location'], 32)) . '|03' . date(Lang::get('fmt_date') . ' H:i', (int)$c['time']));
            $this->nl();
        }
        $this->nl();
        $this->pause();
    }

    private function whoOnline(): void
    {
        $this->cls();
        $this->bar($this->L('who_title'));
        $this->nl();
        $this->nodeTable();
        $this->nl();
        $this->pause();
    }

    /** All nodes with user, activity and time, also used by the sysop menu. */
    private function nodeTable(): void
    {
        $this->write('|03' . cb_pad($this->L('col_node'), 6) . cb_pad($this->L('col_user'), 22) . cb_pad($this->L('col_activity'), 38) . $this->L('col_since'));
        $this->nl();
        $this->rule();
        $rows = DB::all('SELECT * FROM {nodes} ORDER BY node');
        $byNode = [];
        foreach ($rows as $r) {
            $byNode[(int)$r['node']] = $r;
        }
        $max = max(1, Settings::int('max_nodes', 4));
        for ($n = 1; $n <= $max; $n++) {
            $r = $byNode[$n] ?? null;
            if (!$r) {
                $this->write('|15' . cb_pad((string)$n, 6) . '|08' . $this->L('who_waiting'));
            } else {
                $who = $r['handle'] !== '' ? $r['handle'] : $this->L('who_logging_in');
                $this->write('|15' . cb_pad((string)$n, 6) . '|11' . cb_esc(cb_pad($who, 22)) . '|07' .
                    cb_esc(cb_pad($r['activity'], 38)) . '|03' . date('H:i', (int)$r['since']));
            }
            $this->nl();
        }
    }

    private function userList(): void
    {
        $this->act(Lang::get('act_userlist'));
        $ids = array_map('intval', array_column(DB::all('SELECT id FROM {users} WHERE locked=0 AND pending=0 ORDER BY handle_lc'), 'id'));
        $this->startPager('users', $ids, $this->L('userlist_title', count($ids)));
    }

    private function userInfo(): void
    {
        $u = $this->user;
        $lv = $this->level();
        $this->cls();
        $this->bar($this->L('userinfo_title'), $u['handle']);
        $this->nl();
        $rows = [
            ['ui_handle', cb_esc($u['handle'])],
            ['ui_location', cb_esc($u['location'])],
            ['ui_level', $u['level'] . ' (' . cb_esc((string)$lv['name']) . ')'],
            ['ui_member', cb_date((int)$u['created'])],
            ['ui_calls', (string)$u['calls']],
            ['ui_lastcall', cb_date((int)($this->S['prevcall'] ?? 0))],
            ['ui_posts', (string)$u['posts']],
            ['ui_uploads', $u['ul_files'] . ' / ' . $u['ul_kb'] . 'k'],
            ['ui_downloads', $u['dl_files'] . ' / ' . $u['dl_kb'] . 'k'],
            ['ui_timeleft', $this->minutesLeft() . ' min'],
            ['ui_dltoday', $u['dl_kb_today'] . 'k' . ((int)$lv['dl_kb'] > 0 ? ' / ' . $lv['dl_kb'] . 'k' : '')],
            ['ui_ratio', (int)$lv['ratio'] > 0 ? '1:' . $lv['ratio'] : $this->L('off')],
        ];
        foreach ($rows as [$k, $val]) {
            $this->write('  |03' . cb_pad($this->L($k), 27) . '|15' . $val);
            $this->nl();
        }
        $this->nl();
        $this->pause();
    }

    /* ------------------------------------------------------------ personal settings */

    private static function bauds(): array
    {
        return ['1' => 300, '2' => 1200, '3' => 2400, '4' => 9600, '5' => 14400, '6' => 28800, '7' => 57600, '8' => 0, '9' => -1];
    }

    private function baudName(int $b): string
    {
        if ($b < 0) {
            return $this->L('baud_default', $this->baudName(Settings::int('baud', 14400)));
        }
        return $b === 0 ? $this->L('baud_off') : $b . ' bps';
    }

    private function e_uset(): void
    {
        $this->act(Lang::get('act_settings'));
        $u = $this->user;
        $this->cls();
        $this->bar($this->L('uset_title'));
        $this->nl();
        $rows = [
            ['L', 'uset_location', cb_esc($u['location'])],
            ['P', 'uset_password', '********'],
            ['B', 'uset_baud', $this->baudName((int)$u['baud'])],
            ['E', 'uset_expert', (int)$u['expert'] === 1 ? $this->L('on') : $this->L('off')],
        ];
        foreach ($rows as [$k, $label, $val]) {
            $this->write('  |08[|15' . $k . '|08] |07' . cb_pad($this->L($label), 31) . '|11' . $val);
            $this->nl();
        }
        $this->write('  |08[|15Q|08] |07' . $this->L('back'));
        $this->nl(2);
        $this->hot("LPBEQ\r", $this->L('choose'));
    }

    private function i_uset(string $v): void
    {
        $uid = (int)$this->user['id'];
        switch (strtoupper($v)) {
            case 'L':
                $this->S['st'] = 'uset_loc';
                $this->line(30, $this->L('nu_location'));
                return;
            case 'P':
                $this->S['st'] = 'uset_pw_old';
                $this->line(40, $this->L('uset_pw_old'), true);
                return;
            case 'B':
                $this->nl();
                foreach (self::bauds() as $k => $b) {
                    $this->write('  |08[|15' . $k . '|08] |07' . $this->baudName($b));
                    $this->nl();
                }
                $this->S['st'] = 'uset_baud';
                $this->hot('123456789Q', $this->L('choose'));
                return;
            case 'E':
                DB::q('UPDATE {users} SET expert=? WHERE id=?', [(int)$this->user['expert'] === 1 ? 0 : 1, $uid]);
                $this->user = DB::row('SELECT * FROM {users} WHERE id=?', [$uid]);
                $this->go('uset');
                return;
        }
        $this->menu();
    }

    private function i_uset_loc(string $v): void
    {
        $v = CP437::clean($v, 30);
        if (mb_strlen($v) >= 2) {
            DB::q('UPDATE {users} SET location=? WHERE id=?', [$v, (int)$this->user['id']]);
            $this->user['location'] = $v;
        }
        $this->go('uset');
    }

    private function i_uset_baud(string $v): void
    {
        if (isset(self::bauds()[$v])) {
            DB::q('UPDATE {users} SET baud=? WHERE id=?', [self::bauds()[$v], (int)$this->user['id']]);
            $this->user['baud'] = self::bauds()[$v];
        }
        $this->go('uset');
    }

    private function i_uset_pw_old(string $v): void
    {
        if (!password_verify($v, $this->user['pass'])) {
            $this->say('login_wrong');
            $this->pause('uset');
            return;
        }
        $this->S['st'] = 'uset_pw_new';
        $this->line(40, $this->L('nu_pass'), true);
    }

    private function i_uset_pw_new(string $v): void
    {
        if (mb_strlen($v) < 6) {
            $this->say('nu_pass_short');
            $this->pause('uset');
            return;
        }
        $this->S['pwh'] = password_hash($v, PASSWORD_DEFAULT);
        $this->S['st'] = 'uset_pw_new2';
        $this->line(40, $this->L('nu_pass2'), true);
    }

    private function i_uset_pw_new2(string $v): void
    {
        $h = (string)($this->S['pwh'] ?? '');
        unset($this->S['pwh']);
        if (!password_verify($v, $h)) {
            $this->say('nu_pass_mismatch');
            $this->pause('uset');
            return;
        }
        DB::q('UPDATE {users} SET pass=? WHERE id=?', [$h, (int)$this->user['id']]);
        cb_log((int)$this->user['id'], $this->user['handle'], 'Password changed');
        $this->say('uset_pw_ok');
        $this->nl();
        $this->pause('uset');
    }

    /* ------------------------------------------------------------ page sysop */

    private function pageSysop(): void
    {
        $online = array_values(array_diff($this->sysopNodes(), [(int)$this->S['node']]));
        if ($online) {
            foreach ($online as $n) {
                $this->nodeMsg($n, 'page', $this->L('page_notify', $this->user['handle'], (int)$this->S['node']));
            }
            cb_log((int)$this->user['id'], $this->user['handle'], 'Paged the sysop');
            $this->nl();
            $this->say('page_sent');
            $this->nl();
            $this->pause();
            return;
        }
        $this->nl();
        $this->say('page_start');
        $this->w->buf .= ' ';
        for ($i = 0; $i < 5; $i++) {
            $this->w->buf .= "\x07" . str_repeat('.', 6);
        }
        $this->nl();
        $this->say('page_noanswer');
        $this->nl();
        $this->S['st'] = 'page_ask';
        $this->yn($this->L('page_leave'), true);
    }

    private function i_page_ask(string $v): void
    {
        if (!$this->yes($v, true)) {
            $this->menu();
            return;
        }
        $this->startPost(['private' => 1, 'area_id' => 0, 'to' => $this->sysopHandle(), 'to_id' => $this->sysopId()]);
    }

    /* ------------------------------------------------------------ doors */

    private function openDoor(string $id): void
    {
        $doors = cb_doors();
        if (!isset($doors[$id]) || !class_exists($doors[$id]['class'])) {
            $this->say('door_missing', cb_esc($id));
            $this->nl();
            $this->pause();
            return;
        }
        $this->S['door'] = $id;
        $this->S['dd'] = [];
        $this->S['st'] = 'door';
        $this->act(Lang::get('act_door', [$doors[$id]['name']]));
        $cls = $doors[$id]['class'];
        (new $cls())->start($this);
    }

    private function i_door(string $v): void
    {
        $doors = cb_doors();
        $id = (string)($this->S['door'] ?? '');
        if (!isset($doors[$id])) {
            $this->menu();
            return;
        }
        $this->S['st'] = 'door';
        $cls = $doors[$id]['class'];
        (new $cls())->input($this, $v);
    }

    /** Door helpers: per user and per door storage. */
    public function doorGet(string $k, ?int $uid = null): ?string
    {
        $v = DB::val('SELECT v FROM {door_data} WHERE door=? AND user_id=? AND k=?',
            [(string)($this->S['door'] ?? ''), $uid ?? (int)$this->user['id'], $k]);
        return $v === null ? null : (string)$v;
    }

    public function doorSet(string $k, string $v, ?int $uid = null): void
    {
        $door = (string)($this->S['door'] ?? '');
        $uid = $uid ?? (int)$this->user['id'];
        DB::q('DELETE FROM {door_data} WHERE door=? AND user_id=? AND k=?', [$door, $uid, $k]);
        DB::insert('door_data', ['door' => $door, 'user_id' => $uid, 'k' => $k, 'v' => $v]);
    }

    public function doorAll(string $k): array
    {
        return DB::all('SELECT d.user_id, d.v, u.handle FROM {door_data} d LEFT JOIN {users} u ON u.id=d.user_id WHERE d.door=? AND d.k=?',
            [(string)($this->S['door'] ?? ''), $k]);
    }

    /** Persistent state of the running door for this session. */
    public function &doorState(): array
    {
        if (!isset($this->S['dd']) || !is_array($this->S['dd'])) {
            $this->S['dd'] = [];
        }
        return $this->S['dd'];
    }

    public function leaveDoor(): void
    {
        unset($this->S['door'], $this->S['dd']);
        $this->menu();
    }
}
