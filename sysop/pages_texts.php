<?php
/**
 * Sysop backend: own texts for the terminal. Only texts that differ from the language
 * file are stored, as JSON in the setting lang_override_<language>.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

function a_lang_overrides(string $lang): array
{
    $o = json_decode(Settings::get('lang_override_' . $lang), true);
    return is_array($o) ? array_filter($o, 'is_string') : [];
}

/** Warnings for an own text: missing placeholders of the default, lines over 79 characters. */
function a_text_warnings(string $key, string $default, string $text): array
{
    $w = [];
    preg_match_all('/\{\d+\}/', $default, $m);
    $missing = array_values(array_filter(array_unique($m[0]), static fn($p) => !str_contains($text, $p)));
    if ($missing) {
        $w[] = t('{1}: placeholder {2} is missing.', $key, implode(', ', $missing));
    }
    foreach (preg_split('/\|CR|\n/', $text) ?: [] as $line) {
        if (mb_strlen(preg_replace('/\|\d\d|\|CL/', '', $line) ?? '') > 79) {
            $w[] = t('{1}: a line is longer than 79 characters and wraps in the terminal.', $key);
            break;
        }
    }
    return $w;
}

function page_texts(array $admin): void
{
    $langs = a_languages();
    $lang = (string)($_GET['lang'] ?? Settings::get('language', 'en'));
    if (!isset($langs[$lang])) {
        $lang = array_key_first($langs) ?? 'en';
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $only = ($_GET['only'] ?? '') === 'changed';
    $back = array_filter(['lang' => $lang, 'q' => $q, 'only' => $only ? 'changed' : '']);
    $defaults = require CB_ROOT . '/lang/' . $lang . '.php';
    $over = a_lang_overrides($lang);

    if (a_post()) {
        if (isset($_POST['reset'])) {
            $k = (string)$_POST['reset'];
            unset($over[$k]);
            cb_log((int)$admin['id'], $admin['handle'], 'Reset text ' . $k . ' (' . $lang . ')');
            a_flash(t('Text {1} reset to the default.', $k));
        } elseif (isset($_POST['remove_unused'])) {
            $k = (string)$_POST['remove_unused'];
            unset($over[$k]);
            cb_log((int)$admin['id'], $admin['handle'], 'Removed unused text ' . $k . ' (' . $lang . ')');
            a_flash(t('Text {1} removed.', $k));
        } else {
            $changed = 0;
            foreach ((array)($_POST['text'] ?? []) as $k => $v) {
                $k = (string)$k;
                if (!isset($defaults[$k]) || !is_string($v)) {
                    continue;
                }
                $v = str_replace(["\r\n", "\r", "\n"], '|CR', preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
                $old = $over[$k] ?? $defaults[$k];
                if ($v === '' || $v === $defaults[$k]) {
                    unset($over[$k]);
                } else {
                    $over[$k] = mb_substr($v, 0, 2000);
                    foreach (a_text_warnings($k, $defaults[$k], $over[$k]) as $w) {
                        a_flash($w, 'warn');
                    }
                }
                if (($over[$k] ?? $defaults[$k]) !== $old) {
                    $changed++;
                }
            }
            if ($changed) {
                cb_log((int)$admin['id'], $admin['handle'], 'Changed ' . $changed . ' text(s) (' . $lang . ')');
            }
            a_flash(t('{1} text(s) changed.', $changed));
        }
        ksort($over);
        Settings::set('lang_override_' . $lang, $over ? (string)json_encode($over, JSON_UNESCAPED_UNICODE) : '');
        a_go('texts', $back);
    }

    echo '<h1>' . h(t('Texts')) . '</h1>';
    echo '<p class="note">' . h(t('Own versions of the texts callers see in the terminal. They are stored in the database and survive updates. Pipe codes for colours as in screens, {1}, {2} ... are filled in by the board.')) . '</p>';
    echo '<form method="get" class="form row-actions filterbar"><input type="hidden" name="p" value="texts"><select name="lang" aria-label="' . h(t('Language')) . '">';
    foreach ($langs as $c => $n) {
        echo '<option value="' . h($c) . '"' . ($c === $lang ? ' selected' : '') . '>' . h($n) . '</option>';
    }
    echo '</select><input name="q" value="' . h($q) . '" placeholder="' . h(t('Search key or text')) . '">' .
        '<label class="inline"><input type="checkbox" name="only" value="changed"' . ($only ? ' checked' : '') . '> ' . h(t('only changed')) . '</label>' .
        '<button class="btn" type="submit">' . h(t('Show')) . '</button></form>';

    $unused = array_diff_key($over, $defaults);
    $keys = [];
    foreach ($defaults as $k => $def) {
        $cur = $over[$k] ?? $def;
        if ($only && !isset($over[$k])) {
            continue;
        }
        if ($q !== '' && !str_contains(mb_strtolower($k . ' ' . $def . ' ' . $cur), mb_strtolower($q))) {
            continue;
        }
        $keys[] = $k;
    }
    echo '<p class="note">' . h(t('{1} of {2} texts shown, {3} changed.', count($keys), count($defaults), count(array_intersect_key($over, $defaults)))) . '</p>';
    if ($keys) {
        echo '<form method="post" action="' . h(a_url('texts', $back)) . '" class="form">' . a_csrf() . '<div class="tablewrap"><table class="texts"><tr><th>' .
            h(t('Key name')) . '</th><th>' . h(t('Text')) . '</th></tr>';
        foreach ($keys as $k) {
            $def = $defaults[$k];
            $cur = $over[$k] ?? $def;
            $w = new AnsiWriter();
            $w->write('|07|16' . $cur);
            echo '<tr' . (isset($over[$k]) ? ' class="changed"' : '') . '><td><code>' . h($k) . '</code>' .
                (isset($over[$k]) ? '<br><span class="note">' . h(t('changed')) . '</span>' : '') . '</td><td>' .
                '<div class="note">' . h(t('Default:')) . ' <code>' . h($def) . '</code></div>' .
                // the newline after <textarea> is dropped by the browser, so a leading |CR survives
                '<textarea name="text[' . h($k) . ']" class="mono" rows="2" aria-label="' . h($k) . '">' . "\n" . h(str_replace('|CR', "\n", $cur)) . '</textarea>' .
                '<div class="lp" data-b="' . h(base64_encode($w->buf)) . '"></div>' .
                (isset($over[$k]) ? '<button class="btn small ghost" name="reset" value="' . h($k) . '">' . h(t('Reset to default')) . '</button>' : '') .
                '</td></tr>';
        }
        echo '</table></div><p><button class="btn" type="submit">' . h(t('Save texts')) . '</button></p></form>';
    } else {
        echo '<div class="panel"><p class="note">' . h(t('No texts match.')) . '</p></div>';
    }

    if ($unused) {
        echo '<h2>' . h(t('Own texts no longer in use')) . '</h2><p class="note">' .
            h(t('The language file no longer has these keys, so the texts are ignored.')) . '</p><form method="post" action="' . h(a_url('texts', $back)) . '">' .
            a_csrf() . '<div class="tablewrap"><table><tr><th>' . h(t('Key name')) . '</th><th>' . h(t('Text')) . '</th><th></th></tr>';
        foreach ($unused as $k => $v) {
            echo '<tr><td><code>' . h((string)$k) . '</code></td><td><code>' . h($v) . '</code></td><td><button class="btn small danger ghost" name="remove_unused" value="' .
                h((string)$k) . '">' . h(t('Remove')) . '</button></td></tr>';
        }
        echo '</table></div></form>';
    }

    // one terminal renders all previews, each one is copied into its own canvas, cut to the used columns
    echo '<script src="../assets/font.js?v=' . CB_VERSION . '"></script><script src="../assets/terminal.js?v=' . CB_VERSION . '"></script>';
    echo '<script>(function(){var src=document.createElement("canvas"),t=new CarrierTerm(src);t.setBaud(0);var cw=src.width/80;' .
        'document.querySelectorAll(".lp").forEach(function(el){t.reset();t.write(atob(el.dataset.b));t.render();' .
        'var rows=Math.max(1,t.y+(t.x>0?1:0)),cols=1;for(var i=0;i<rows*80;i++){if(t.ch[i]!==32||(t.at[i]&0x70)){cols=Math.max(cols,i%80+1);}}' .
        'var c=document.createElement("canvas"),w=cols*cw,h=rows*16;c.width=w;c.height=h;' .
        'c.getContext("2d").drawImage(src,0,0,w,h,0,0,w,h);el.appendChild(c);});}());</script>';
}
