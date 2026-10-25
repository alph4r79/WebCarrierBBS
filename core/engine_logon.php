<?php
/**
 * WebCarrier BBS: around the login. What is new since the last call, the questionnaire for new
 * users, the code of the second factor and things the poll reports during a call.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

trait EngineLogon
{
    /* ------------------------------------------------------------ new since the last call */

    /**
     * Counts since the last call, key => number, only entries above 0. Null if the box is switched
     * off or this is the first call. Own messages, oneliners and scores do not count. Polls count
     * as long as they run and the caller has not voted.
     */
    private function newSinceCounts(): ?array
    {
        $prev = (int)($this->S['prevcall'] ?? 0);
        if ($prev <= 0 || !$this->user || Settings::get('newsince', '1') !== '1') {
            return null;
        }
        $uid = (int)$this->user['id'];
        $lvl = $this->lvl();
        $n = [
            'msgs' => (int)DB::val('SELECT COUNT(*) FROM {messages} m JOIN {msg_areas} a ON a.id=m.area_id
                WHERE m.posted>? AND m.private=0 AND m.from_id<>? AND a.read_level<=?', [$prev, $uid, $lvl]),
            'files' => (int)DB::val('SELECT COUNT(*) FROM {files} f JOIN {file_areas} a ON a.id=f.area_id
                WHERE f.added>? AND f.approved=1 AND a.dl_level<=?', [$prev, $lvl]),
            'users' => (int)DB::val('SELECT COUNT(*) FROM {users} WHERE created>? AND pending=0 AND locked=0 AND id<>?', [$prev, $uid]),
            'one' => (int)DB::val('SELECT COUNT(*) FROM {oneliners} WHERE time>? AND user_id<>?', [$prev, $uid]),
            // all running polls without a vote of the caller, also older ones
            'polls' => $this->newPolls(),
            'scores' => 0,
        ];
        $doors = array_keys($this->reachableDoors());
        if ($doors) {
            $n['scores'] = (int)DB::val('SELECT COUNT(*) FROM {door_scores} s JOIN {users} u ON u.id=s.user_id
                WHERE s.time>? AND s.user_id<>? AND u.locked=0 AND u.pending=0 AND s.door IN (' . implode(',', array_fill(0, count($doors), '?')) . ')',
                array_merge([$prev, $uid], array_map('strval', $doors)));
        }
        return array_filter($n);
    }

    /** The box as text with pipe codes and |CR. */
    private function newSinceBox(array $counts): string
    {
        $prev = (int)($this->S['prevcall'] ?? 0);
        $title = $this->L('ns_title', date(Lang::get('fmt_date'), $prev), date('H:i', $prev));
        $lines = ['  |03┌─ |11' . $title . ' |03' . str_repeat('─', max(0, 72 - 3 - mb_strlen($title))) . '┐'];
        foreach ($counts as $k => $n) {
            $text = $this->L('ns_' . $k);
            if ($k === 'polls') {
                $one = $n === 1 ? '_one' : '';
                $text = $this->L('ns_polls' . $one);
                $key = DB::val("SELECT i.hotkey FROM {menu_items} i JOIN {menus} m ON m.id=i.menu_id
                    WHERE m.name='main' AND i.command='POLLS' AND i.min_level<=?", [$this->lvl()]);
                if ($key !== null) {
                    $text = $this->L('ns_polls' . $one . '_key', cb_esc(mb_strtoupper((string)$key)));
                }
            }
            $lines[] = '  |03│ |15' . cb_pad((string)$n, 5, 'R') . ' |07' . cb_pad($text, 64) . ' |03│';
        }
        if (!$counts) {
            $lines[] = '  |03│ |07' . cb_pad($this->L('ns_none'), 70) . ' |03│';
        }
        $lines[] = '  |03└' . str_repeat('─', 72) . '┘';
        return implode('|CR', $lines) . '|07';
    }

    /** True if the logon screen shows the box itself with @NEWSINCE@. */
    private function logonHasNewSince(): bool
    {
        $p = cb_screen_path('logon');
        return $p !== null && str_contains((string)file_get_contents($p), '@NEWSINCE@');
    }

    /**
     * End of the greeting: with something to read the keys N and F, otherwise the usual pause.
     * After a direct dial link the pause leads to its target instead of the main menu.
     */
    private function newSincePrompt(?array $counts): void
    {
        if (!empty($this->S['dw'])) {
            $this->pause('direct');
            return;
        }
        $keys = '';
        $prompt = '';
        foreach (['msgs' => 'N', 'files' => 'F'] as $k => $key) {
            if (!empty($counts[$k])) {
                $keys .= $key;
                $prompt .= $this->L('ns_key_' . strtolower($key)) . ' ';
            }
        }
        if ($keys === '') {
            $this->pause('menu');
            return;
        }
        $this->S['st'] = 'newsince';
        $this->hot($keys . "\r", $prompt . $this->L('ns_key_enter'), true);
    }

    private function i_newsince(string $v): void
    {
        $this->S['menu'] = 'main';
        match (strtoupper($v)) {
            'N' => $this->newScan(),
            'F' => $this->fileNew(),
            default => $this->menu(),
        };
    }

    /* ------------------------------------------------------------ questionnaire for new users */

    private function nuQuestions(): array
    {
        if (Settings::get('nu_questions_on', '0') !== '1') {
            return [];
        }
        return DB::all('SELECT * FROM {nu_questions} WHERE active=1 ORDER BY sort, id LIMIT 10');
    }

    public static function nuOptions(array $q): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string)$q['options'])), static fn($o) => $o !== ''));
    }

    /** Ask question $i, after the last one the summary of the registration. */
    private function e_nu_q(int $i = 0, bool $again = false): void
    {
        $q = $this->nuQuestions()[$i] ?? null;
        if (!$q) {
            $this->nuSummary();
            return;
        }
        $this->S['nu']['qi'] = $i;
        if ($i === 0 && !$again) {
            $this->nl();
            $this->say('nuq_head');
            $this->nl();
        }
        $optional = (int)$q['required'] !== 1;
        $this->nl();
        $this->write('|15' . cb_esc((string)$q['text']) . '|07');
        $this->nl();
        if ($optional) {
            $this->say('nuq_optional');
            $this->nl();
        }
        if ($q['type'] === 'yn') {
            $y = Lang::get('key_yes');
            $n = Lang::get('key_no');
            $this->hot($y . $n . ($optional ? "\r" : ''), $this->L('nuq_yn', $y, $n));
        } elseif ($q['type'] === 'choice') {
            $opts = self::nuOptions($q);
            foreach ($opts as $k => $o) {
                $this->write('  |08[|15' . ($k + 1) . '|08] |07' . cb_esc($o));
                $this->nl();
            }
            $this->hot(implode('', array_map('strval', range(1, max(1, count($opts))))) . ($optional ? "\r" : ''), $this->L('nuq_choice'));
        } else {
            $this->line(max(1, min(60, (int)$q['maxlen'])), $this->L('nuq_answer'));
        }
    }

    private function i_nu_q(string $v): void
    {
        $i = (int)($this->S['nu']['qi'] ?? 0);
        $q = $this->nuQuestions()[$i] ?? null;
        if (!$q || !isset($this->S['nu']['hash'])) {
            $this->nuSummary();
            return;
        }
        $optional = (int)$q['required'] !== 1;
        $answer = null;
        if ($q['type'] === 'yn') {
            $k = mb_strtoupper($v);
            $answer = $k === Lang::get('key_yes') ? Lang::get('nuq_yes') : ($k === Lang::get('key_no') ? Lang::get('nuq_no') : null);
        } elseif ($q['type'] === 'choice') {
            $answer = ctype_digit($v) ? (self::nuOptions($q)[(int)$v - 1] ?? null) : null;
        } else {
            $t = CP437::clean($v, max(1, min(60, (int)$q['maxlen'])));
            if ($t !== '' && ($w = cb_banned_word($t)) !== null) {
                $this->say('ban_word', cb_esc($w));
                $this->nl();
                $this->go('nu_q', $i, true);
                return;
            }
            $answer = $t === '' ? null : $t;
        }
        if ($answer === null && !$optional) {
            $this->say('nuq_required');
            $this->nl();
            $this->go('nu_q', $i, true);
            return;
        }
        if ($answer !== null) {
            $this->S['nu']['qa'][] = ['id' => (int)$q['id'], 'q' => (string)$q['text'], 'a' => mb_substr($answer, 0, 60)];
        }
        $this->go('nu_q', $i + 1);
    }

    private function nuSummary(): void
    {
        $this->nl();
        $this->say('nu_summary', cb_esc($this->S['nu']['handle']), cb_esc($this->S['nu']['location']));
        if (cb_has_public_areas()) {
            $this->say('nu_public_note');
            $this->nl();
        }
        $this->S['st'] = 'nu_confirm';
        $this->yn($this->L('nu_confirm'), true);
    }

    /** Answers collected during the registration, stored together with the account. */
    private function nuStoreAnswers(int $uid, array $answers): void
    {
        foreach ($answers as $a) {
            DB::insert('nu_answers', ['user_id' => $uid, 'question_id' => (int)$a['id'], 'question' => (string)$a['q'],
                'answer' => (string)$a['a'], 'time' => time()]);
        }
    }

    /* ------------------------------------------------------------ second factor */

    private function e_totp(): void
    {
        $this->line(12, $this->L('totp_prompt'));
    }

    /** Code of the app or a backup code after the right password. Three wrong tries hang up. */
    private function i_totp(string $v): void
    {
        $id = (int)($this->S['tmpuid'] ?? 0);
        $u = DB::row('SELECT * FROM {users} WHERE id=?', [$id]);
        if (!$u || (int)($this->S['pwok'] ?? 0) !== $id || (int)$u['totp_on'] !== 1 || (int)$u['locked'] === 1 || (int)$u['pending'] === 1
            || cb_login_blocked($id)) {
            $this->say('login_toomany');
            $this->hangup();
            return;
        }
        $how = cb_second_factor($u, $v);
        if ($how !== null) {
            if ($how === 'backup') {
                cb_log($id, (string)$u['handle'], 'Backup code used (terminal)');
            }
            $this->doLogin($id);
            return;
        }
        $this->S['tries2'] = (int)($this->S['tries2'] ?? 0) + 1;
        if ($this->S['tries2'] >= 3) {
            cb_log($id, (string)$u['handle'], 'Wrong code, disconnected after 3 tries (terminal)');
            $this->say('login_toomany');
            $this->hangup();
            return;
        }
        cb_log($id, (string)$u['handle'], 'Wrong code (terminal)');
        $this->say('totp_wrong');
        $this->go('totp');
    }

    /* ------------------------------------------------------------ during the call */

    /** Kind of the hint for a hidden browser tab: chat, msg, turn or bye. A chat wins over the rest. */
    public function hint(string $kind): void
    {
        if ($this->notify === null || $kind === 'chat') {
            $this->notify = $kind;
        }
    }

    /** Checked with every poll: private mail that arrived during the call, and the last two minutes of the time. */
    private function pollExtras(): void
    {
        if (!$this->user) {
            return;
        }
        $uid = (int)$this->user['id'];
        $rows = DB::all('SELECT id, from_name FROM {messages} WHERE private=1 AND to_id=? AND id>? AND from_id<>? ORDER BY id',
            [$uid, (int)($this->S['mailmax'] ?? 0), $uid]);
        if ($rows) {
            $this->S['mailmax'] = (int)end($rows)['id'];
            $names = array_values(array_unique(array_column($rows, 'from_name')));
            $this->write(count($names) === 1 ? $this->L('mail_arrived', cb_esc((string)$names[0])) : $this->L('mail_arrived_n', count($rows)));
            $this->w->buf .= "\x07\r\n";
            $this->hint('msg');
        }
        $left = $this->timeLeft();
        if ($left <= 120 && $left > 0 && empty($this->S['twarn'])) {
            $this->S['twarn'] = 1;
            $this->write($this->L('time_warn', (int)ceil($left / 60)));
            $this->w->buf .= "\x07\r\n";
            $this->hint('bye');
        }
    }

    /** Tube effect for this caller: the setting of the sysop unless the caller switched it off. */
    private function crtMode(): string
    {
        $m = Settings::get('crt', 'off');
        if (!in_array($m, ['light', 'strong'], true) || ($this->user && (int)$this->user['crt'] === 0)) {
            return 'off';
        }
        return $m;
    }
}
