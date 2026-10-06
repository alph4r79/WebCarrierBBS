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

    public function startPager(string $kind, array $ids, string $title, array $lines = []): void
    {
        $this->S['pg'] = ['kind' => $kind, 'ids' => array_values($ids), 'pos' => 0, 'title' => $title, 'lines' => $lines];
        $this->cls();
        $this->bar($title);
        $this->pagerHeader($kind);
        $this->go('pager');
    }

    private function pagerHeader(string $kind): void
    {
        if ($kind === 'files') {
            $this->write('|03' . cb_pad($this->L('col_file'), 19) . cb_pad($this->L('col_size'), 6, 'R') . ' ' .
                cb_pad($this->L('col_date'), 9) . $this->L('col_desc'));
            $this->nl();
            $this->rule();
        } elseif ($kind === 'users') {
            $this->write('|03' . cb_pad($this->L('col_user'), 21) . cb_pad($this->L('col_loc'), 31) .
                cb_pad($this->L('col_calls'), 7, 'R') . '  ' . $this->L('col_last'));
            $this->nl();
            $this->rule();
        }
    }

    private function pagerItem(string $kind, int $id): array
    {
        if ($kind === 'lines') {
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
        if (!$f) {
            return [];
        }
        $desc = cb_wrap(trim((string)$f['description']) !== '' ? (string)$f['description'] : $this->L('no_desc'), 44);
        $desc = array_slice(array_values(array_filter($desc, static fn($l) => trim($l) !== '')), 0, 6) ?: [''];
        $cols = '|10' . cb_pad(cb_kb((int)$f['size']), 6, 'R') . ' |03' . cb_pad(date(Lang::get('fmt_date'), (int)$f['added']), 9);
        $out = [];
        $name = (string)$f['filename'];
        if (mb_strlen($name) > 18) {
            $out[] = '|15' . cb_esc($name);
            $out[] = str_repeat(' ', 19) . $cols . '|07' . cb_esc($desc[0]);
        } else {
            $out[] = '|15' . cb_esc(cb_pad($name, 19)) . $cols . '|07' . cb_esc($desc[0]);
        }
        foreach (array_slice($desc, 1) as $d) {
            $out[] = str_repeat(' ', 35) . '|07' . cb_esc($d);
        }
        return $out;
    }

    private function e_pager(): void
    {
        $pg = &$this->S['pg'];
        $used = 0;
        $limit = $pg['pos'] === 0 ? 19 : 22;
        while ($pg['pos'] < count($pg['ids'])) {
            $lines = $this->pagerItem($pg['kind'], (int)$pg['ids'][$pg['pos']]);
            if ($used > 0 && $used + count($lines) > $limit) {
                break;
            }
            foreach ($lines as $l) {
                $this->write($l);
                $this->nl();
            }
            $used += count($lines);
            $pg['pos']++;
        }
        $files = $pg['kind'] === 'files';
        $dkey = Lang::get('key_download');
        if ($pg['pos'] < count($pg['ids'])) {
            $this->hot("\r Q" . ($files ? $dkey : '') . Lang::get('key_no'), $this->L($files ? 'pager_more_files' : 'pager_more'), true);
        } else {
            $this->hot("\rQ" . ($files ? $dkey : ''), $this->L($files ? 'pager_end_files' : 'pager_end'), true);
        }
    }

    private function i_pager(string $v): void
    {
        $k = strtoupper($v);
        $pg = $this->S['pg'] ?? null;
        if (!$pg) {
            $this->menu();
            return;
        }
        if ($k === Lang::get('key_download') && $pg['kind'] === 'files') {
            $this->go('fdl');
            return;
        }
        if (($k === "\r" || $k === ' ') && $pg['pos'] < count($pg['ids'])) {
            $this->go('pager');
            return;
        }
        unset($this->S['pg']);
        $this->menu();
    }

    /* ------------------------------------------------------------ download */

    private function e_fdl(): void
    {
        $this->act(Lang::get('act_download'));
        $this->nl();
        $this->line(60, $this->L('fdl_prompt'));
    }

    private function i_fdl(string $v): void
    {
        unset($this->S['pg']);
        $v = CP437::clean($v, 120);
        if ($v === '') {
            $this->menu();
            return;
        }
        $cur = (int)($this->curFArea()['id'] ?? 0);
        $f = DB::row('SELECT f.* FROM {files} f JOIN {file_areas} a ON a.id=f.area_id WHERE f.approved=1 AND a.dl_level<=?
            AND LOWER(f.filename)=? ORDER BY CASE WHEN f.area_id=? THEN 0 ELSE 1 END, f.id', [$this->lvl(), mb_strtolower($v), $cur]);
        if (!$f) {
            $this->say('fdl_notfound', cb_esc($v));
            $this->nl();
            $this->pause();
            return;
        }
        $path = CB_DATA . '/files/' . $f['storage'];
        if (!is_file($path)) {
            $this->say('fdl_missing');
            $this->nl();
            $this->pause();
            return;
        }
        $kb = (int)ceil((int)$f['size'] / 1024);
        $lv = $this->level();
        $u = $this->user;
        if ((int)$lv['dl_kb'] > 0 && (int)$u['dl_kb_today'] + $kb > (int)$lv['dl_kb'] && !$this->isSysop()) {
            $this->say('fdl_limit', (int)$lv['dl_kb'], max(0, (int)$lv['dl_kb'] - (int)$u['dl_kb_today']));
            $this->nl();
            $this->pause();
            return;
        }
        if ((int)$lv['ratio'] > 0 && !$this->isSysop() && ((int)$u['dl_files'] + 1) > ((int)$u['ul_files'] + 1) * (int)$lv['ratio']) {
            $this->say('fdl_ratio', (int)$lv['ratio']);
            $this->nl();
            $this->pause();
            return;
        }
        $token = bin2hex(random_bytes(12));
        $_SESSION['cb_dl'] = array_filter($_SESSION['cb_dl'] ?? [], static fn($t) => $t['x'] > time());
        $_SESSION['cb_dl'][$token] = ['f' => (int)$f['id'], 'x' => time() + 600];
        DB::q('UPDATE {files} SET downloads=downloads+1 WHERE id=?', [(int)$f['id']]);
        DB::q('UPDATE {users} SET dl_files=dl_files+1, dl_kb=dl_kb+?, dl_kb_today=dl_kb_today+? WHERE id=?', [$kb, $kb, (int)$u['id']]);
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
        $this->pause();
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
