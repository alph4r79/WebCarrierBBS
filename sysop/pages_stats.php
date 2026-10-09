<?php
/**
 * Sysop backend: statistics. Dates are grouped in PHP (time zone of the board), so the same
 * code runs on SQLite and MySQL. Uploads and downloads come from the log.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Counts per bucket: $times grouped with date($fmt), every key of $buckets present (0 if empty). */
function a_count_by(array $times, string $fmt, array $buckets): array
{
    $out = array_fill_keys($buckets, 0);
    foreach ($times as $t) {
        $k = date($fmt, (int)$t);
        if (isset($out[$k])) {
            $out[$k]++;
        }
    }
    return $out;
}

/** Bars for label => number, or a sentence if everything is 0. */
function a_bars(array $rows, string $empty): string
{
    $max = $rows ? max($rows) : 0;
    if ($max <= 0) {
        return '<p class="note">' . h($empty) . '</p>';
    }
    $h = '<table class="bars">';
    foreach ($rows as $label => $n) {
        $h .= '<tr><th>' . h((string)$label) . '</th><td><span class="bar" style="width:' . round($n / $max * 100, 1) . '%"></span></td><td class="num">' . (int)$n . '</td></tr>';
    }
    return $h . '</table>';
}

/** Top list as table: [label, number]. */
function a_toplist(array $rows, string $col, string $empty): string
{
    if (!$rows) {
        return '<p class="note">' . h($empty) . '</p>';
    }
    $h = '<div class="tablewrap"><table><tr><th class="num">#</th><th>' . h($col) . '</th><th class="num">' . h(t('Number')) . '</th></tr>';
    foreach (array_values($rows) as $i => [$label, $n]) {
        $h .= '<tr><td class="num">' . ($i + 1) . '</td><td>' . h((string)$label) . '</td><td class="num">' . (int)$n . '</td></tr>';
    }
    return $h . '</table></div>';
}

function page_stats(array $admin): void
{
    $days = [];
    for ($i = 29; $i >= 0; $i--) {
        $days[] = date('Y-m-d', strtotime("today -$i days"));
    }
    $months = [];
    $first = new DateTimeImmutable('first day of this month 00:00');
    for ($i = 11; $i >= 0; $i--) {
        $months[] = $first->modify("-$i months")->format('Y-m');
    }
    $dayStart = strtotime($days[0]);
    $monthStart = $first->modify('-11 months')->getTimestamp();
    $label = static fn(string $ym) => date('m/Y', (int)strtotime($ym . '-01'));

    // same source as "Calls today" on the overview: the table calls
    $calls = a_count_by(array_column(DB::all('SELECT time FROM {calls} WHERE time>=?', [$dayStart]), 'time'), 'Y-m-d', $days);
    $callRows = [];
    foreach ($calls as $d => $n) {
        $callRows[date('d.m.', (int)strtotime($d))] = $n;
    }
    $users = a_count_by(array_column(DB::all('SELECT created FROM {users} WHERE created>=?', [$monthStart]), 'created'), 'Y-m', $months);
    // written by the engine as "Upload <name>" and "Download <name>"
    $log = DB::all("SELECT time, text FROM {log} WHERE time>=? AND (text LIKE 'Upload %' OR text LIKE 'Download %')", [$monthStart]);
    $ups = a_count_by(array_column(array_filter($log, static fn($r) => str_starts_with($r['text'], 'Upload ')), 'time'), 'Y-m', $months);
    $downs = a_count_by(array_column(array_filter($log, static fn($r) => str_starts_with($r['text'], 'Download ')), 'time'), 'Y-m', $months);

    $sys = Settings::int('sysop_level', 255);
    $files = array_map(static fn($r) => [$r['filename'], $r['downloads']],
        DB::all('SELECT filename, downloads FROM {files} WHERE downloads>0 ORDER BY downloads DESC, filename LIMIT 10'));
    $writers = array_map(static fn($r) => [$r['handle'], $r['posts']],
        DB::all('SELECT handle, posts FROM {users} WHERE posts>0 AND level<? AND pending=0 ORDER BY posts DESC, handle_lc LIMIT 10', [$sys]));
    $callers = array_map(static fn($r) => [$r['handle'], $r['calls']],
        DB::all('SELECT handle, calls FROM {users} WHERE calls>0 AND level<? AND pending=0 ORDER BY calls DESC, handle_lc LIMIT 10', [$sys]));

    $m = static fn(array $rows) => array_combine(array_map($label, array_keys($rows)), array_values($rows));
    echo '<h1>' . h(t('Statistics')) . '</h1><div class="stats-grid">';
    echo '<section class="panel"><h2>' . h(t('Calls per day, last 30 days')) . '</h2>' . a_bars($callRows, t('No calls in the last 30 days.')) . '</section>';
    echo '<section class="panel"><h2>' . h(t('New users per month')) . '</h2>' . a_bars($m($users), t('No new users in the last 12 months.')) . '</section>';
    echo '<section class="panel"><h2>' . h(t('Uploads per month')) . '</h2>' . a_bars($m($ups), t('No uploads in the last 12 months.')) . '</section>';
    echo '<section class="panel"><h2>' . h(t('Downloads per month')) . '</h2>' . a_bars($m($downs), t('No downloads in the last 12 months.')) . '</section>';
    echo '<section class="panel"><h2>' . h(t('Most downloaded files')) . '</h2>' . a_toplist($files, t('File'), t('No file has been downloaded yet.')) . '</section>';
    echo '<section class="panel"><h2>' . h(t('Most active writers')) . '</h2>' . a_toplist($writers, t('User'), t('Nobody has written a message yet.')) . '</section>';
    echo '<section class="panel"><h2>' . h(t('Most frequent callers')) . '</h2>' . a_toplist($callers, t('User'), t('Nobody has called yet.')) . '</section>';
    echo '</div><p class="note">' . h(t('Uploads and downloads are counted from the log. Clearing the log also clears these numbers. Sysops and users waiting for validation are not part of the top lists.')) . '</p>';
}
