<?php
/**
 * WebCarrier BBS: sysop menu in the terminal, node messages (broadcast, page, chat) and kicking.
 * Node messages go through the table node_msgs, the terminal fetches them with poll.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

trait EngineSysop
{
    /* ------------------------------------------------------------ node messages */

    /** Queue a message for a node (kinds: msg, chat, line, end, page). */
    public function nodeMsg(int $node, string $kind, string $text): void
    {
        if ($kind === 'end') {
            // a chat request the other side has not picked up yet is void now
            DB::q("DELETE FROM {node_msgs} WHERE node=? AND kind='chat' AND from_node=?", [$node, (int)($this->S['node'] ?? 0)]);
        }
        DB::insert('node_msgs', ['node' => $node, 'kind' => $kind, 'from_node' => (int)($this->S['node'] ?? 0),
            'from_handle' => (string)($this->user['handle'] ?? ''), 'text' => mb_substr($text, 0, 255), 'time' => time()]);
    }

    /** Nodes with a logged in sysop. */
    public function sysopNodes(): array
    {
        return array_map('intval', array_column(DB::all('SELECT n.node FROM {nodes} n JOIN {users} u ON u.id=n.user_id
            WHERE n.kicked=0 AND u.locked=0 AND u.pending=0 AND u.level>=? ORDER BY n.node', [Settings::int('sysop_level', 255)]), 'node'));
    }

    /** Write all waiting messages of this node. A chat request waits while the caller is in the editor or uploading. */
    private function deliverNodeMsgs(): void
    {
        $node = (int)$this->S['node'];
        $busy = in_array($this->S['st'] ?? '', ['p_edit', 'fup', 'fup_diz', 'fup_desc'], true);
        foreach (DB::all('SELECT * FROM {node_msgs} WHERE node=? ORDER BY id', [$node]) as $r) {
            if ($r['kind'] === 'chat' && $busy && empty($this->S['chat'])) {
                continue;
            }
            DB::q('DELETE FROM {node_msgs} WHERE id=?', [(int)$r['id']]);
            $chat = $this->S['chat'] ?? null;
            $fromPeer = $chat && (int)$r['from_node'] === (int)$chat['peer'];
            switch ($r['kind']) {
                case 'msg':
                    $this->write('|14' . $this->L('bc_line', cb_esc((string)$r['text'])) . '|07');
                    $this->w->buf .= "\x07\r\n";
                    break;
                case 'page':
                    $this->write('|14' . $r['text'] . '|07');
                    $this->w->buf .= "\x07\r\n";
                    break;
                case 'chat':
                    if (!$chat) {
                        $this->joinChat((int)$r['from_node'], (string)$r['from_handle']);
                    }
                    break;
                case 'line':
                    if ($fromPeer) {
                        $this->write('|11' . cb_esc((string)$r['from_handle']) . '|08> |11' . cb_esc((string)$r['text']) . '|07');
                        $this->nl();
                    }
                    break;
                case 'end':
                    if ($fromPeer) {
                        $this->endChat();
                    }
                    break;
            }
        }
        if (!empty($this->S['chat']) && !$this->chatPeerOnline()) {
            $this->endChat();
        }
    }

    /* ------------------------------------------------------------ chat */

    private function chatPeerOnline(): bool
    {
        $c = $this->S['chat'];
        return (bool)DB::val('SELECT COUNT(*) FROM {nodes} WHERE node=? AND handle=? AND kicked=0', [(int)$c['peer'], (string)$c['handle']]);
    }

    /** Called on the caller's side when the sysop starts a chat. */
    private function joinChat(int $peer, string $handle): void
    {
        $this->S['chat'] = ['peer' => $peer, 'handle' => $handle];
        $this->S['st'] = 'chat';
        $this->act(Lang::get('act_chat'));
        $this->nl();
        $this->say('chat_joined');
        $this->nl();
        $this->nodeMsg($peer, 'page', $this->L('chat_peer_joined', cb_esc((string)$this->user['handle'])));
        $this->chatPrompt();
    }

    private function chatPrompt(): void
    {
        $this->write('|15> ');
        $this->ask = ['t' => 'chat', 'm' => 70];
    }

    private function endChat(): void
    {
        unset($this->S['chat']);
        $this->nl();
        $this->say('chat_end');
        $this->nl();
        $this->S['menu'] = 'main';
        $this->menu();
    }

    private function i_chat(string $v): void
    {
        if (empty($this->S['chat']) || !$this->chatPeerOnline()) {
            $this->endChat();
            return;
        }
        $v = CP437::clean($v, 70);
        if (mb_strtolower($v) === '/q') {
            $this->nodeMsg((int)$this->S['chat']['peer'], 'end', '');
            $this->endChat();
            return;
        }
        if ($v !== '') {
            $this->nodeMsg((int)$this->S['chat']['peer'], 'line', $v);
        }
        $this->deliverNodeMsgs();
        if (($this->S['st'] ?? '') === 'chat') {
            $this->chatPrompt();
        }
    }

    /* ------------------------------------------------------------ sysop menu */

    private function sysLog(string $text): void
    {
        cb_log((int)$this->user['id'], $this->user['handle'], $text);
    }

    /** Highest level the sysop may assign: the own one. */
    private function sysMaxLevel(): int
    {
        return min(255, $this->lvl());
    }

    private function e_sys(): void
    {
        $this->act(Lang::get('act_sysop'));
        $this->cls();
        $this->bar($this->L('sys_title'), Settings::get('bbs_name', 'WebCarrier BBS'));
        $this->nl();
        $pending = (int)DB::val('SELECT COUNT(*) FROM {users} WHERE pending=1');
        $uploads = (int)DB::val('SELECT COUNT(*) FROM {files} WHERE approved=0');
        $items = [
            ['N', $this->L('sys_n') . ($pending ? ' (' . $pending . ')' : '')],
            ['U', $this->L('sys_u') . ($uploads ? ' (' . $uploads . ')' : '')],
            ['E', $this->L('sys_e')], ['T', $this->L('sys_t')], ['R', $this->L('sys_r')], ['C', $this->L('sys_c')], ['Q', $this->L('sys_q')],
        ];
        $col = array_map(static fn($i) => '|08[|15' . $i[0] . '|08] |07' . cb_pad($i[1], 33), $items);
        $half = (int)ceil(count($col) / 2);
        for ($i = 0; $i < $half; $i++) {
            $this->write('  ' . $col[$i] . (isset($col[$i + $half]) ? '  ' . $col[$i + $half] : ''));
            $this->nl();
        }
        $this->nl();
        $this->hot('NUETRCQ', $this->L('sys_prompt'));
    }

    private function i_sys(string $v): void
    {
        switch (strtoupper($v)) {
            case 'N':
                $this->sysStartReview();
                return;
            case 'U':
                $this->sysStartUploads();
                return;
            case 'E':
                $this->go('sys_edit_h');
                return;
            case 'T':
                $this->go('sys_kick');
                return;
            case 'R':
                $this->go('sys_bc');
                return;
            case 'C':
                $this->go('sys_chatn');
                return;
            case 'Q':
                $this->S['menu'] = 'main';
                $this->menu();
                return;
        }
        $this->go('sys');
    }

    /* ------------------------------------------------------------ N: review new users */

    private function sysStartReview(): void
    {
        $ids = array_merge(
            array_column(DB::all('SELECT id FROM {users} WHERE pending=1 ORDER BY created, id'), 'id'),
            array_column(DB::all('SELECT id FROM {users} WHERE pending=0 AND level=? AND id<>? ORDER BY created, id',
                [Settings::int('new_level', 10), (int)$this->user['id']]), 'id')
        );
        if (!$ids) {
            $this->nl();
            $this->say('rev_empty');
            $this->nl();
            $this->pause('sys');
            return;
        }
        $this->S['sr'] = ['ids' => array_map('intval', $ids), 'i' => 0];
        $this->go('sys_rev');
    }

    private function sysReviewUser(): ?array
    {
        $sr = $this->S['sr'] ?? null;
        $id = $sr['ids'][$sr['i'] ?? 0] ?? 0;
        return $id ? DB::row('SELECT * FROM {users} WHERE id=?', [$id]) : null;
    }

    private function sysReviewNext(): void
    {
        $this->S['sr']['i']++;
        $this->pause('sys_rev');
    }

    private function e_sys_rev(): void
    {
        $sr = &$this->S['sr'];
        while (isset($sr['ids'][$sr['i']]) && !$this->sysReviewUser()) {
            $sr['i']++;
        }
        $u = $this->sysReviewUser();
        if (!$u) {
            unset($this->S['sr']);
            $this->nl();
            $this->say('rev_end');
            $this->nl();
            $this->pause('sys');
            return;
        }
        $pending = (int)$u['pending'] === 1;
        $this->cls();
        $this->bar($this->L('rev_title'), $this->L('n_of', $sr['i'] + 1, count($sr['ids'])));
        $this->nl();
        foreach ([['ui_handle', cb_esc($u['handle'])], ['ui_location', cb_esc($u['location'])], ['ui_member', cb_date((int)$u['created'])],
                     ['ui_calls', (string)$u['calls']], ['ui_posts', (string)$u['posts']], ['ui_level', (string)$u['level']]] as [$k, $val]) {
            $this->write('  |03' . cb_pad($this->L($k), 27) . '|15' . $val);
            $this->nl();
        }
        if ($pending) {
            $this->nl();
            $this->say('rev_pending');
            $this->nl();
        }
        $this->nl();
        $this->hot($pending ? 'FHSLWQ' : 'HSWQ', $this->L($pending ? 'rev_prompt_pending' : 'rev_prompt'));
    }

    private function i_sys_rev(string $v): void
    {
        $u = $this->sysReviewUser();
        if (!$u) {
            $this->go('sys_rev');
            return;
        }
        $pending = (int)$u['pending'] === 1;
        switch (strtoupper($v)) {
            case 'F':
            case 'H':
                if (strtoupper($v) === 'F' && !$pending) {
                    break;
                }
                $mode = strtoupper($v) === 'F' ? 'approve' : 'promote';
                $def = $mode === 'approve' ? Settings::int('new_level', 10)
                    : (int)(DB::val('SELECT MIN(level) FROM {levels} WHERE level>?', [(int)$u['level']]) ?? $u['level']);
                $this->S['sr']['mode'] = $mode;
                $this->S['sr']['def'] = min($def, $this->sysMaxLevel());
                $this->S['st'] = 'sys_rev_lvl';
                $this->nl();
                $this->line(3, $this->L('rev_level', $this->S['sr']['def']));
                return;
            case 'S':
                DB::q('UPDATE {users} SET locked=1 WHERE id=?', [(int)$u['id']]);
                $this->sysLog('Locked user ' . $u['handle'] . ' (sysop menu)');
                $this->nl();
                $this->say('rev_locked');
                $this->nl();
                $this->sysReviewNext();
                return;
            case 'L':
                if ($pending) {
                    $this->S['st'] = 'sys_rev_del';
                    $this->nl();
                    $this->yn($this->L('rev_del_confirm', cb_esc($u['handle'])), false);
                    return;
                }
                break;
            case 'W':
                $this->S['sr']['i']++;
                $this->go('sys_rev');
                return;
            case 'Q':
                unset($this->S['sr']);
                $this->go('sys');
                return;
        }
        $this->go('sys_rev');
    }

    /** Level from input, '' takes $def. Null if not allowed (only 0 up to the own level). */
    private function sysLevelInput(string $v, int $def): ?int
    {
        $v = trim($v);
        $lvl = $v === '' ? $def : (ctype_digit($v) ? (int)$v : -1);
        return $lvl >= 0 && $lvl <= $this->sysMaxLevel() ? $lvl : null;
    }

    private function i_sys_rev_lvl(string $v): void
    {
        $u = $this->sysReviewUser();
        $lvl = $this->sysLevelInput($v, (int)($this->S['sr']['def'] ?? 0));
        if (!$u) {
            $this->go('sys_rev');
            return;
        }
        if ($lvl === null) {
            $this->say('rev_level_bad', $this->sysMaxLevel());
            $this->nl();
            $this->line(3, $this->L('rev_level', (int)$this->S['sr']['def']));
            return;
        }
        if (($this->S['sr']['mode'] ?? '') === 'approve') {
            DB::q('UPDATE {users} SET pending=0, level=? WHERE id=?', [$lvl, (int)$u['id']]);
            $this->sysLog('Validated user ' . $u['handle'] . ' with level ' . $lvl . ' (sysop menu)');
            $this->say('rev_approved', $lvl);
        } else {
            DB::q('UPDATE {users} SET level=? WHERE id=?', [$lvl, (int)$u['id']]);
            $this->sysLog('Set level of ' . $u['handle'] . ' to ' . $lvl . ' (sysop menu)');
            $this->say('rev_promoted', $lvl);
        }
        $this->nl();
        $this->sysReviewNext();
    }

    private function i_sys_rev_del(string $v): void
    {
        $u = $this->sysReviewUser();
        if ($u && (int)$u['pending'] === 1 && $this->yes($v, false)) {
            cb_delete_user((int)$u['id']);
            $this->sysLog('Deleted user ' . $u['handle'] . ' (sysop menu)');
            $this->say('rev_deleted');
            $this->nl();
            $this->sysReviewNext();
            return;
        }
        $this->go('sys_rev');
    }

    /* ------------------------------------------------------------ U: waiting uploads */

    private function sysStartUploads(): void
    {
        $ids = array_map('intval', array_column(DB::all('SELECT id FROM {files} WHERE approved=0 ORDER BY added, id'), 'id'));
        if (!$ids) {
            $this->nl();
            $this->say('up_empty');
            $this->nl();
            $this->pause('sys');
            return;
        }
        $this->S['su'] = ['ids' => $ids, 'i' => 0];
        $this->go('sys_up');
    }

    private function sysUpload(): ?array
    {
        $su = $this->S['su'] ?? null;
        $id = $su['ids'][$su['i'] ?? 0] ?? 0;
        return $id ? DB::row('SELECT * FROM {files} WHERE id=? AND approved=0', [$id]) : null;
    }

    private function e_sys_up(): void
    {
        $su = &$this->S['su'];
        while (isset($su['ids'][$su['i']]) && !$this->sysUpload()) {
            $su['i']++;
        }
        $f = $this->sysUpload();
        if (!$f) {
            unset($this->S['su']);
            $this->nl();
            $this->say('up_end');
            $this->nl();
            $this->pause('sys');
            return;
        }
        $this->cls();
        $this->bar($this->L('up_title'), $this->L('n_of', $su['i'] + 1, count($su['ids'])));
        $this->nl();
        $area = (string)DB::val('SELECT name FROM {file_areas} WHERE id=?', [(int)$f['area_id']]);
        foreach ([['col_file', cb_esc($f['filename'])], ['col_area', cb_esc($area)], ['up_uploader', cb_esc($f['uploader'])],
                     ['col_size', cb_kb((int)$f['size'])]] as [$k, $val]) {
            $this->write('  |03' . cb_pad($this->L($k), 16) . '|15' . $val);
            $this->nl();
        }
        $this->write('  |03' . $this->L('col_desc'));
        $this->nl();
        foreach (array_slice(cb_wrap((string)$f['description'], 74), 0, 10) as $l) {
            $this->write('    |07' . cb_esc($l));
            $this->nl();
        }
        $this->nl();
        $this->hot('FLWQ', $this->L('up_prompt'));
    }

    private function i_sys_up(string $v): void
    {
        $f = $this->sysUpload();
        switch (strtoupper($v)) {
            case 'F':
                if ($f) {
                    DB::q('UPDATE {files} SET approved=1 WHERE id=?', [(int)$f['id']]);
                    $this->sysLog('Approved file ' . $f['filename'] . ' (sysop menu)');
                    $this->nl();
                    $this->say('up_approved');
                    $this->nl();
                }
                break;
            case 'L':
                if ($f) {
                    $this->nl();
                    if (cb_delete_file($f)) {
                        $this->sysLog('Deleted file ' . $f['filename'] . ' (sysop menu)');
                        $this->say('up_deleted');
                    } else {
                        $this->say('up_delete_failed');
                    }
                    $this->nl();
                }
                break;
            case 'W':
                $this->S['su']['i']++;
                $this->go('sys_up');
                return;
            case 'Q':
                unset($this->S['su']);
                $this->go('sys');
                return;
            default:
                $this->go('sys_up');
                return;
        }
        $this->S['su']['i']++;
        $this->pause('sys_up');
    }

    /* ------------------------------------------------------------ E: edit a user */

    private function e_sys_edit_h(): void
    {
        $this->nl();
        $this->line(20, $this->L('ed_handle_prompt'));
    }

    private function i_sys_edit_h(string $v): void
    {
        $v = CP437::clean($v, 20);
        if ($v === '') {
            $this->go('sys');
            return;
        }
        $u = DB::row('SELECT * FROM {users} WHERE handle_lc=?', [mb_strtolower($v)]);
        if (!$u) {
            $this->say('ed_not_found', cb_esc($v));
            $this->go('sys_edit_h');
            return;
        }
        if ((int)$u['id'] !== (int)$this->user['id'] && (int)$u['level'] > $this->lvl()) {
            $this->say('ed_higher');
            $this->go('sys_edit_h');
            return;
        }
        $this->S['se'] = (int)$u['id'];
        $this->go('sys_edit');
    }

    /** The edited user, null (and back to the menu) if gone or now above the own level. */
    private function sysEditUser(): ?array
    {
        $u = DB::row('SELECT * FROM {users} WHERE id=?', [(int)($this->S['se'] ?? 0)]);
        if (!$u || ((int)$u['id'] !== (int)$this->user['id'] && (int)$u['level'] > $this->lvl())) {
            unset($this->S['se']);
            $this->go('sys');
            return null;
        }
        return $u;
    }

    private function sysSelf(array $u): bool
    {
        return (int)$u['id'] === (int)$this->user['id'];
    }

    private function e_sys_edit(): void
    {
        $u = $this->sysEditUser();
        if (!$u) {
            return;
        }
        $this->cls();
        $this->bar($this->L('ed_title'), $u['handle']);
        $this->nl();
        foreach ([['ui_handle', cb_esc($u['handle'])], ['ui_location', cb_esc($u['location'])], ['ui_level', (string)$u['level']],
                     ['ed_lockstate', (int)$u['locked'] === 1 ? $this->L('on') : $this->L('off')],
                     ['ed_today', (int)ceil((int)$u['time_today'] / 60) . ' min, ' . (int)$u['dl_kb_today'] . 'k']] as [$k, $val]) {
            $this->write('  |03' . cb_pad($this->L($k), 27) . '|15' . $val);
            $this->nl();
        }
        if ((int)$u['pending'] === 1) {
            $this->nl();
            $this->say('rev_pending');
            $this->nl();
        }
        $this->nl();
        $this->hot('LSZPQ', $this->L('ed_prompt'));
    }

    private function i_sys_edit(string $v): void
    {
        $u = $this->sysEditUser();
        if (!$u) {
            return;
        }
        switch (strtoupper($v)) {
            case 'L':
                if ($this->sysSelf($u)) {
                    $this->nl();
                    $this->say('ed_self_level');
                    $this->nl();
                    $this->pause('sys_edit');
                    return;
                }
                $this->S['st'] = 'sys_edit_lvl';
                $this->nl();
                $this->line(3, $this->L('ed_level_prompt', $this->sysMaxLevel()));
                return;
            case 'S':
                $this->nl();
                if ($this->sysSelf($u)) {
                    $this->say('ed_self_lock');
                } else {
                    $lock = (int)$u['locked'] === 1 ? 0 : 1;
                    DB::q('UPDATE {users} SET locked=? WHERE id=?', [$lock, (int)$u['id']]);
                    $this->sysLog(($lock ? 'Locked' : 'Unlocked') . ' user ' . $u['handle'] . ' (sysop menu)');
                    $this->say($lock ? 'ed_locked' : 'ed_unlocked');
                }
                $this->nl();
                $this->pause('sys_edit');
                return;
            case 'Z':
                DB::q('UPDATE {users} SET time_today=0, dl_kb_today=0 WHERE id=?', [(int)$u['id']]);
                $this->sysLog('Reset time and download counter of ' . $u['handle'] . ' (sysop menu)');
                $this->nl();
                $this->say('ed_reset');
                $this->nl();
                $this->pause('sys_edit');
                return;
            case 'P':
                $this->S['st'] = 'sys_edit_pw1';
                $this->nl();
                $this->line(40, $this->L('nu_pass'), true);
                return;
            case 'Q':
                unset($this->S['se']);
                $this->go('sys');
                return;
        }
        $this->go('sys_edit');
    }

    private function i_sys_edit_lvl(string $v): void
    {
        $u = $this->sysEditUser();
        if (!$u) {
            return;
        }
        $lvl = $this->sysLevelInput($v, (int)$u['level']);
        if ($lvl === null || $this->sysSelf($u)) {
            $this->say('rev_level_bad', $this->sysMaxLevel());
            $this->nl();
            $this->pause('sys_edit');
            return;
        }
        DB::q('UPDATE {users} SET level=? WHERE id=?', [$lvl, (int)$u['id']]);
        $this->sysLog('Set level of ' . $u['handle'] . ' to ' . $lvl . ' (sysop menu)');
        $this->say('ed_level_set', $lvl);
        $this->nl();
        $this->pause('sys_edit');
    }

    private function i_sys_edit_pw1(string $v): void
    {
        if (!$this->sysEditUser()) {
            return;
        }
        if (mb_strlen($v) < 6) {
            $this->say('nu_pass_short');
            $this->pause('sys_edit');
            return;
        }
        $this->S['se_pw'] = password_hash($v, PASSWORD_DEFAULT);
        $this->S['st'] = 'sys_edit_pw2';
        $this->line(40, $this->L('nu_pass2'), true);
    }

    private function i_sys_edit_pw2(string $v): void
    {
        $u = $this->sysEditUser();
        $hash = (string)($this->S['se_pw'] ?? '');
        unset($this->S['se_pw']);
        if (!$u) {
            return;
        }
        if (!password_verify($v, $hash)) {
            $this->say('nu_pass_mismatch');
            $this->pause('sys_edit');
            return;
        }
        DB::q('UPDATE {users} SET pass=? WHERE id=?', [$hash, (int)$u['id']]);
        $this->sysLog('Set a new password for ' . $u['handle'] . ' (sysop menu)');
        $this->say('ed_pw_ok');
        $this->nl();
        $this->pause('sys_edit');
    }

    /* ------------------------------------------------------------ T: kick, R: broadcast, C: chat */

    private function e_sys_kick(): void
    {
        $this->cls();
        $this->bar($this->L('sys_t'));
        $this->nl();
        $this->nodeTable();
        $this->nl();
        $this->line(2, $this->L('kick_prompt'));
    }

    private function i_sys_kick(string $v): void
    {
        $v = trim($v);
        if ($v === '') {
            $this->go('sys');
            return;
        }
        $n = (int)$v;
        $row = DB::row('SELECT * FROM {nodes} WHERE node=?', [$n]);
        if ($n === (int)$this->S['node']) {
            $this->say('kick_self');
        } elseif (!$row) {
            $this->say('kick_none', $n);
        } else {
            DB::q('UPDATE {nodes} SET kicked=1 WHERE node=?', [$n]);
            $this->sysLog('Disconnected node ' . $n . ($row['handle'] !== '' ? ' (' . $row['handle'] . ')' : ''));
            $this->say('kick_done', $n);
        }
        $this->nl();
        $this->pause('sys');
    }

    private function e_sys_bc(): void
    {
        $this->nl();
        $this->line(70, $this->L('bc_prompt'));
    }

    private function i_sys_bc(string $v): void
    {
        $v = CP437::clean($v, 70);
        if ($v === '') {
            $this->go('sys');
            return;
        }
        $nodes = array_map('intval', array_column(DB::all('SELECT node FROM {nodes} WHERE node<>?', [(int)$this->S['node']]), 'node'));
        foreach ($nodes as $n) {
            $this->nodeMsg($n, 'msg', $v);
        }
        $this->sysLog('Broadcast to ' . count($nodes) . ' node(s): ' . $v);
        $this->say('bc_done', count($nodes));
        $this->nl();
        $this->pause('sys');
    }

    private function e_sys_chatn(): void
    {
        $this->cls();
        $this->bar($this->L('sys_c'));
        $this->nl();
        $this->nodeTable();
        $this->nl();
        $this->line(2, $this->L('chat_node_prompt'));
    }

    private function i_sys_chatn(string $v): void
    {
        $v = trim($v);
        if ($v === '') {
            $this->go('sys');
            return;
        }
        $n = (int)$v;
        $row = DB::row('SELECT * FROM {nodes} WHERE node=? AND user_id>0 AND kicked=0', [$n]);
        if ($n === (int)$this->S['node'] || !$row) {
            $this->say('chat_no_user', $n);
            $this->nl();
            $this->pause('sys');
            return;
        }
        $this->S['chat'] = ['peer' => $n, 'handle' => (string)$row['handle']];
        $this->nodeMsg($n, 'chat', '');
        $this->sysLog('Chat with ' . $row['handle'] . ' on node ' . $n);
        $this->act(Lang::get('act_chat'));
        $this->nl();
        $this->say('chat_wait', cb_esc((string)$row['handle']), $n);
        $this->nl();
        $this->S['st'] = 'chat';
        $this->chatPrompt();
    }
}
