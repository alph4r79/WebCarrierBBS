<?php
/**
 * WebCarrier BBS: file areas, listings, search, download and upload.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

trait EngineFiles
{
    private function fileAreas(): array
    {
        return DB::all('SELECT * FROM {file_areas} WHERE dl_level<=? ORDER BY sort, id', [$this->lvl()]);
    }

    private function curFArea(): ?array
    {
        $id = (int)($this->S['farea'] ?? 0);
        if ($id > 0) {
            $a = DB::row('SELECT * FROM {file_areas} WHERE id=? AND dl_level<=?', [$id, $this->lvl()]);
            if ($a) {
                return $a;
            }
        }
        $a = $this->fileAreas()[0] ?? null;
        if ($a) {
            $this->S['farea'] = (int)$a['id'];
        }
        return $a;
    }

    private function e_farea(): void
    {
        $this->act(Lang::get('act_files'));
        $this->cls();
        $this->bar($this->L('farea_title'));
        $this->nl();
        $cur = (int)($this->curFArea()['id'] ?? 0);
        $this->write('|03  #  ' . cb_pad($this->L('col_area'), 46) . cb_pad($this->L('col_files'), 8, 'R'));
        $this->nl();
        $this->rule();
        foreach ($this->fileAreas() as $i => $a) {
            $n = (int)DB::val('SELECT COUNT(*) FROM {files} WHERE area_id=? AND approved=1', [(int)$a['id']]);
            $mark = (int)$a['id'] === $cur ? '|14*' : ' ';
            $up = (int)$a['uploads'] === 1 && (int)$a['ul_level'] <= $this->lvl() ? ' |10' . $this->L('upload_ok') : '';
            $this->write($mark . '|15' . cb_pad((string)($i + 1), 3, 'R') . '  |11' . cb_esc(cb_pad($a['name'], 46)) .
                '|07' . cb_pad((string)$n, 8, 'R') . $up);
            $this->nl();
            if ($a['description'] !== '') {
                $this->write('       |08' . cb_esc(mb_substr($a['description'], 0, 70)));
                $this->nl();
            }
        }
        $this->nl();
        $this->line(3, $this->L('farea_prompt'));
    }

    private function i_farea(string $v): void
    {
        $n = (int)trim($v);
        $areas = $this->fileAreas();
        if ($n >= 1 && isset($areas[$n - 1])) {
            $this->S['farea'] = (int)$areas[$n - 1]['id'];
            $this->say('farea_set', cb_esc($areas[$n - 1]['name']));
            $this->nl();
        }
        $this->menu();
    }

    /* ------------------------------------------------------------ listings (pager) */

    private function fileList(): void
    {
        $a = $this->curFArea();
        if (!$a) {
            $this->say('no_fareas');
            $this->pause();
            return;
        }
        $ids = array_map('intval', array_column(DB::all('SELECT id FROM {files} WHERE area_id=? AND approved=1 ORDER BY filename',
            [(int)$a['id']]), 'id'));
        if (!$ids) {
            $this->nl();
            $this->say('farea_empty', cb_esc($a['name']));
            $this->nl();
            $this->pause();
            return;
        }
        $this->startPager('files', $ids, $a['name']);
    }

    private function fileNew(): void
    {
        $since = (int)($this->S['prevcall'] ?? 0);
        if ($since <= 0) {
            $since = time() - 14 * 86400;
        }
        $ids = array_map('intval', array_column(DB::all(
            'SELECT f.id FROM {files} f JOIN {file_areas} a ON a.id=f.area_id WHERE f.approved=1 AND f.added>? AND a.dl_level<=? ORDER BY a.sort, f.filename',
            [$since, $this->lvl()]), 'id'));
        if (!$ids) {
            $this->nl();
            $this->say('no_new_files', cb_date($since));
            $this->nl();
            $this->pause();
            return;
        }
        $this->startPager('files', $ids, $this->L('new_files_title', cb_date($since)));
    }

    private function e_fsearch(): void
    {
        $this->nl();
        $this->line(30, $this->L('fsearch_prompt'));
    }

    private function i_fsearch(string $v): void
    {
        $v = CP437::clean($v, 30);
        if (mb_strlen($v) < 2) {
            $this->menu();
            return;
        }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($v)) . '%';
        $ids = array_map('intval', array_column(DB::all(
            "SELECT f.id FROM {files} f JOIN {file_areas} a ON a.id=f.area_id WHERE f.approved=1 AND a.dl_level<=?
             AND (LOWER(f.filename) LIKE ? ESCAPE '!' OR LOWER(f.description) LIKE ? ESCAPE '!') ORDER BY a.sort, f.filename",
            [$this->lvl(), $like, $like]), 'id'));
        if (!$ids) {
            $this->say('fsearch_none', cb_esc($v));
            $this->nl();
            $this->pause();
            return;
        }
        $this->startPager('files', array_slice($ids, 0, 500), $this->L('fsearch_title', cb_esc($v), count($ids)));
    }

    /** $wad: id of the file a viewer shows, if it can be opened in WAnsiDraw. */
    public function startPager(string $kind, array $ids, string $title, array $lines = [], int $wad = 0): void
    {
        // a file viewer opened from a list returns to that list afterwards
        $prev = in_array($kind, ['lines', 'raw'], true) && ($this->S['pg']['kind'] ?? '') === 'files' ? $this->S['pg'] : null;
        $this->S['pg'] = ['kind' => $kind, 'ids' => array_values($ids), 'pos' => 0, 'start' => 0, 'hdr' => true, 'title' => $title, 'lines' => $lines, 'prev' => $prev, 'wad' => $wad];
        if ($kind === 'files') {
            // numbers in prompts refer to the last list shown
            $this->S['fl'] = array_values($ids);
        }
        $this->cls();
        $this->bar($title);
        $this->pagerHeader($kind);
        $this->go('pager');
    }

    /** Show the current page of the pager again, from the top of the screen. */
    private function pagerRedraw(): void
    {
        $pg = &$this->S['pg'];
        $pg['pos'] = (int)($pg['start'] ?? 0);
        $pg['hdr'] = true;
        $this->cls();
        $this->bar((string)$pg['title']);
        $this->pagerHeader((string)$pg['kind']);
        $this->go('pager');
    }

    private function e_pgback(): void
    {
        if (!empty($this->S['pg'])) {
            $this->pagerRedraw();
            return;
        }
        $this->menu();
    }

    private function pagerHeader(string $kind): void
    {
        if ($kind === 'files') {
            $this->write('|03' . cb_pad($this->L('col_nr'), 5, 'R') . '  ' . cb_pad($this->L('col_file'), 25) . '  ' . cb_pad($this->L('col_size'), 5, 'R') .
                '  ' . cb_pad($this->L('col_date'), 8) . '  ' . $this->L('col_desc'));
            $this->nl();
            $this->write('|08   ──  ' . str_repeat('─', 25) . '  ' . str_repeat('─', 5) . '  ' . str_repeat('─', 8) . '  ' . str_repeat('─', 28) . '|07');
            $this->nl();
        } elseif ($kind === 'users') {
            $this->write('|03' . cb_pad($this->L('col_user'), 21) . cb_pad($this->L('col_loc'), 31) .
                cb_pad($this->L('col_calls'), 7, 'R') . '  ' . $this->L('col_last'));
            $this->nl();
            $this->rule();
        }
    }

    /**
     * Lines of one file in a list: one line if the name fits, otherwise the name alone and the rest below.
     * Columns: number 5, mark 1, name 25, size 5 (from column 34), date 8, description 28 (from column 51).
     */
    private function fileLines(array $f, int $num): array
    {
        $desc = trim((string)$f['description']) !== '' ? (string)$f['description'] : $this->L('no_desc');
        $desc = array_values(array_filter(cb_wrap($desc, 28), static fn($l) => trim($l) !== '')) ?: [''];
        if (count($desc) > 4) {
            $desc = array_slice($desc, 0, 4);
            $desc[3] = mb_substr(rtrim($desc[3]), 0, 25) . '...';
        }
        $mark = in_array((int)$f['id'], $this->S['mark'] ?? [], true) ? '|14*' : ' ';
        $num = '|08' . cb_pad((string)$num, 5, 'R') . ' ' . $mark;
        $cols = '|10' . cb_pad(cb_size((int)$f['size']), 5, 'R') . '  |03' . cb_pad(date(Lang::get('fmt_date'), (int)$f['added']), 8) . '  |07' . cb_esc($desc[0]);
        $name = (string)$f['filename'];
        if (mb_strlen($name) > 25) {
            $out = [$num . '|15' . cb_esc(mb_substr($name, 0, 72)), str_repeat(' ', 34) . $cols];
        } else {
            $out = [$num . '|15' . cb_esc(cb_pad($name, 25)) . '  ' . $cols];
        }
        foreach (array_slice($desc, 1) as $d) {
            $out[] = str_repeat(' ', 51) . '|07' . cb_esc($d);
        }
        return $out;
    }

    private function pagerItem(string $kind, int $id, int $num): array
    {
        if ($kind === 'lines' || $kind === 'raw') {
            return [$this->S['pg']['lines'][$id] ?? ''];
        }
        if ($kind === 'users') {
            $u = DB::row('SELECT * FROM {users} WHERE id=?', [$id]);
            if (!$u) {
                return [];
            }
            return ['|11' . cb_esc(cb_pad($u['handle'], 21)) . '|07' . cb_esc(cb_pad($u['location'], 31)) .
                '|03' . cb_pad((string)$u['calls'], 7, 'R') . '  |08' . cb_date((int)$u['last_call'])];
        }
        $f = DB::row('SELECT * FROM {files} WHERE id=?', [$id]);
        return $f ? $this->fileLines($f, $num) : [];
    }

    private function e_pager(): void
    {
        $pg = &$this->S['pg'];
        $used = 0;
        $limit = !empty($pg['hdr']) ? 19 : 22;
        $pg['hdr'] = false;
        $pg['start'] = $pg['pos'];
        while ($pg['pos'] < count($pg['ids'])) {
            $lines = $this->pagerItem($pg['kind'], (int)$pg['ids'][$pg['pos']], $pg['pos'] + 1);
            // an entry is never split by the page break
            if ($used > 0 && $used + count($lines) > $limit) {
                break;
            }
            foreach ($lines as $l) {
                if ($pg['kind'] === 'raw') {
                    $this->w->raw($l);
                } else {
                    $this->write($l);
                }
                $this->nl();
            }
            $used += count($lines);
            $pg['pos']++;
        }
        if ($pg['kind'] === 'raw') {
            $this->w->buf .= "\e[0m";
            $this->w->reset();
        }
        $this->pagerPrompt();
    }

    private function pagerPrompt(): void
    {
        $pg = $this->S['pg'];
        $more = $pg['pos'] < count($pg['ids']);
        if ($pg['kind'] === 'files') {
            $keys = 'Q' . Lang::get('key_download') . 'VMK';
            $this->hot($more ? "\r " . $keys . Lang::get('key_no') : "\r" . $keys, $this->L($more ? 'pager_more_files' : 'pager_end_files'), true);
        } elseif ($pg['kind'] === 'users') {
            $this->hot($more ? "\r QP" . Lang::get('key_no') : "\rQP", $this->L($more ? 'pager_more_users' : 'pager_end_users'), true);
        } else {
            $w = !empty($pg['wad']) ? 'W' : '';
            $this->hot($more ? "\r Q" . $w . Lang::get('key_no') : "\rQ" . $w, $this->L(($more ? 'pager_more' : 'pager_end') . ($w !== '' ? '_w' : '')), true);
        }
    }

    /** Key W in the viewer: the browser opens the file in WAnsiDraw. Not a download. */
    private function openInWad(int $id): void
    {
        $f = DB::row('SELECT f.* FROM {files} f JOIN {file_areas} a ON a.id=f.area_id WHERE f.id=? AND f.approved=1 AND a.dl_level<=?', [$id, $this->lvl()]);
        $url = $f ? cb_wansidraw_link(CB_DATA . '/files/' . $f['storage'], '', (string)$f['filename']) : null;
        if ($url === null) {
            $this->say('wad_big');
            $this->nl();
        } else {
            $this->open = $url;
            cb_log((int)$this->user['id'], (string)$this->user['handle'], 'Opened ' . $f['filename'] . ' in WAnsiDraw');
        }
        $this->pagerPrompt();
    }

    private function i_pager(string $v): void
    {
        $k = strtoupper($v);
        $pg = $this->S['pg'] ?? null;
        if (!$pg) {
            $this->menu();
            return;
        }
        if ($pg['kind'] === 'files' && in_array($k, [Lang::get('key_download'), 'V', 'M', 'K'], true)) {
            $this->go(['V' => 'fview', 'M' => 'fmark', 'K' => 'fmarks'][$k] ?? 'fdl');
            return;
        }
        if (!empty($pg['wad']) && $k === 'W') {
            $this->openInWad((int)$pg['wad']);
            return;
        }
        if ($pg['kind'] === 'users' && $k === 'P') {
            $this->S['profback'] = 'pager';
            $this->go('prof_ask');
            return;
        }
        if (($k === "\r" || $k === ' ') && $pg['pos'] < count($pg['ids'])) {
            $this->go('pager');
            return;
        }
        if (!empty($pg['prev'])) {
            $this->S['pg'] = $pg['prev'];
            $this->pagerRedraw();
            return;
        }
        unset($this->S['pg']);
        $this->menu();
    }

    /* ------------------------------------------------------------ choosing a file: number or name */

    /** File by number of the last list or by name, readable for the caller. [file, error key]. */
    private function pickFile(string $v): array
    {
        $v = CP437::clean($v, 120);
        if (ctype_digit($v) && !empty($this->S['fl'])) {
            $id = $this->S['fl'][(int)$v - 1] ?? null;
            if ($id === null) {
                return [null, 'fsel_badnum'];
            }
            $f = DB::row('SELECT f.* FROM {files} f JOIN {file_areas} a ON a.id=f.area_id WHERE f.id=? AND f.approved=1 AND a.dl_level<=?', [(int)$id, $this->lvl()]);
            return [$f, $f ? '' : 'fsel_badnum'];
        }
        $cur = (int)($this->curFArea()['id'] ?? 0);
        $f = DB::row('SELECT f.* FROM {files} f JOIN {file_areas} a ON a.id=f.area_id WHERE f.approved=1 AND a.dl_level<=?
            AND LOWER(f.filename)=? ORDER BY CASE WHEN f.area_id=? THEN 0 ELSE 1 END, f.id', [$this->lvl(), mb_strtolower($v), $cur]);
        return [$f, $f ? '' : 'fdl_notfound'];
    }

    /** Prompt text: with number if a list was shown in this session. */
    private function pickPrompt(string $key): string
    {
        return $this->L(!empty($this->S['fl']) ? $key : $key . '_name');
    }

    /* ------------------------------------------------------------ view */

    private function e_fview(): void
    {
        $this->nl();
        $this->line(60, $this->pickPrompt('fview_prompt'));
    }

    private function i_fview(string $v): void
    {
        if (trim($v) === '') {
            $this->go('pgback');
            return;
        }
        [$f, $err] = $this->pickFile($v);
        $path = $f ? CB_DATA . '/files/' . $f['storage'] : '';
        $ext = $f ? strtolower(pathinfo((string)$f['filename'], PATHINFO_EXTENSION)) : '';
        if (!$f) {
            $this->say($err, cb_esc(trim($v)));
        } elseif (!in_array($ext, ['txt', 'nfo', 'diz', 'asc', 'ans'], true)) {
            $this->say('fview_type');
        } elseif (!is_file($path)) {
            $this->say('fdl_missing');
        } elseif ((int)filesize($path) > 65536) {
            $this->say('fview_big');
        } else {
            $this->viewFile($f, (string)file_get_contents($path), $ext);
            return;
        }
        $this->nl();
        $this->pause('pgback');
    }

    /** ANSI goes through the screen renderer (CP437, no SAUCE), text is CP437 unless it is valid UTF-8. Not a download. */
    private function viewFile(array $f, string $data, string $ext): void
    {
        $this->act(Lang::get('act_files'));
        $title = $this->L('fview_title', cb_esc((string)$f['filename']));
        $wad = in_array($ext, ['ans', 'txt'], true) && cb_wansidraw_base() !== '' ? (int)$f['id'] : 0;
        if ($ext === 'ans' || str_contains($data, "\e[")) {
            $lines = explode("\n", str_replace("\r", '', cb_strip_sauce($data)));
            $this->startPager('raw', array_keys($lines), $title, $lines, $wad);
            return;
        }
        $text = mb_check_encoding($data, 'UTF-8') ? $data : CP437::toUtf8($data);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $lines = array_map(static fn($l) => '|07' . cb_esc($l), cb_wrap($text, 79));
        $this->startPager('lines', array_keys($lines), $title, $lines, $wad);
    }

    /* ------------------------------------------------------------ marked files */

    private function e_fmark(): void
    {
        $this->nl();
        $this->line(60, $this->pickPrompt('fmark_prompt'));
    }

    private function i_fmark(string $v): void
    {
        if (trim($v) === '') {
            $this->go('pgback');
            return;
        }
        [$f, $err] = $this->pickFile($v);
        $mark = $this->S['mark'] ?? [];
        if (!$f) {
            $this->say($err, cb_esc(trim($v)));
        } elseif (in_array((int)$f['id'], $mark, true)) {
            $this->S['mark'] = array_values(array_diff($mark, [(int)$f['id']]));
            $this->say('fmark_off', cb_esc(mb_substr((string)$f['filename'], 0, 40)));
        } elseif (count($mark) >= 10) {
            $this->say('fmark_full');
        } else {
            $this->S['mark'] = array_merge($mark, [(int)$f['id']]);
            $this->say('fmark_on', cb_esc(mb_substr((string)$f['filename'], 0, 40)), count($this->S['mark']));
        }
        $this->nl();
        $this->pause('pgback');
    }

    /** Marked files that still exist and may be read, in the order they were marked. */
    private function markedFiles(): array
    {
        $out = [];
        foreach ($this->S['mark'] ?? [] as $id) {
            $f = DB::row('SELECT f.* FROM {files} f JOIN {file_areas} a ON a.id=f.area_id WHERE f.id=? AND f.approved=1 AND a.dl_level<=?', [(int)$id, $this->lvl()]);
            if ($f) {
                $out[] = $f;
            }
        }
        $this->S['mark'] = array_map(static fn($f) => (int)$f['id'], $out);
        return $out;
    }

    private function e_fmarks(): void
    {
        $files = $this->markedFiles();
        if (!$files) {
            $this->nl();
            $this->say('fmarks_empty');
            $this->nl();
            $this->pause('pgback');
            return;
        }
        $this->cls();
        $this->bar($this->L('fmarks_title'), (string)count($files));
        $this->pagerHeader('files');
        foreach ($files as $i => $f) {
            foreach ($this->fileLines($f, $i + 1) as $l) {
                $this->write($l);
                $this->nl();
            }
        }
        $this->nl();
        $this->line(3, $this->L('fmarks_prompt'));
    }

    private function i_fmarks(string $v): void
    {
        $v = trim($v);
        $mark = $this->S['mark'] ?? [];
        if (ctype_digit($v) && isset($mark[(int)$v - 1])) {
            array_splice($mark, (int)$v - 1, 1);
            $this->S['mark'] = $mark;
            $this->go('fmarks');
            return;
        }
        $this->go('pgback');
    }

    /* ------------------------------------------------------------ download */

    private function e_fdl(): void
    {
        $this->act(Lang::get('act_download'));
        $this->nl();
        $files = $this->markedFiles();
        if ($files) {
            $size = array_sum(array_map(static fn($f) => (int)$f['size'], $files));
            $this->S['st'] = 'fdl_mq';
            $this->yn($this->L('fdl_marked', count($files), cb_size($size)), true);
            return;
        }
        $this->line(60, $this->pickPrompt('fdl_prompt'));
    }

    private function i_fdl_mq(string $v): void
    {
        if ($this->yes($v, true)) {
            unset($this->S['pg']);
            $this->S['dq'] = ['ids' => $this->S['mark'] ?? [], 'skip' => [], 'ok' => 0];
            $this->go('fdlm');
            return;
        }
        $this->nl();
        $this->S['st'] = 'fdl';
        $this->line(60, $this->pickPrompt('fdl_prompt'));
    }

    /** Reason why the caller may not download the file now, as [key, args], or null. */
    private function dlRefusal(array $f): ?array
    {
        if (!is_file(CB_DATA . '/files/' . $f['storage'])) {
            return ['fdl_missing', []];
        }
        $kb = (int)ceil((int)$f['size'] / 1024);
        $lv = $this->level();
        $u = $this->user;
        if ((int)$lv['dl_kb'] > 0 && (int)$u['dl_kb_today'] + $kb > (int)$lv['dl_kb'] && !$this->isSysop()) {
            return ['fdl_limit', [(int)$lv['dl_kb'], max(0, (int)$lv['dl_kb'] - (int)$u['dl_kb_today'])]];
        }
        if ((int)$lv['ratio'] > 0 && !$this->isSysop() && ((int)$u['dl_files'] + 1) > ((int)$u['ul_files'] + 1) * (int)$lv['ratio']) {
            return ['fdl_ratio', [(int)$lv['ratio']]];
        }
        return null;
    }

    /** One-time link, counters, log and the transfer lines. */
    private function dlStart(array $f): void
    {
        $kb = (int)ceil((int)$f['size'] / 1024);
        $u = $this->user;
        $token = bin2hex(random_bytes(12));
        $_SESSION['cb_dl'] = array_filter($_SESSION['cb_dl'] ?? [], static fn($t) => $t['x'] > time());
        $_SESSION['cb_dl'][$token] = ['f' => (int)$f['id'], 'x' => time() + 600];
        DB::q('UPDATE {files} SET downloads=downloads+1 WHERE id=?', [(int)$f['id']]);
        DB::q('UPDATE {users} SET dl_files=dl_files+1, dl_kb=dl_kb+?, dl_kb_today=dl_kb_today+? WHERE id=?', [$kb, $kb, (int)$u['id']]);
        $this->user = DB::row('SELECT * FROM {users} WHERE id=?', [(int)$u['id']]);
        cb_log((int)$u['id'], $u['handle'], 'Download ' . $f['filename']);
        $baud = $this->baud();
        $secs = $baud > 0 ? (int)ceil(((int)$f['size'] * 10) / $baud) : 0;
        $this->nl();
        $this->say('fdl_start', cb_esc($f['filename']), cb_kb((int)$f['size']));
        $this->nl();
        if ($secs > 0) {
            $this->say('fdl_eta', $baud, sprintf('%d:%02d', intdiv($secs, 60), $secs % 60));
            $this->nl();
        }
        $this->say('fdl_go');
        $this->nl();
        $this->download(cb_base_path() . 'download.php?t=' . $token);
        $this->S['mark'] = array_values(array_diff($this->S['mark'] ?? [], [(int)$f['id']]));
    }

    private function i_fdl(string $v): void
    {
        unset($this->S['pg']);
        if (trim($v) === '') {
            $this->menu();
            return;
        }
        [$f, $err] = $this->pickFile($v);
        if (!$f) {
            $this->say($err, cb_esc(CP437::clean($v, 120)));
            $this->nl();
            $this->pause();
            return;
        }
        $no = $this->dlRefusal($f);
        if ($no !== null) {
            $this->say($no[0], ...$no[1]);
            $this->nl();
            $this->pause();
            return;
        }
        $this->dlStart($f);
        $this->pause();
    }

    /** All marked files one after the other, a short wait between them so the browser starts each download. */
    private function e_fdlm(): void
    {
        $q = &$this->S['dq'];
        while ($q['ids']) {
            $id = (int)array_shift($q['ids']);
            $f = DB::row('SELECT f.* FROM {files} f JOIN {file_areas} a ON a.id=f.area_id WHERE f.id=? AND f.approved=1 AND a.dl_level<=?', [$id, $this->lvl()]);
            if (!$f) {
                continue;
            }
            $no = $this->dlRefusal($f);
            if ($no !== null) {
                $q['skip'][] = [(string)$f['filename'], $no[0]];
                continue;
            }
            $this->dlStart($f);
            $q['ok']++;
            if ($q['ids']) {
                $this->wait(2500, $this->L('fdl_next'));
                return;
            }
            break;
        }
        $this->nl();
        $this->say('fdl_marked_done', (int)$q['ok']);
        $this->nl();
        foreach ($q['skip'] as [$name, $why]) {
            $this->write('  |12' . cb_esc($name) . '|08: |07' . $this->L($why === 'fdl_ratio' ? 'fdl_skip_ratio' : ($why === 'fdl_limit' ? 'fdl_skip_limit' : 'fdl_skip_missing')));
            $this->nl();
        }
        unset($this->S['dq']);
        $this->pause();
    }

    private function i_fdlm(string $v): void
    {
        if (empty($this->S['dq'])) {
            $this->menu();
            return;
        }
        $this->go('fdlm');
    }

    /* ------------------------------------------------------------ upload */

    private function uploadArea(): ?array
    {
        $a = $this->curFArea();
        if ($a && (int)$a['uploads'] === 1 && (int)$a['ul_level'] <= $this->lvl()) {
            return $a;
        }
        return DB::row('SELECT * FROM {file_areas} WHERE uploads=1 AND ul_level<=? ORDER BY sort, id', [$this->lvl()]);
    }

    public static function uploadMaxBytes(): int
    {
        $ini = static function (string $k): int {
            $v = trim((string)ini_get($k));
            if ($v === '') {
                return PHP_INT_MAX;
            }
            $n = (int)$v;
            if ($n <= 0) {
                return PHP_INT_MAX; // 0 = unlimited
            }
            $u = strtolower(substr($v, -1));
            return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
        };
        $cfg = Settings::int('upload_max_kb', 8192) * 1024;
        return max(1024, min($cfg, $ini('upload_max_filesize'), $ini('post_max_size')));
    }

    private function fileUpload(): void
    {
        $a = $this->uploadArea();
        if (!$a) {
            $this->nl();
            $this->say('fup_noarea');
            $this->nl();
            $this->pause();
            return;
        }
        $this->go('fup', (int)$a['id']);
    }

    private function e_fup(int $area): void
    {
        $a = DB::row('SELECT * FROM {file_areas} WHERE id=?', [$area]);
        $this->S['up'] = ['area' => $area];
        unset($_SESSION['cb_up']);
        $this->act(Lang::get('act_upload'));
        $this->nl();
        $this->say('fup_info', cb_esc($a['name'] ?? '?'), cb_kb(self::uploadMaxBytes()), cb_esc(Settings::get('upload_ext', 'zip,arj,lzh,rar,7z,txt,ans')));
        $this->nl(2);
        $this->upload($this->L('fup_prompt'));
    }

    private function i_fup(string $v): void
    {
        if (strtoupper($v) === 'Q' || empty($this->S['up'])) {
            unset($this->S['up'], $_SESSION['cb_up']);
            $this->menu();
            return;
        }
        $up = $_SESSION['cb_up'] ?? null;
        if (!$up || !is_file($up['tmp'] ?? '')) {
            $this->nl();
            $this->say('fup_failed');
            $this->nl();
            $this->upload($this->L('fup_prompt'));
            return;
        }
        $this->nl();
        $this->say('fup_received', cb_esc($up['name']), cb_kb((int)$up['size']));
        $this->nl();
        if (!empty($up['diz'])) {
            $this->nl();
            $this->say('fup_diz_found');
            $this->nl();
            foreach (array_slice(explode("\n", (string)$up['diz']), 0, 10) as $l) {
                $this->write('|07  ' . cb_esc($l));
                $this->nl();
            }
            $this->S['st'] = 'fup_diz';
            $this->yn($this->L('fup_diz_use'), true);
            return;
        }
        $this->go('fup_desc');
    }

    private function i_fup_diz(string $v): void
    {
        if ($this->yes($v, true)) {
            $this->finishUpload((string)($_SESSION['cb_up']['diz'] ?? ''));
            return;
        }
        $this->go('fup_desc');
    }

    private function e_fup_desc(): void
    {
        $this->line(60, $this->L('fup_desc'));
    }

    private function i_fup_desc(string $v): void
    {
        $v = CP437::clean($v, 60);
        if (mb_strlen($v) < 3) {
            $this->say('fup_desc_short');
            $this->go('fup_desc');
            return;
        }
        $this->finishUpload($v);
    }

    private function finishUpload(string $desc): void
    {
        $up = $_SESSION['cb_up'] ?? null;
        $area = (int)($this->S['up']['area'] ?? 0);
        unset($_SESSION['cb_up'], $this->S['up']);
        if (!$up || !is_file($up['tmp']) || $area <= 0) {
            $this->say('fup_failed');
            $this->nl();
            $this->pause();
            return;
        }
        $dir = CB_DATA . '/files/' . $area;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = (string)$up['name'];
        if (DB::val('SELECT COUNT(*) FROM {files} WHERE area_id=? AND LOWER(filename)=?', [$area, mb_strtolower($name)]) || is_file("$dir/$name")) {
            @unlink($up['tmp']);
            $this->say('fup_dupe', cb_esc($name));
            $this->nl();
            $this->pause();
            return;
        }
        if (!@rename($up['tmp'], "$dir/$name")) {
            @unlink($up['tmp']);
            $this->say('fup_failed');
            $this->nl();
            $this->pause();
            return;
        }
        $approved = (Settings::get('upload_auto_approve', '0') === '1' || $this->isSysop()) ? 1 : 0;
        $banned = $this->bannedWord($desc);
        if ($banned !== null) {
            // never online without a check, the sysop decides
            $approved = 0;
            // not "Upload ...", the statistics count those lines
            cb_log((int)$this->user['id'], $this->user['handle'], 'Banned word "' . $banned . '" in the description of upload ' . $name . ', waits for review');
        }
        DB::insert('files', [
            'area_id' => $area, 'filename' => $name, 'size' => (int)$up['size'], 'description' => mb_substr($desc, 0, 2000),
            'uploader_id' => (int)$this->user['id'], 'uploader' => $this->user['handle'], 'added' => time(),
            'downloads' => 0, 'approved' => $approved, 'storage' => $area . '/' . $name,
        ]);
        $kb = (int)ceil((int)$up['size'] / 1024);
        DB::q('UPDATE {users} SET ul_files=ul_files+1, ul_kb=ul_kb+? WHERE id=?', [$kb, (int)$this->user['id']]);
        cb_log((int)$this->user['id'], $this->user['handle'], 'Upload ' . $name . ($approved ? '' : ' (pending)'));
        $this->nl();
        $this->say($approved ? 'fup_done' : 'fup_pending', cb_esc($name));
        $this->nl();
        $this->pause();
    }
}
