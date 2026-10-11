<?php
/**
 * Sysop backend: polls. Callers vote once in the terminal, a poll with votes can only be
 * closed or deleted. The last day is inclusive.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

function a_poll_open(array $p): bool
{
    return (int)$p['closed'] === 0 && ((string)$p['closes'] === '' || date('Y-m-d') <= (string)$p['closes']);
}

/** Question, answers and last day from the form, or an error text. */
function a_poll_input(): array
{
    $q = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($_POST['question'] ?? '')) ?? '');
    $opts = [];
    foreach ((array)($_POST['opt'] ?? []) as $o) {
        $o = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$o) ?? '');
        if ($o !== '') {
            $opts[] = $o;
        }
    }
    $closes = trim((string)($_POST['closes'] ?? ''));
    $err = null;
    if ($q === '' || mb_strlen($q) > 70) {
        $err = t('The question needs 1 to 70 characters.');
    } elseif (count($opts) < 2 || count($opts) > 8) {
        $err = t('A poll needs 2 to 8 answers.');
    } elseif (max(array_map('mb_strlen', $opts)) > 40) {
        $err = t('An answer has more than 40 characters.');
    } elseif ($closes !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $closes, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) {
        $err = t('The date is not valid.');
    }
    return [$q, $opts, $closes, $err];
}

/** Form for a new poll or for one without votes. */
function a_poll_form(array $q, array $opts, string $closes, string $button): string
{
    $h = '<label>' . h(t('Question')) . '<input name="question" value="' . h((string)($q['question'] ?? '')) . '" maxlength="70" required></label>' .
        '<fieldset><legend class="note">' . h(t('Answers (2 to 8, empty fields are left out)')) . '</legend><div class="grid2">';
    for ($i = 0; $i < 8; $i++) {
        $h .= '<label>' . h(t('Answer {1}', $i + 1)) . '<input name="opt[]" value="' . h((string)($opts[$i] ?? '')) . '" maxlength="40"></label>';
    }
    return $h . '</div></fieldset><label>' . h(t('Open until (optional)')) . '<input type="date" name="closes" value="' . h($closes) . '">' .
        '<span class="hint">' . h(t('Up to and including this day. Empty: until you close the poll.')) . '</span></label>' .
        '<p><button class="btn" type="submit">' . h($button) . '</button></p>';
}

function page_polls(array $admin): void
{
    if (a_post() && isset($_POST['create'])) {
        [$q, $opts, $closes, $err] = a_poll_input();
        if ($err !== null) {
            $_SESSION['cb_poll_draft'] = [$q, $opts, $closes];
            a_flash($err, 'bad');
            a_go('polls');
        }
        $id = DB::insert('polls', ['question' => $q, 'created' => time(), 'closes' => $closes, 'closed' => 0, 'created_by' => (int)$admin['id']]);
        foreach ($opts as $i => $o) {
            DB::insert('poll_options', ['poll_id' => $id, 'text' => $o, 'sort' => ($i + 1) * 10]);
        }
        cb_log((int)$admin['id'], $admin['handle'], 'Created poll ' . $id . ': ' . $q);
        a_flash(t('Poll created.'));
        a_go('polls');
    }
    $draft = $_SESSION['cb_poll_draft'] ?? null;
    unset($_SESSION['cb_poll_draft']);

    echo '<h1>' . h(t('Polls')) . '</h1>';
    echo '<p class="note">' . h(t('Callers vote in the terminal with the menu command POLLS, once per poll. They see the result after their vote or when the poll is finished.')) . '</p>';
    $polls = DB::all('SELECT p.*, (SELECT COUNT(*) FROM {poll_votes} v WHERE v.poll_id=p.id) AS votes FROM {polls} p ORDER BY p.id DESC');
    if ($polls) {
        echo '<div class="tablewrap"><table class="polls"><tr><th>' . h(t('Question')) . '</th><th>' . h(t('Status')) . '</th><th class="num">' . h(t('Votes')) . '</th><th>' .
            h(t('Created')) . '</th></tr>';
        foreach ($polls as $p) {
            $status = a_poll_open($p) ? ((string)$p['closes'] !== '' ? t('open until {1}', date('d.m.Y', (int)strtotime((string)$p['closes']))) : t('open'))
                : t('finished');
            echo '<tr><td><a href="' . h(a_url('poll', ['id' => $p['id']])) . '">' . h($p['question']) . '</a></td><td data-label="' . h(t('Status')) . '">' .
                '<span class="tag' . (a_poll_open($p) ? ' ok' : '') . '">' . h($status) . '</span></td><td class="num" data-label="' . h(t('Votes')) . '">' . (int)$p['votes'] .
                '</td><td data-label="' . h(t('Created')) . '">' . date('d.m.Y', (int)$p['created']) . '</td></tr>';
        }
        echo '</table></div>';
    } else {
        echo '<div class="panel"><p class="note">' . h(t('There is no poll yet.')) . '</p></div>';
    }
    echo '<h2>' . h(t('New poll')) . '</h2><form method="post" class="form panel">' . a_csrf() . '<input type="hidden" name="create" value="1">' .
        a_poll_form(['question' => $draft[0] ?? ''], $draft[1] ?? [], (string)($draft[2] ?? ''), t('Create poll')) . '</form>';
}

function page_poll(array $admin): void
{
    $id = (int)($_GET['id'] ?? 0);
    $p = DB::row('SELECT * FROM {polls} WHERE id=?', [$id]);
    if (!$p) {
        a_go('polls');
    }
    $votes = (int)DB::val('SELECT COUNT(*) FROM {poll_votes} WHERE poll_id=?', [$id]);
    if (a_post()) {
        if (isset($_POST['delete'])) {
            DB::q('DELETE FROM {poll_votes} WHERE poll_id=?', [$id]);
            DB::q('DELETE FROM {poll_options} WHERE poll_id=?', [$id]);
            DB::q('DELETE FROM {polls} WHERE id=?', [$id]);
            cb_log((int)$admin['id'], $admin['handle'], 'Deleted poll ' . $id . ': ' . $p['question']);
            a_flash(t('Poll deleted.'));
            a_go('polls');
        }
        if (isset($_POST['close']) && a_poll_open($p)) {
            DB::q('UPDATE {polls} SET closed=1 WHERE id=?', [$id]);
            cb_log((int)$admin['id'], $admin['handle'], 'Closed poll ' . $id);
            a_flash(t('Poll finished.'));
        }
        if (isset($_POST['save'])) {
            if ($votes > 0) {
                a_flash(t('A poll with votes cannot be changed any more.'), 'bad');
                a_go('poll', ['id' => $id]);
            }
            [$q, $opts, $closes, $err] = a_poll_input();
            if ($err !== null) {
                a_flash($err, 'bad');
                a_go('poll', ['id' => $id]);
            }
            DB::update('polls', ['question' => $q, 'closes' => $closes], 'id=?', [$id]);
            DB::q('DELETE FROM {poll_options} WHERE poll_id=?', [$id]);
            foreach ($opts as $i => $o) {
                DB::insert('poll_options', ['poll_id' => $id, 'text' => $o, 'sort' => ($i + 1) * 10]);
            }
            cb_log((int)$admin['id'], $admin['handle'], 'Changed poll ' . $id);
            a_flash(t('Poll saved.'));
        }
        a_go('poll', ['id' => $id]);
    }

    $opts = DB::all('SELECT o.*, (SELECT COUNT(*) FROM {poll_votes} v WHERE v.option_id=o.id) AS n FROM {poll_options} o WHERE o.poll_id=? ORDER BY o.sort, o.id', [$id]);
    echo '<h1>' . h($p['question']) . '</h1>';
    echo '<p class="note">' . h(a_poll_open($p) ? ((string)$p['closes'] !== '' ? t('open until {1}', date('d.m.Y', (int)strtotime((string)$p['closes']))) : t('open')) : t('finished')) .
        ', ' . h(t('{1} vote(s)', $votes)) . '</p>';
    echo '<div class="panel"><h2>' . h(t('Result')) . '</h2><table class="poll-result">';
    foreach ($opts as $o) {
        $pct = $votes > 0 ? round((int)$o['n'] * 100 / $votes) : 0;
        echo '<tr><th>' . h($o['text']) . '</th><td><span class="pbar" style="width:' . $pct . '%"></span></td><td class="num">' . $pct . '%</td><td class="num">' . (int)$o['n'] . '</td></tr>';
    }
    echo '</table></div>';
    if ($votes === 0) {
        echo '<h2>' . h(t('Change')) . '</h2><form method="post" class="form panel">' . a_csrf() . '<input type="hidden" name="save" value="1">' .
            a_poll_form($p, array_column($opts, 'text'), (string)$p['closes'], t('Save poll')) . '</form>';
    } else {
        echo '<p class="note">' . h(t('This poll has votes. It can only be finished or deleted.')) . '</p>';
    }
    echo '<div class="row-actions">';
    if (a_poll_open($p)) {
        echo '<form method="post"' . a_confirm(t('Finish this poll now?')) . '>' . a_csrf() . '<button class="btn small ghost" name="close" value="1">' . h(t('Finish now')) . '</button></form>';
    }
    echo '<form method="post"' . a_confirm(t('Delete this poll with all votes?')) . '>' . a_csrf() . '<button class="btn small danger ghost" name="delete" value="1">' .
        h(t('Delete poll')) . '</button></form><a class="btn small ghost" href="' . h(a_url('polls')) . '">' . h(t('Back')) . '</a></div>';
}
