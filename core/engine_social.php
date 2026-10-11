<?php
/**
 * WebCarrier BBS: meeting point (chat for all callers), messages to a node, profiles,
 * about me and signature, and polls.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

trait EngineSocial
{
    /* ------------------------------------------------------------ meeting point */

    /** Nodes in the meeting point, node => handle. */
    private function teleNodes(int $except = 0): array
    {
        $out = [];
        foreach (DB::all('SELECT node, handle FROM {nodes} WHERE tele=1 AND kicked=0 AND node<>? ORDER BY node', [$except]) as $r) {
            $out[(int)$r['node']] = (string)$r['handle'];
        }
        return $out;
    }

    /** A formatted line (pipe codes, user text already escaped) to everybody in the room except $except. */
    private function teleSend(string $line, int $except): void
    {
        foreach (array_keys($this->teleNodes($except)) as $n) {
            $this->nodeMsg($n, 'tele', $line);
        }
    }

    /** One stored or live line as the room shows it. */
    private function teleFormat(string $handle, string $text): string
    {
        if (preg_match('/^\/me\s+(.+)$/iu', $text, $m)) {
            return '|13* ' . cb_esc($handle) . ' ' . cb_esc($m[1]) . '|07';
        }
        return '|11' . cb_esc($handle) . '|08> |07' . cb_esc($text);
    }

    private function teleWho(): void
    {
        $names = array_values($this->teleNodes());
        $this->say('tele_present', cb_esc(implode(', ', $names)));
        $this->nl();
    }

    private function e_tele(): void
    {
        $node = (int)$this->S['node'];
        DB::q('UPDATE {nodes} SET tele=1 WHERE node=? AND sid=?', [$node, session_id()]);
        DB::q("DELETE FROM {node_msgs} WHERE node=? AND kind='tele'", [$node]);
        $this->act(Lang::get('act_tele'));
        $this->cls();
        $this->bar($this->L('tele_title'));
        $this->nl();
        $this->say('tele_intro');
        $this->nl(2);
        $rows = array_reverse(DB::all('SELECT * FROM {tele_lines} WHERE time>=? ORDER BY id DESC LIMIT 10', [time() - 1800]));
        foreach ($rows as $r) {
            $this->write('|08' . date('H:i', (int)$r['time']) . ' ' . $this->teleFormat((string)$r['handle'], (string)$r['text']));
            $this->nl();
        }
        if ($rows) {
            $this->nl();
        }
        $this->teleWho();
        $this->teleSend('|08' . $this->L('tele_join', cb_esc((string)$this->user['handle'])) . '|07', $node);
        $this->S['st'] = 'tele';
        $this->telePrompt();
    }

    private function telePrompt(): void
    {
        $this->write('|15> ');
        $this->ask = ['t' => 'chat', 'm' => 70];
    }

    /** Leave the room: flag off, pending lines gone, the others get a system line. */
    private function teleLeave(): void
    {
        $node = (int)($this->S['node'] ?? 0);
        if ($node <= 0 || !DB::val('SELECT COUNT(*) FROM {nodes} WHERE node=? AND tele=1', [$node])) {
            return;
        }
        DB::q('UPDATE {nodes} SET tele=0 WHERE node=?', [$node]);
        DB::q("DELETE FROM {node_msgs} WHERE node=? AND kind='tele'", [$node]);
        $this->teleSend('|08' . $this->L('tele_leave', cb_esc((string)($this->user['handle'] ?? ''))) . '|07', $node);
    }

    private function i_tele(string $v): void
    {
        $node = (int)$this->S['node'];
        if (!DB::val('SELECT COUNT(*) FROM {nodes} WHERE node=? AND tele=1', [$node])) {
            $this->menu();
            return;
        }
        $v = CP437::clean($v, 70);
        if ($v !== '' && $v[0] === '/') {
            $cmd = mb_strtoupper((string)strtok($v, ' '));
            if ($cmd === '/Q') {
                $this->teleLeave();
                $this->nl();
                $this->menu();
                return;
            }
            if ($cmd === '/W') {
                $this->teleWho();
            } elseif ($cmd === '/?') {
                $this->say('tele_help');
                $this->nl();
            } elseif ($cmd === '/ME' && trim(mb_substr($v, 3)) !== '') {
                $this->teleLine($v);
            } else {
                $this->say('tele_unknown');
                $this->nl();
            }
        } elseif ($v !== '') {
            $this->teleLine($v);
        }
        $this->deliverNodeMsgs();
        if (($this->S['st'] ?? '') === 'tele') {
            $this->telePrompt();
        }
    }

    /** A line or action of the caller: ban list, store (last 50), send to the others. */
    private function teleLine(string $v): void
    {
        if (($w = $this->bannedWord($v)) !== null) {
            $this->say('ban_word', cb_esc($w));
            $this->nl();
            return;
        }
        $h = (string)$this->user['handle'];
        DB::insert('tele_lines', ['user_id' => (int)$this->user['id'], 'handle' => $h, 'text' => $v, 'time' => time()]);
        $keep = (int)(DB::val('SELECT id FROM {tele_lines} ORDER BY id DESC LIMIT 1 OFFSET 49') ?? 0);
        if ($keep > 0) {
            DB::q('DELETE FROM {tele_lines} WHERE id<?', [$keep]);
        }
        $line = $this->teleFormat($h, $v);
        $this->teleSend($line, (int)$this->S['node']);
        if (str_starts_with($line, '|13')) {
            // own action as the others see it, a normal line is already on the screen
            $this->write($line);
            $this->nl();
        }
    }

    /** System line for a node that vanished without hanging up (closed browser). */
    private function teleGone(int $node, string $handle): void
    {
        foreach (array_keys($this->teleNodes($node)) as $n) {
            $this->nodeMsg($n, 'tele', '|08' . $this->L('tele_leave', cb_esc($handle)) . '|07');
        }
    }

    /* ------------------------------------------------------------ who's online, message to a node */

    private function e_who(): void
    {
        $this->cls();
        $this->bar($this->L('who_title'));
        $this->nl();
        $this->nodeTable();
        $this->nl();
        $this->hot("N\r Q", $this->L('who_prompt'), true);
    }

    private function i_who(string $v): void
    {
        if (strtoupper($v) === 'N') {
            $this->S['st'] = 'who_node';
            $this->line(2, $this->L('nmsg_node'));
            return;
        }
        $this->menu();
    }

    /** Target user of a node message, or an error key. */
    private function nodeMsgTarget(int $n): array
    {
        $row = DB::row('SELECT n.node, n.user_id, u.handle, u.allow_msgs, u.pending, u.locked FROM {nodes} n JOIN {users} u ON u.id=n.user_id
            WHERE n.node=? AND n.kicked=0', [$n]);
        if ($n === (int)$this->S['node']) {
            return ['err' => 'nmsg_self'];
        }
        if (!$row || (int)$row['pending'] === 1 || (int)$row['locked'] === 1) {
            return ['err' => 'nmsg_nobody'];
        }
        if ((int)$row['allow_msgs'] !== 1 && !$this->isSysop()) {
            return ['err' => 'nmsg_refused', 'handle' => (string)$row['handle']];
        }
        return ['node' => $n, 'handle' => (string)$row['handle'], 'uid' => (int)$row['user_id']];
    }

    private function i_who_node(string $v): void
    {
        $v = trim($v);
        if ($v === '' || !ctype_digit($v)) {
            $this->go('who');
            return;
        }
        $t = $this->nodeMsgTarget((int)$v);
        if (isset($t['err'])) {
            $this->say($t['err'], cb_esc($t['handle'] ?? ''), (int)$v);
            $this->nl();
            $this->pause('who');
            return;
        }
        $this->S['nmt'] = $t;
        $this->S['st'] = 'who_text';
        $this->line(70, $this->L('nmsg_text', cb_esc($t['handle'])));
    }

    private function i_who_text(string $v): void
    {
        $t = $this->S['nmt'] ?? null;
        unset($this->S['nmt']);
        $v = CP437::clean($v, 70);
        if (!$t || $v === '') {
            $this->go('who');
            return;
        }
        if (($w = $this->bannedWord($v)) !== null) {
            $this->say('ban_word', cb_esc($w));
            $this->nl();
            $this->pause('who');
            return;
        }
        // the target may have left or changed the setting meanwhile
        $now = $this->nodeMsgTarget((int)$t['node']);
        if (isset($now['err']) || $now['uid'] !== $t['uid']) {
            $this->say($now['err'] ?? 'nmsg_nobody', cb_esc($now['handle'] ?? ''), (int)$t['node']);
            $this->nl();
            $this->pause('who');
            return;
        }
        $uid = (int)$this->user['id'];
        if (DB::val("SELECT COUNT(*) FROM {log} WHERE user_id=? AND time>? AND text LIKE 'Node message from %'", [$uid, time() - 10])) {
            $this->say('nmsg_flood');
            $this->nl();
            $this->pause('who');
            return;
        }
        $this->nodeMsg((int)$t['node'], 'nmsg', $v);
        cb_log($uid, (string)$this->user['handle'], 'Node message from ' . $this->user['handle'] . ' to ' . $t['handle'] . ' (node ' . $t['node'] . ')');
        $this->say('nmsg_sent', cb_esc($t['handle']));
        $this->nl();
        $this->pause('who');
    }

    /* ------------------------------------------------------------ profiles */

    private function e_prof_ask(): void
    {
        $this->nl();
        $this->line(20, $this->L('prof_ask'));
    }

    private function i_prof_ask(string $v): void
    {
        $v = CP437::clean($v, 20);
        $back = (string)($this->S['profback'] ?? 'menu');
        unset($this->S['profback']);
        if ($v === '') {
            $this->profileBack($back);
            return;
        }
        $u = DB::row('SELECT * FROM {users} WHERE handle_lc=?', [mb_strtolower($v)]);
        $this->showProfile($u, $back);
    }

    private function profileBack(string $back): void
    {
        if ($back === 'pager' && !empty($this->S['pg'])) {
            $this->pagerRedraw();
            return;
        }
        if ($back === 'reader' && !empty($this->S['rd'])) {
            $this->go('rmsg');
            return;
        }
        $this->menu();
    }

    /** Profile of a user. Locked users and users waiting for validation do not exist for others. */
    private function showProfile(?array $u, string $back): void
    {
        $own = $u && (int)$u['id'] === (int)$this->user['id'];
        if (!$u || (!$own && ((int)$u['locked'] === 1 || (int)$u['pending'] === 1))) {
            $this->nl();
            $this->say('prof_none');
            $this->nl();
            $this->pause($back === 'menu' ? 'menu' : 'profback', $back === 'menu' ? [] : [$back]);
            return;
        }
        $lvName = (string)(DB::val('SELECT name FROM {levels} WHERE level<=? ORDER BY level DESC', [(int)$u['level']]) ?? '');
        $posts = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE from_id=? AND private=0', [(int)$u['id']]);
        $this->cls();
        $this->bar($this->L('prof_title'), (string)$u['handle']);
        $this->nl();
        $rows = [
            ['ui_handle', cb_esc((string)$u['handle'])],
            ['ui_location', cb_esc((string)$u['location'])],
            ['ui_level', cb_esc($lvName)],
            ['ui_member', cb_date((int)$u['created'])],
            ['prof_lastcall', cb_date((int)$u['last_call'])],
            ['ui_calls', (string)$u['calls']],
            ['prof_posts', (string)$posts],
            ['prof_uploads', (string)$u['ul_files']],
        ];
        foreach ($rows as [$k, $val]) {
            $this->write('  |03' . cb_pad($this->L($k), 22) . '|15' . $val);
            $this->nl();
        }
        if (trim((string)$u['about']) !== '') {
            $this->nl();
            $this->write('  |03' . $this->L('prof_about'));
            $this->nl();
            foreach (explode("\n", (string)$u['about']) as $l) {
                $this->write('    |07' . cb_esc($l));
                $this->nl();
            }
        }
        $best = [];
        $doors = cb_doors();
        foreach (DB::all('SELECT door FROM {door_scores} WHERE user_id=?', [(int)$u['id']]) as $r) {
            $d = $doors[(string)$r['door']] ?? null;
            if ($d && ($rank = cb_door_rank($d['id'], $d['score'], (int)$u['id'])) !== null) {
                $best[] = [$rank[0], $d['name'], cb_score_label($rank[1])];
            }
        }
        if ($best) {
            usort($best, static fn($a, $b) => $a[0] <=> $b[0] ?: strnatcasecmp($a[1], $b[1]));
            $this->nl();
            $this->write('  |03' . $this->L('prof_scores'));
            $this->nl();
            foreach (array_slice($best, 0, 5) as [$rank, $name, $label]) {
                $this->write('    |07' . cb_esc(cb_pad($name, 28)) . ' |14' . cb_esc(cb_pad($label, 20)) . ' |08' . $this->L('prof_rank', $rank) . '|07');
                $this->nl();
            }
        }
        $this->nl();
        $this->pause($back === 'menu' ? 'menu' : 'profback', $back === 'menu' ? [] : [$back]);
    }

    private function e_profback(string $back = 'menu'): void
    {
        $this->profileBack($back);
    }

    /* ------------------------------------------------------------ about me and signature */

    /** Multi line input in the settings: $field about (3 x 60) or signature (2 x 70). */
    private function startLines(string $field): void
    {
        [$max, $width] = $field === 'about' ? [3, 60] : [2, 70];
        $this->S['ml'] = ['f' => $field, 'max' => $max, 'w' => $width, 'lines' => []];
        $this->nl();
        $this->say($field === 'about' ? 'uset_about_help' : 'uset_sig_help', $max, $width);
        $this->nl();
        $this->S['st'] = 'uset_lines';
        $this->line($width, $this->L('uset_line', 1));
    }

    private function i_uset_lines(string $v): void
    {
        $ml = &$this->S['ml'];
        $v = CP437::clean($v, (int)$ml['w']);
        if ($v !== '') {
            $ml['lines'][] = $v;
        }
        if ($v !== '' && count($ml['lines']) < (int)$ml['max']) {
            $this->line((int)$ml['w'], $this->L('uset_line', count($ml['lines']) + 1));
            return;
        }
        $field = (string)$ml['f'];
        $text = implode("\n", $ml['lines']);
        unset($this->S['ml']);
        if ($text !== '' && ($w = $this->bannedWord($text)) !== null) {
            $this->say('ban_word', cb_esc($w));
            $this->nl();
            $this->pause('uset');
            return;
        }
        DB::q('UPDATE {users} SET ' . ($field === 'about' ? 'about' : 'signature') . '=? WHERE id=?', [$text, (int)$this->user['id']]);
        $this->user[$field] = $text;
        $this->say($text === '' ? 'uset_lines_cleared' : 'uset_lines_saved');
        $this->nl();
        $this->pause('uset');
    }

    /* ------------------------------------------------------------ polls */

    /** Open: not closed and the last day not over. */
    private static function pollOpen(array $p): bool
    {
        return (int)$p['closed'] === 0 && ((string)$p['closes'] === '' || date('Y-m-d') <= (string)$p['closes']);
    }

    /** Open polls without a vote of the caller. */
    public function newPolls(): int
    {
        $n = 0;
        foreach (DB::all('SELECT p.* FROM {polls} p WHERE p.closed=0 AND NOT EXISTS (SELECT 1 FROM {poll_votes} v WHERE v.poll_id=p.id AND v.user_id=?)',
            [(int)$this->user['id']]) as $p) {
            $n += self::pollOpen($p) ? 1 : 0;
        }
        return $n;
    }

    private function e_polls(): void
    {
        $this->act(Lang::get('act_polls'));
        $all = DB::all('SELECT * FROM {polls} ORDER BY id DESC');
        $open = array_values(array_filter($all, [self::class, 'pollOpen']));
        $ended = array_slice(array_values(array_filter($all, static fn($p) => !self::pollOpen($p))), 0, 5);
        $this->cls();
        $this->bar($this->L('polls_title'));
        $this->nl();
        if (!$open && !$ended) {
            $this->say('polls_none');
            $this->nl(2);
            $this->pause();
            return;
        }
        $list = [];
        $voted = array_flip(array_map('intval', array_column(DB::all('SELECT poll_id FROM {poll_votes} WHERE user_id=?', [(int)$this->user['id']]), 'poll_id')));
        foreach ([[$open, 'polls_open'], [$ended, 'polls_ended']] as [$rows, $head]) {
            if (!$rows) {
                continue;
            }
            $this->write('|03' . $this->L($head));
            $this->nl();
            foreach ($rows as $p) {
                $list[] = (int)$p['id'];
                $new = self::pollOpen($p) && !isset($voted[(int)$p['id']]);
                $this->write(' |15' . cb_pad((string)count($list), 3, 'R') . '  |07' . cb_esc(cb_pad((string)$p['question'], 64)) . ($new ? '|12' . $this->L('polls_new_mark') : ''));
                $this->nl();
            }
            $this->nl();
        }
        $this->S['pl'] = $list;
        $this->line(3, $this->L('polls_prompt'));
    }

    private function i_polls(string $v): void
    {
        $v = trim($v);
        $id = ctype_digit($v) ? ($this->S['pl'][(int)$v - 1] ?? null) : null;
        if ($id === null) {
            unset($this->S['pl']);
            $this->menu();
            return;
        }
        $this->go('poll', (int)$id);
    }

    private function e_poll(int $id): void
    {
        $p = DB::row('SELECT * FROM {polls} WHERE id=?', [$id]);
        if (!$p) {
            $this->go('polls');
            return;
        }
        $opts = DB::all('SELECT * FROM {poll_options} WHERE poll_id=? ORDER BY sort, id', [$id]);
        $mine = DB::val('SELECT option_id FROM {poll_votes} WHERE poll_id=? AND user_id=?', [$id, (int)$this->user['id']]);
        $this->cls();
        $this->bar($this->L('polls_title'), self::pollOpen($p) ? '' : $this->L('polls_ended_tag'));
        $this->nl();
        $this->write('|15' . cb_esc((string)$p['question']));
        $this->nl(2);
        if ($mine !== null || !self::pollOpen($p)) {
            $this->pollResult($p, $opts, $mine === null ? 0 : (int)$mine);
            $this->nl();
            $this->pause('polls');
            return;
        }
        foreach ($opts as $i => $o) {
            $this->write('  |08[|15' . ($i + 1) . '|08] |07' . cb_esc((string)$o['text']));
            $this->nl();
        }
        if ((string)$p['closes'] !== '') {
            $this->nl();
            $this->say('polls_until', cb_date((int)strtotime((string)$p['closes'])));
            $this->nl();
        }
        $this->nl();
        $this->S['pv'] = ['poll' => $id];
        $this->S['st'] = 'poll_vote';
        $this->hot(implode('', array_map('strval', range(1, count($opts)))) . "Q\r", $this->L('polls_vote_prompt', count($opts)));
    }

    private function pollResult(array $p, array $opts, int $mine): void
    {
        $counts = [];
        foreach (DB::all('SELECT option_id, COUNT(*) AS n FROM {poll_votes} WHERE poll_id=? GROUP BY option_id', [(int)$p['id']]) as $r) {
            $counts[(int)$r['option_id']] = (int)$r['n'];
        }
        $total = array_sum($counts);
        foreach ($opts as $i => $o) {
            $n = $counts[(int)$o['id']] ?? 0;
            $pct = $total > 0 ? (int)round($n * 100 / $total) : 0;
            $full = (int)round($pct / 5);
            $this->write(($mine === (int)$o['id'] ? '|14>' : ' ') . '|15' . cb_pad((string)($i + 1), 2, 'R') . ' |07' . cb_esc(cb_pad((string)$o['text'], 40)) .
                ' |11' . str_repeat('█', $full) . '|08' . str_repeat('░', 20 - $full) . ' |15' . cb_pad($pct . '%', 4, 'R') . ' |07' . cb_pad('(' . $n . ')', 7, 'R'));
            $this->nl();
        }
        $this->nl();
        $this->say('polls_total', $total);
        $this->nl();
    }

    private function i_poll_vote(string $v): void
    {
        $id = (int)($this->S['pv']['poll'] ?? 0);
        $opts = DB::all('SELECT * FROM {poll_options} WHERE poll_id=? ORDER BY sort, id', [$id]);
        $o = ctype_digit($v) ? ($opts[(int)$v - 1] ?? null) : null;
        if (!$o) {
            unset($this->S['pv']);
            $this->go('polls');
            return;
        }
        $this->S['pv']['opt'] = (int)$o['id'];
        $this->nl();
        $this->say('polls_choice', cb_esc((string)$o['text']));
        $this->nl();
        $this->S['st'] = 'poll_confirm';
        $this->yn($this->L('polls_confirm'), true);
    }

    private function i_poll_confirm(string $v): void
    {
        $pv = $this->S['pv'] ?? [];
        unset($this->S['pv']);
        $p = DB::row('SELECT * FROM {polls} WHERE id=?', [(int)($pv['poll'] ?? 0)]);
        $uid = (int)$this->user['id'];
        if (!$this->yes($v, true) || !$p) {
            $this->go('polls');
            return;
        }
        $u = DB::row('SELECT locked, pending FROM {users} WHERE id=?', [$uid]);
        if (self::pollOpen($p) && $u && (int)$u['locked'] === 0 && (int)$u['pending'] === 0
            && !DB::val('SELECT COUNT(*) FROM {poll_votes} WHERE poll_id=? AND user_id=?', [(int)$p['id'], $uid])
            && DB::val('SELECT COUNT(*) FROM {poll_options} WHERE id=? AND poll_id=?', [(int)$pv['opt'], (int)$p['id']])) {
            DB::insert('poll_votes', ['poll_id' => (int)$p['id'], 'user_id' => $uid, 'option_id' => (int)$pv['opt'], 'time' => time()]);
            $this->nl();
            $this->say('polls_thanks');
            $this->nl();
        }
        $this->go('poll', (int)$p['id']);
    }

    /** Line for the greeting after the login, or ''. */
    private function pollsGreeting(): string
    {
        $n = $this->newPolls();
        if ($n === 0) {
            return '';
        }
        $key = DB::val("SELECT i.hotkey FROM {menu_items} i JOIN {menus} m ON m.id=i.menu_id WHERE m.name='main' AND i.command='POLLS' AND i.min_level<=?",
            [$this->lvl()]);
        $k = $n === 1 ? 'polls_greet_one' : 'polls_greet';
        return $key !== null ? $this->L($k . '_key', $n, cb_esc(mb_strtoupper((string)$key))) : $this->L($k, $n);
    }
}
