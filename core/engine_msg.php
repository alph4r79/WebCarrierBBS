<?php
/**
 * WebCarrier BBS: message areas, reader, editor flow and private mail.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

trait EngineMessages
{
    private function readableAreas(): array
    {
        return DB::all('SELECT * FROM {msg_areas} WHERE read_level<=? ORDER BY sort, id', [$this->lvl()]);
    }

    private function curMArea(): ?array
    {
        $id = (int)($this->S['marea'] ?? 0);
        if ($id > 0) {
            $a = DB::row('SELECT * FROM {msg_areas} WHERE id=? AND read_level<=?', [$id, $this->lvl()]);
            if ($a) {
                return $a;
            }
        }
        $a = $this->readableAreas()[0] ?? null;
        if ($a) {
            $this->S['marea'] = (int)$a['id'];
        }
        return $a;
    }

    private function lastRead(int $area): int
    {
        return (int)(DB::val('SELECT msg_id FROM {lastread} WHERE user_id=? AND area_id=?', [(int)$this->user['id'], $area]) ?? 0);
    }

    private function setLastRead(int $area, int $msg): void
    {
        $uid = (int)$this->user['id'];
        $cur = DB::val('SELECT msg_id FROM {lastread} WHERE user_id=? AND area_id=?', [$uid, $area]);
        if ($cur === null) {
            DB::insert('lastread', ['user_id' => $uid, 'area_id' => $area, 'msg_id' => $msg]);
        } elseif ((int)$cur < $msg) {
            DB::q('UPDATE {lastread} SET msg_id=? WHERE user_id=? AND area_id=?', [$msg, $uid, $area]);
        }
    }

    /* ------------------------------------------------------------ area selection */

    private function e_marea(): void
    {
        $this->act(Lang::get('act_msgarea'));
        $this->cls();
        $this->bar($this->L('marea_title'));
        $this->nl();
        $cur = (int)($this->curMArea()['id'] ?? 0);
        $areas = $this->readableAreas();
        $this->write('|03  #  ' . cb_pad($this->L('col_area'), 38) . cb_pad($this->L('col_msgs'), 8, 'R') . cb_pad($this->L('col_new'), 8, 'R'));
        $this->nl();
        $this->rule();
        foreach ($areas as $i => $a) {
            $total = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE area_id=? AND private=0', [(int)$a['id']]);
            $new = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE area_id=? AND private=0 AND id>?',
                [(int)$a['id'], $this->lastRead((int)$a['id'])]);
            $mark = (int)$a['id'] === $cur ? '|14*' : ' ';
            $this->write($mark . '|15' . cb_pad((string)($i + 1), 3, 'R') . '  |11' . cb_esc(cb_pad($a['name'], 38)) .
                '|07' . cb_pad((string)$total, 8, 'R') . ($new > 0 ? '|10' : '|08') . cb_pad((string)$new, 8, 'R'));
            $this->nl();
            if ($a['description'] !== '') {
                $this->write('       |08' . cb_esc(mb_substr($a['description'], 0, 70)));
                $this->nl();
            }
        }
        $this->nl();
        $this->line(3, $this->L('marea_prompt'));
    }

    private function i_marea(string $v): void
    {
        $n = (int)trim($v);
        $areas = $this->readableAreas();
        if ($n >= 1 && isset($areas[$n - 1])) {
            $this->S['marea'] = (int)$areas[$n - 1]['id'];
            $this->say('marea_set', cb_esc($areas[$n - 1]['name']));
            $this->nl();
        }
        $this->menu();
    }

    /* ------------------------------------------------------------ reading */

    private function e_mread(): void
    {
        $a = $this->curMArea();
        if (!$a) {
            $this->say('no_areas');
            $this->pause();
            return;
        }
        $total = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE area_id=? AND private=0', [(int)$a['id']]);
        if ($total === 0) {
            $this->nl();
            $this->say('area_empty', cb_esc($a['name']));
            $this->nl();
            $this->pause();
            return;
        }
        $new = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE area_id=? AND private=0 AND id>?',
            [(int)$a['id'], $this->lastRead((int)$a['id'])]);
        $this->nl();
        $this->line(5, $this->L('read_from', cb_esc($a['name']), $new, $total));
    }

    private function i_mread(string $v): void
    {
        $a = $this->curMArea();
        $v = strtoupper(trim($v));
        if (!$a || $v === 'Q') {
            $this->menu();
            return;
        }
        $all = array_map('intval', array_column(DB::all('SELECT id FROM {messages} WHERE area_id=? AND private=0 ORDER BY id',
            [(int)$a['id']]), 'id'));
        if ($v === '' || $v === 'N') {
            $lr = $this->lastRead((int)$a['id']);
            $ids = array_values(array_filter($all, static fn($id) => $id > $lr));
            if (!$ids) {
                $this->say('no_new');
                $this->nl();
                $this->pause();
                return;
            }
        } elseif ($v === 'A') {
            $ids = $all;
        } elseif (ctype_digit($v) && (int)$v >= 1 && (int)$v <= count($all)) {
            $ids = array_slice($all, (int)$v - 1);
        } else {
            $this->go('mread');
            return;
        }
        $this->startReader($ids, 'area');
    }

    private function newScan(): void
    {
        $ids = [];
        foreach ($this->readableAreas() as $a) {
            $lr = $this->lastRead((int)$a['id']);
            foreach (DB::all('SELECT id FROM {messages} WHERE area_id=? AND private=0 AND id>? ORDER BY id', [(int)$a['id'], $lr]) as $r) {
                $ids[] = (int)$r['id'];
            }
        }
        if (!$ids) {
            $this->nl();
            $this->say('no_new_any');
            $this->nl();
            $this->pause();
            return;
        }
        $this->startReader($ids, 'new');
    }

    private function readMail(): void
    {
        $uid = (int)$this->user['id'];
        $rows = DB::all('SELECT id FROM {messages} WHERE private=1 AND to_id=? AND is_read=0 ORDER BY id', [$uid]);
        if (!$rows) {
            $rows = DB::all('SELECT id FROM {messages} WHERE private=1 AND to_id=? ORDER BY id', [$uid]);
            if (!$rows) {
                $this->nl();
                $this->say('no_mail');
                $this->nl();
                $this->pause();
                return;
            }
            $this->nl();
            $this->say('no_new_mail_show_all');
            $this->nl();
        }
        $this->startReader(array_map('intval', array_column($rows, 'id')), 'mail');
    }

    private function startReader(array $ids, string $mode): void
    {
        $this->S['rd'] = ['ids' => array_values($ids), 'i' => 0, 'mode' => $mode, 'lines' => [], 'pos' => 0];
        $this->act(Lang::get($mode === 'mail' ? 'act_mail' : 'act_read'));
        $this->go('rmsg');
    }

    private function canSee(array $m): bool
    {
        if ((int)$m['private'] === 1) {
            return $this->isSysop() || (int)$m['to_id'] === (int)$this->user['id'] || (int)$m['from_id'] === (int)$this->user['id'];
        }
        return (bool)DB::val('SELECT COUNT(*) FROM {msg_areas} WHERE id=? AND read_level<=?', [(int)$m['area_id'], $this->lvl()]);
    }

    private function e_rmsg(): void
    {
        $rd = &$this->S['rd'];
        while (true) {
            if (!isset($rd['ids'][$rd['i']])) {
                unset($this->S['rd']);
                $this->nl();
                $this->say('end_of_msgs');
                $this->nl();
                $this->pause();
                return;
            }
            $m = DB::row('SELECT * FROM {messages} WHERE id=?', [$rd['ids'][$rd['i']]]);
            if ($m && $this->canSee($m)) {
                break;
            }
            $rd['i']++;
        }
        $private = (int)$m['private'] === 1;
        if ($private) {
            $where = $this->L('private_mail');
            $num = $this->L('msg_n_of', $rd['i'] + 1, count($rd['ids']));
        } else {
            $where = (string)DB::val('SELECT name FROM {msg_areas} WHERE id=?', [(int)$m['area_id']]);
            $pos = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE area_id=? AND private=0 AND id<=?', [(int)$m['area_id'], (int)$m['id']]);
            $tot = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE area_id=? AND private=0', [(int)$m['area_id']]);
            $num = $this->L('msg_n_of', $pos, $tot);
        }
        $lines = [];
        $lines[] = '|03' . cb_pad($this->L('hdr_from'), 6) . '|11' . cb_esc(cb_pad($m['from_name'], 32)) .
            '|03' . cb_pad($this->L('hdr_date'), 7) . '|11' . date(Lang::get('fmt_date') . ' H:i', (int)$m['posted']);
        $new = $private && (int)$m['is_read'] === 0 && (int)$m['to_id'] === (int)$this->user['id'];
        $lines[] = '|03' . cb_pad($this->L('hdr_to'), 6) . '|11' . cb_esc(cb_pad($m['to_name'], 32)) . ($new ? '|12' . $this->L('hdr_new') : '');
        $lines[] = '|03' . cb_pad($this->L('hdr_subj'), 6) . '|15' . cb_esc(mb_substr($m['subject'], 0, 70));
        $lines[] = '|08' . str_repeat('─', 79);
        foreach (cb_wrap((string)$m['body'], 79) as $l) {
            $color = preg_match('/^\s*[\p{L}]{0,3}>/u', $l) ? '|03' : '|07';
            $lines[] = $color . cb_esc($l);
        }
        $rd['lines'] = $lines;
        $rd['pos'] = 0;
        $rd['mid'] = (int)$m['id'];
        $this->cls();
        $this->bar($where, $num);
        if ($private && (int)$m['to_id'] === (int)$this->user['id'] && (int)$m['is_read'] === 0) {
            DB::q('UPDATE {messages} SET is_read=1 WHERE id=?', [(int)$m['id']]);
        }
        if (!$private) {
            $this->setLastRead((int)$m['area_id'], (int)$m['id']);
        }
        $this->showReaderPage(22);
    }

    private function showReaderPage(int $rows): void
    {
        $rd = &$this->S['rd'];
        $end = min(count($rd['lines']), $rd['pos'] + $rows);
        for ($i = $rd['pos']; $i < $end; $i++) {
            $this->write($rd['lines'][$i]);
            $this->nl();
        }
        $rd['pos'] = $end;
        if ($end < count($rd['lines'])) {
            $this->S['st'] = 'rmore';
            $this->hot("\r" . Lang::get('key_no') . 'Q ', $this->L('more_prompt'), true);
            return;
        }
        $this->readerPrompt();
    }

    private function i_rmore(string $v): void
    {
        $k = strtoupper($v);
        if ($k === "\r" || $k === ' ') {
            $this->showReaderPage(23);
            return;
        }
        $this->readerPrompt();
    }

    private function canDelete(array $m): bool
    {
        if ($this->isSysop()) {
            return true;
        }
        if ((int)$m['private'] === 1) {
            return (int)$m['to_id'] === (int)$this->user['id'];
        }
        return false;
    }

    private function readerPrompt(): void
    {
        $m = DB::row('SELECT * FROM {messages} WHERE id=?', [(int)($this->S['rd']['mid'] ?? 0)]);
        $keys = "NPRAQ\r";
        $prompt = $this->L('reader_prompt');
        if ($m && $this->isSysop() && (int)$m['private'] === 0) {
            $keys .= 'DM';
            $prompt = $this->L('reader_prompt_sys');
        } elseif ($m && $this->canDelete($m)) {
            $keys .= 'D';
            $prompt = $this->L('reader_prompt_del');
        }
        $this->S['st'] = 'ract';
        $this->hot($keys, $prompt);
    }

    private function i_ract(string $v): void
    {
        $rd = &$this->S['rd'];
        $k = strtoupper($v);
        $m = DB::row('SELECT * FROM {messages} WHERE id=?', [(int)($rd['mid'] ?? 0)]);
        switch ($k) {
            case 'Q':
                unset($this->S['rd']);
                $this->menu();
                return;
            case 'P':
                $rd['i'] = max(0, $rd['i'] - 1);
                $this->go('rmsg');
                return;
            case 'A':
                $this->go('rmsg');
                return;
            case 'R':
                if ($m) {
                    $this->reply($m);
                    return;
                }
                break;
            case 'M':
                if ($m && $this->isSysop() && (int)$m['private'] === 0) {
                    $this->nl();
                    foreach ($this->readableAreas() as $i => $ar) {
                        $this->write('  |15' . cb_pad((string)($i + 1), 3, 'R') . '  |11' . cb_esc($ar['name']));
                        $this->nl();
                    }
                    $this->S['st'] = 'rmove';
                    $this->line(3, $this->L('msg_move_prompt'));
                    return;
                }
                break;
            case 'D':
                if ($m && $this->canDelete($m)) {
                    DB::q('DELETE FROM {messages} WHERE id=?', [(int)$m['id']]);
                    $this->say('msg_deleted');
                    $this->nl();
                }
                break;
        }
        $rd['i']++;
        $this->go('rmsg');
    }

    private function i_rmove(string $v): void
    {
        $areas = $this->readableAreas();
        $n = (int)trim($v);
        $mid = (int)($this->S['rd']['mid'] ?? 0);
        if ($this->isSysop() && isset($areas[$n - 1]) && $mid > 0) {
            DB::q('UPDATE {messages} SET area_id=? WHERE id=? AND private=0', [(int)$areas[$n - 1]['id'], $mid]);
            cb_log((int)$this->user['id'], $this->user['handle'], 'Moved message ' . $mid . ' to area ' . $areas[$n - 1]['id']);
            $this->say('msg_moved', cb_esc($areas[$n - 1]['name']));
            $this->nl();
        }
        if (!empty($this->S['rd'])) {
            $this->S['rd']['i']++;
            $this->go('rmsg');
            return;
        }
        $this->menu();
    }

    private function reply(array $m): void
    {
        $private = (int)$m['private'] === 1;
        if (!$private) {
            $ok = DB::val('SELECT COUNT(*) FROM {msg_areas} WHERE id=? AND write_level<=?', [(int)$m['area_id'], $this->lvl()]);
            if (!$ok) {
                $this->nl();
                $this->say('no_write');
                $this->nl();
                $this->readerPrompt();
                return;
            }
        }
        $subj = (string)$m['subject'];
        if (!preg_match('/^re:/i', $subj)) {
            $subj = 'Re: ' . $subj;
        }
        $this->startPost([
            'private' => $private ? 1 : 0, 'area_id' => (int)$m['area_id'],
            'to' => $m['from_name'], 'to_id' => (int)$m['from_id'], 'subj' => mb_substr($subj, 0, 70),
            'reply_to' => (int)$m['id'], 'quote' => $this->quoteLines($m), 'back' => 'reader',
        ]);
    }

    private function quoteLines(array $m): array
    {
        $ini = '';
        foreach (preg_split('/[\s._\-]+/u', (string)$m['from_name']) as $p) {
            if ($p !== '' && mb_strlen($ini) < 2) {
                $ini .= mb_strtoupper(mb_substr($p, 0, 1));
            }
        }
        $out = [];
        foreach (cb_wrap((string)$m['body'], 70) as $l) {
            if (trim($l) === '') {
                continue;
            }
            $out[] = ' ' . $ini . '> ' . $l;
            if (count($out) >= 40) {
                break;
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------ posting */

    private function postPublic(): void
    {
        $a = $this->curMArea();
        if (!$a) {
            $this->say('no_areas');
            $this->pause();
            return;
        }
        if ((int)$a['write_level'] > $this->lvl()) {
            $this->nl();
            $this->say('no_write');
            $this->nl();
            $this->pause();
            return;
        }
        $this->startPost(['private' => 0, 'area_id' => (int)$a['id']]);
    }

    private function startPost(array $p): void
    {
        $p += ['to' => '', 'to_id' => 0, 'subj' => '', 'reply_to' => 0, 'quote' => [], 'back' => 'menu'];
        $this->S['post'] = $p;
        $this->act(Lang::get('act_write'));
        $this->nl();
        if ((int)$p['private'] === 1) {
            $this->say('post_private_head');
        } else {
            $this->say('post_head', cb_esc((string)DB::val('SELECT name FROM {msg_areas} WHERE id=?', [(int)$p['area_id']])));
        }
        $this->nl();
        if ($p['to'] === '') {
            $this->go('p_to');
        } elseif ($p['subj'] === '') {
            $this->say('post_to_is', cb_esc($p['to']));
            $this->nl();
            $this->go('p_subj');
        } else {
            $this->say('post_to_is', cb_esc($p['to']));
            $this->nl();
            $this->say('post_subj_is', cb_esc($p['subj']));
            $this->nl();
            $this->afterSubject();
        }
    }

    private function e_p_to(): void
    {
        $priv = (int)$this->S['post']['private'] === 1;
        $this->line(20, $this->L($priv ? 'post_to_user' : 'post_to'));
    }

    private function i_p_to(string $v): void
    {
        $v = CP437::clean($v, 20);
        $priv = (int)$this->S['post']['private'] === 1;
        if ($v === '') {
            if ($priv) {
                $this->say('post_aborted');
                $this->pause();
                return;
            }
            $this->S['post']['to'] = Lang::get('kw_all');
            $this->S['post']['to_id'] = 0;
            $this->go('p_subj');
            return;
        }
        $lc = mb_strtolower($v);
        $u = $lc === 'sysop' ? DB::row('SELECT id, handle FROM {users} WHERE id=?', [$this->sysopId()])
            : DB::row('SELECT id, handle FROM {users} WHERE handle_lc=? AND pending=0', [$lc]);
        if (!$u) {
            if ($priv) {
                $this->say('user_unknown', cb_esc($v));
                $this->go('p_to');
                return;
            }
            $this->S['post']['to'] = $v;
            $this->S['post']['to_id'] = 0;
        } else {
            $this->S['post']['to'] = $u['handle'];
            $this->S['post']['to_id'] = (int)$u['id'];
        }
        $this->go('p_subj');
    }

    private function e_p_subj(): void
    {
        $this->line(60, $this->L('post_subj'));
    }

    private function i_p_subj(string $v): void
    {
        $v = CP437::clean($v, 60);
        if ($v === '') {
            $this->say('post_aborted');
            $this->postBack();
            return;
        }
        $this->S['post']['subj'] = $v;
        $this->afterSubject();
    }

    private function afterSubject(): void
    {
        if (!empty($this->S['post']['quote'])) {
            $this->S['st'] = 'p_quote';
            $this->yn($this->L('post_quote'), true);
            return;
        }
        $this->go('p_edit', false);
    }

    private function i_p_quote(string $v): void
    {
        $this->go('p_edit', $this->yes($v, true));
    }

    private function e_p_edit(bool $quote = false): void
    {
        $this->nl();
        $this->say('editor_help');
        $this->nl();
        $this->rule();
        $this->editor($quote ? $this->S['post']['quote'] : [], 75, Settings::int('max_msg_lines', 200));
    }

    private function i_p_edit(string $v): void
    {
        $p = $this->S['post'] ?? null;
        if (!$p || $v === "\x00") {
            $this->say('post_aborted');
            $this->postBack();
            return;
        }
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        if (!mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
        }
        $v = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $v) ?? '';
        $v = mb_substr(rtrim($v), 0, 20000);
        if (trim($v) === '') {
            $this->say('post_aborted');
            $this->postBack();
            return;
        }
        $newId = DB::insert('messages', [
            'area_id' => (int)$p['area_id'], 'from_id' => (int)$this->user['id'], 'from_name' => $this->user['handle'],
            'to_id' => (int)$p['to_id'], 'to_name' => (string)$p['to'], 'subject' => (string)$p['subj'], 'body' => $v,
            'posted' => time(), 'reply_to' => (int)$p['reply_to'], 'private' => (int)$p['private'], 'is_read' => 0,
        ]);
        DB::q('UPDATE {users} SET posts=posts+1 WHERE id=?', [(int)$this->user['id']]);
        if ((int)$p['private'] === 0) {
            $prev = (int)(DB::val('SELECT MAX(id) FROM {messages} WHERE area_id=? AND private=0 AND id<?', [(int)$p['area_id'], $newId]) ?? 0);
            if ($this->lastRead((int)$p['area_id']) >= $prev) {
                $this->setLastRead((int)$p['area_id'], $newId);
            }
        }
        cb_log((int)$this->user['id'], $this->user['handle'], ((int)$p['private'] ? 'Mail to ' : 'Message in area ' . $p['area_id'] . ' to ') . $p['to']);
        $this->nl();
        $this->say('post_saved');
        $this->nl();
        $this->postBack();
    }

    private function postBack(): void
    {
        $back = $this->S['post']['back'] ?? 'menu';
        unset($this->S['post']);
        if ($back === 'reader' && !empty($this->S['rd'])) {
            $this->S['rd']['i']++;
            $this->pause('rmsg');
            return;
        }
        $this->pause();
    }
}
