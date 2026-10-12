<?php
/**
 * Sysop backend: questionnaire for new users.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

function a_question_types(): array
{
    return ['text' => t('Free text'), 'choice' => t('Choice'), 'yn' => t('Yes or no')];
}

/** Question from the form: [row, error or null]. */
function a_question_input(): array
{
    $type = (string)($_POST['type'] ?? 'text');
    $type = isset(a_question_types()[$type]) ? $type : 'text';
    $opts = [];
    $err = null;
    foreach (preg_split('/\R/u', (string)($_POST['options'] ?? '')) ?: [] as $o) {
        $o = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $o) ?? '');
        if ($o === '') {
            continue;
        }
        $opts[] = $o;
        if (mb_strlen($o) > 30) {
            $err = t('Answer {1} has more than 30 characters.', count($opts));
        }
    }
    $text = a_in('text', 70);
    if ($text === '') {
        $err = t('Please enter the question.');
    } elseif ($type === 'choice' && (count($opts) < 2 || count($opts) > 8)) {
        $err = t('A choice needs 2 to 8 answers.');
    }
    $max = (int)($_POST['maxlen'] ?? 40);
    return [[
        'text' => $text, 'type' => $type, 'options' => $type === 'choice' ? implode("\n", $opts) : '',
        'maxlen' => $max >= 1 && $max <= 60 ? $max : 40,
        'required' => isset($_POST['required']) ? 1 : 0, 'active' => isset($_POST['active']) ? 1 : 0,
    ], $err];
}

function a_question_form(?array $q, string $button, string $name, string $value): string
{
    $q ??= ['text' => '', 'type' => 'text', 'options' => '', 'maxlen' => 40, 'required' => 0, 'active' => 1];
    $h = '<label>' . h(t('Question')) . '<input name="text" maxlength="70" required value="' . h((string)$q['text']) . '">' .
        '<span class="hint">' . h(t('Up to 70 characters.')) . '</span></label><div class="grid2"><label>' . h(t('Kind')) . '<select name="type">';
    foreach (a_question_types() as $k => $label) {
        $h .= '<option value="' . $k . '"' . ($k === $q['type'] ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $h . '</select></label><label>' . h(t('Longest answer')) . '<input type="number" name="maxlen" min="1" max="60" value="' . (int)$q['maxlen'] . '">' .
        '<span class="hint">' . h(t('Only for free text, 1 to 60 characters.')) . '</span></label></div>' .
        '<label>' . h(t('Answers of a choice')) . '<textarea name="options" class="mono" rows="5">' . "\n" . h((string)$q['options']) . '</textarea>' .
        '<span class="hint">' . h(t('Only for a choice: 2 to 8 answers, one per line, up to 30 characters each. The caller picks by number.')) . '</span></label>' .
        '<label class="inline"><input type="checkbox" name="required"' . ((int)$q['required'] ? ' checked' : '') . '> ' . h(t('Must be answered')) . '</label>' .
        '<label class="inline"><input type="checkbox" name="active"' . ((int)$q['active'] ? ' checked' : '') . '> ' . h(t('Active')) . '</label>' .
        '<p class="row-actions"><button class="btn" name="' . $name . '" value="' . h($value) . '">' . h($button) . '</button>' .
        ($name === 'save' ? ' <a class="btn ghost" href="' . h(a_url('questions')) . '">' . h(t('Back')) . '</a>' : '') . '</p>';
}

function page_questions(array $admin): void
{
    $all = DB::all('SELECT * FROM {nu_questions} ORDER BY sort, id');
    if (a_post()) {
        if (isset($_POST['switch'])) {
            Settings::set('nu_questions_on', isset($_POST['on']) ? '1' : '0');
            a_flash(t('Settings saved.'));
        } elseif (isset($_POST['add']) || isset($_POST['save'])) {
            [$row, $err] = a_question_input();
            $id = (int)($_POST['save'] ?? 0);
            if ($err === null && !$id && count($all) >= 10) {
                $err = t('There are 10 questions already.');
            }
            if ($err !== null) {
                $_SESSION['cb_question_draft'] = $row;
                a_flash($err . ' ' . t('The question was not saved.'), 'bad');
                a_go('questions', $id ? ['edit' => $id] : []);
            }
            if ($id) {
                DB::update('nu_questions', $row, 'id=?', [$id]);
            } else {
                DB::insert('nu_questions', $row + ['sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+10 FROM {nu_questions}')]);
            }
            a_flash(t('Question saved.'));
        } elseif (isset($_POST['del'])) {
            DB::q('DELETE FROM {nu_questions} WHERE id=?', [(int)$_POST['del']]);
            a_flash(t('Question deleted. Answers given so far stay with the users.'));
        } elseif (isset($_POST['up']) || isset($_POST['down'])) {
            $ids = array_map('intval', array_column($all, 'id'));
            $i = array_search((int)($_POST['up'] ?? $_POST['down']), $ids, true);
            $j = $i === false ? false : $i + (isset($_POST['up']) ? -1 : 1);
            if ($i !== false && isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                foreach ($ids as $n => $qid) {
                    DB::q('UPDATE {nu_questions} SET sort=? WHERE id=?', [($n + 1) * 10, $qid]);
                }
            }
        }
        a_go('questions');
    }
    $draft = $_SESSION['cb_question_draft'] ?? null;
    unset($_SESSION['cb_question_draft']);
    $on = Settings::get('nu_questions_on', '0') === '1';
    $active = count(array_filter($all, static fn($q) => (int)$q['active'] === 1));

    echo '<h1>' . h(t('Questionnaire')) . '</h1>';
    echo '<p class="note">' . h(t('Questions for new users during the registration, after location and password. You see the answers on the user page and in the list of users waiting for validation. Users that exist already are not asked.')) . '</p>';
    echo '<p class="note">' . h(t('Ask only what you really need for the validation. Only the sysop level sees the answers.')) . '</p>';
    echo '<form method="post" class="form panel">' . a_csrf() . '<label class="inline"><input type="checkbox" name="on"' . ($on ? ' checked' : '') . '> ' .
        h(t('Ask the questionnaire during the registration')) . '</label>';
    if ($active === 0) {
        echo '<p class="note">' . h(t('There is no active question, so the switch has no effect.')) . '</p>';
    }
    echo '<button class="btn small" name="switch" value="1">' . h(t('Save')) . '</button></form>';

    if ($all) {
        $types = a_question_types();
        echo '<form method="post">' . a_csrf() . '<div class="tablewrap"><table><tr><th class="num">#</th><th>' . h(t('Question')) . '</th><th>' . h(t('Kind')) .
            '</th><th>' . h(t('Must be answered')) . '</th><th>' . h(t('Active')) . '</th><th></th></tr>';
        foreach ($all as $n => $q) {
            $id = (int)$q['id'];
            $kind = $types[$q['type']] ?? $q['type'];
            if ($q['type'] === 'choice') {
                $kind .= ' (' . count(Engine::nuOptions($q)) . ')';
            } elseif ($q['type'] === 'text') {
                $kind .= ' (' . (int)$q['maxlen'] . ')';
            }
            echo '<tr><td class="num">' . ($n + 1) . '</td><td>' . h($q['text']) . '</td><td>' . h($kind) . '</td><td>' . h((int)$q['required'] ? t('yes') : t('no')) .
                '</td><td>' . ((int)$q['active'] ? h(t('yes')) : '<span class="tag">' . h(t('no')) . '</span>') . '</td><td class="row-actions">' .
                '<button class="btn small ghost" name="up" value="' . $id . '"' . ($n === 0 ? ' disabled' : '') . '>' . h(t('Up')) . '</button>' .
                '<button class="btn small ghost" name="down" value="' . $id . '"' . ($n === count($all) - 1 ? ' disabled' : '') . '>' . h(t('Down')) . '</button>' .
                '<a class="btn small ghost" href="' . h(a_url('questions', ['edit' => $id])) . '">' . h(t('Edit')) . '</a>' .
                '<button class="btn small danger ghost" name="del" value="' . $id . '" onclick="return confirm(' . h(json_encode(t('Delete this question?'))) . ')">' .
                h(t('Delete')) . '</button></td></tr>';
        }
        echo '</table></div></form>';
    }

    $edit = null;
    foreach ($all as $q) {
        if ((int)$q['id'] === (int)($_GET['edit'] ?? 0)) {
            $edit = $q;
        }
    }
    if ($edit) {
        echo '<form method="post" class="form panel">' . a_csrf() . '<h2>' . h(t('Edit question')) . '</h2>' .
            a_question_form(is_array($draft) ? $draft : $edit, t('Save question'), 'save', (string)$edit['id']) .
            '<p class="note">' . h(t('Answers given so far keep the old wording of the question.')) . '</p></form>';
    } elseif (count($all) < 10) {
        echo '<form method="post" class="form panel">' . a_csrf() . '<h2>' . h(t('New question')) . '</h2>' .
            a_question_form(is_array($draft) ? $draft : null, t('Add question'), 'add', '1') . '</form>';
    } else {
        echo '<p class="note">' . h(t('There are 10 questions already.')) . '</p>';
    }
}

/** Answers of a user from the questionnaire as a list, with a delete button per answer on the user page. */
function a_answers_html(array $rows, bool $withDelete): string
{
    $h = '<dl class="answers">';
    foreach ($rows as $a) {
        $h .= '<dt>' . h($a['question']) . '</dt><dd>' . h($a['answer']);
        if ($withDelete) {
            $h .= ' <form method="post"' . a_confirm(t('Delete this answer?')) . '>' . a_csrf() .
                '<button class="linkbtn" name="del_answer" value="' . (int)$a['id'] . '">' . h(t('Delete')) . '</button></form>';
        }
        $h .= '</dd>';
    }
    return $h . '</dl>';
}
