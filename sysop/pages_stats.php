<?php
/**
 * Sysop backend: statistics. Counting is done in SQL in 15 minute buckets, PHP turns the buckets
 * into days, hours and months in the time zone of the board (also right for half hour zones).
 * Uploads and downloads come from the log. Sysops and users waiting for validation are left out
 * of calls and active users.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

const A_BUCKET = 900;

/** Calls without sysops and users waiting for validation (calls of deleted users count). */
function a_calls_from(): string
{
    return '{calls} c LEFT JOIN {users} u ON u.id=c.user_id WHERE (u.id IS NULL OR (u.level<? AND u.pending=0))';
}

/** Buckets of a query: bucket start (timestamp) => count. $sql selects b and n. */
function a_buckets(string $sql, array $params): array
{
    $out = [];
    foreach (DB::all($sql, $params) as $r) {
        $out[(int)$r['b'] * A_BUCKET] = (int)$r['n'];
    }
    return $out;
}

/** Sum the buckets by date($fmt) into the given keys. */
function a_group(array $buckets, string $fmt, array $keys): array
{
    $out = array_fill_keys($keys, 0);
    foreach ($buckets as $t => $n) {
        $k = date($fmt, $t);
        if (isset($out[$k])) {
            $out[$k] += $n;
        }
    }
    return $out;
}

/** Comparison with the period before as text, no colours. */
function a_cmp(int $now, int $before, string $what): string
{
    $d = $now - $before;
    if ($d === 0) {
        return t('same as {1}', $what);
    }
    return t('{1} compared to {2}', ($d > 0 ? '+' : '') . $d, $what);
}

/**
 * Column chart with a fixed height. $series: [[name, css class, values]], $labels: axis texts (''
 * for none), $titles: text per column for the title attribute and the hidden table.
 */
function a_chart(string $title, array $series, array $labels, array $titles, string $empty, string $extra = ''): string
{
    $max = 0;
    $sums = [];
    foreach ($series as [, , $vals]) {
        $max = max($max, $vals ? max($vals) : 0);
        $sums[] = array_sum($vals);
    }
    $head = $max > 0 ? t('Highest {1}, total {2}', $max, implode(' / ', $sums)) : '';
    $h = '<section class="panel chart-box"><div class="chart-head"><h2>' . h($title) . '</h2>' . $extra .
        ($head !== '' ? '<span class="note">' . h($head) . '</span>' : '') . '</div>';
    if (count($series) > 1) {
        $h .= '<p class="legend">';
        foreach ($series as [$name, $cls]) {
            $h .= '<span><i class="sw ' . $cls . '"></i>' . h($name) . '</span>';
        }
        $h .= '</p>';
    }
    $h .= '<div class="chart" aria-hidden="true"><div class="plot">';
    foreach ($titles as $i => $tt) {
        $h .= '<div class="col">';
        foreach ($series as [$name, $cls, $vals]) {
            $v = (int)$vals[$i];
            if ($v > 0) {
                $h .= '<span class="b ' . $cls . '" style="height:' . round($v / $max * 100, 1) . '%" title="' . h($tt . ($name !== '' ? ', ' . $name : '') . ': ' . $v) . '"></span>';
            }
        }
        $h .= '</div>';
    }
    $h .= '</div>' . ($max === 0 ? '<p class="chart-empty">' . h($empty) . '</p>' : '') . '<div class="axis">';
    foreach ($labels as $l) {
        $h .= '<span>' . h($l) . '</span>';
    }
    $h .= '</div></div><table class="sr-only"><caption>' . h($title) . '</caption><tr><th>' . h(t('Period')) . '</th>';
    foreach ($series as [$name]) {
        $h .= '<th>' . h($name !== '' ? $name : t('Number')) . '</th>';
    }
    $h .= '</tr>';
    foreach ($titles as $i => $tt) {
        $h .= '<tr><td>' . h($tt) . '</td>';
        foreach ($series as [, , $vals]) {
            $h .= '<td>' . (int)$vals[$i] . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</table></section>';
}

/** Top list as a table with a fixed head; an empty list keeps the head and shows a line. */
function a_toplist(string $title, array $rows, string $col, string $empty): string
{
    $h = '<section class="panel top-box"><h2>' . h($title) . '</h2><div class="tablewrap"><table><thead><tr><th class="num">#</th><th>' . h($col) .
        '</th><th class="num">' . h(t('Number')) . '</th></tr></thead><tbody>';
    if (!$rows) {
        $h .= '<tr><td colspan="3" class="note">' . h($empty) . '</td></tr>';
    }
    foreach (array_values($rows) as $i => [$label, $n]) {
        $h .= '<tr><td class="num">' . ($i + 1) . '</td><td>' . h((string)$label) . '</td><td class="num">' . (int)$n . '</td></tr>';
    }
    return $h . '</tbody></table></div></section>';
}

function page_stats(array $admin): void
{
    $range = (int)($_GET['r'] ?? 30);
    $range = in_array($range, [7, 30, 90], true) ? $range : 30;
    $sys = Settings::int('sysop_level', 255);
    $today = (int)strtotime('today');
    $day = static fn(int $n) => (int)strtotime("today -$n days");
    $calls = static fn(int $from, int $to) => (int)DB::val('SELECT COUNT(*) FROM ' . a_calls_from() . ' AND c.time>=? AND c.time<?', [$sys, $from, $to]);
    $log = static fn(string $what, int $from, int $to) => (int)DB::val("SELECT COUNT(*) FROM {log} WHERE time>=? AND time<? AND text LIKE ?", [$from, $to, $what . ' %']);
    $end = time() + 1;

    // the band: today, 7 and 30 days, each with the period before
    $c7 = [$calls($day(6), $end), $calls($day(13), $day(6))];
    $c30 = [$calls($day(29), $end), $calls($day(59), $day(29))];
    $act = [];
    foreach ([[$day(29), $end], [$day(59), $day(29)]] as [$f, $t]) {
        $act[] = (int)DB::val('SELECT COUNT(DISTINCT c.user_id) FROM ' . a_calls_from() . ' AND c.time>=? AND c.time<?', [$sys, $f, $t]);
    }
    $nu = [];
    $msgs = [];
    $nuWaiting = (int)DB::val('SELECT COUNT(*) FROM {users} WHERE created>=? AND pending=1', [$day(29)]);
    foreach ([[$day(29), $end], [$day(59), $day(29)]] as [$f, $t]) {
        $nu[] = (int)DB::val('SELECT COUNT(*) FROM {users} WHERE created>=? AND created<?', [$f, $t]);
        $msgs[] = (int)DB::val('SELECT COUNT(*) FROM {messages} WHERE private=0 AND posted>=? AND posted<?', [$f, $t]);
    }
    $ud = [$log('Upload', $day(29), $end), $log('Download', $day(29), $end)];
    $udBefore = [$log('Upload', $day(59), $day(29)), $log('Download', $day(59), $day(29))];
    $band = [
        [t('Calls today'), (string)$calls($today, $end), a_cmp($calls($today, $end), $calls($day(1), $today), t('yesterday'))],
        [t('Calls, 7 days'), (string)$c7[0], a_cmp($c7[0], $c7[1], t('the 7 days before'))],
        [t('Calls, 30 days'), (string)$c30[0], a_cmp($c30[0], $c30[1], t('the 30 days before'))],
        [t('Active users, 30 days'), (string)$act[0], a_cmp($act[0], $act[1], t('the 30 days before'))],
        [t('New users, 30 days'), (string)$nu[0], a_cmp($nu[0], $nu[1], t('the 30 days before')), $nuWaiting > 0 ? t('of which {1} waiting', $nuWaiting) : ''],
        [t('Public messages, 30 days'), (string)$msgs[0], a_cmp($msgs[0], $msgs[1], t('the 30 days before'))],
        [t('Uploads / downloads, 30 days'), $ud[0] . ' / ' . $ud[1], t('before: {1}', $udBefore[0] . ' / ' . $udBefore[1])],
    ];
    // only calls with a known end, older versions did not store it
    $avg = DB::val('SELECT AVG(c.ended - c.time) FROM ' . a_calls_from() . ' AND c.time>=? AND c.ended>0',
        [Settings::int('sysop_level', 255), $day(29)]);
    if ($avg !== null) {
        $band[] = [t('Average length of a call, 30 days'), a_duration((int)round((float)$avg)), t('calls with a known end')];
    }

    echo '<h1>' . h(t('Statistics')) . '</h1><dl class="kpis">';
    foreach ($band as $b) {
        [$label, $value, $cmp] = $b;
        $extra = (string)($b[3] ?? '');
        echo '<div><dt>' . h($label) . '</dt><dd>' . h($value) . '</dd>' . ($extra !== '' ? '<dd class="cmp">' . h($extra) . '</dd>' : '') .
            '<dd class="cmp">' . h($cmp) . '</dd></div>';
    }
    echo '</dl>';

    // calls per day and per hour over the chosen range
    $from = $day($range - 1);
    $b = a_buckets('SELECT ' . DB::bucket('c.time', A_BUCKET) . ' AS b, COUNT(*) AS n FROM ' . a_calls_from() . ' AND c.time>=? GROUP BY b', [$sys, $from]);
    $days = [];
    for ($i = $range - 1; $i >= 0; $i--) {
        $days[] = date('Y-m-d', $day($i));
    }
    $perDay = array_values(a_group($b, 'Y-m-d', $days));
    $step = $range === 7 ? 1 : ($range === 30 ? 7 : 14);
    $dayLabels = [];
    foreach ($days as $i => $d) {
        // every n-th day and the last one; an n-th day right before the last one stays empty
        $rest = count($days) - 1 - $i;
        $dayLabels[] = $step === 1 || $rest === 0 || ($i % $step === 0 && $rest >= 3) ? date('d.m.', (int)strtotime($d)) : '';
    }
    $switch = '<nav class="ranges" aria-label="' . h(t('Period')) . '">';
    foreach ([7, 30, 90] as $r) {
        $switch .= '<a href="' . h(a_url('stats', ['r' => $r])) . '"' . ($r === $range ? ' aria-current="page"' : '') . '>' . h(t('{1} days', $r)) . '</a>';
    }
    $switch .= '</nav>';
    $hours = array_fill(0, 24, 0);
    foreach ($b as $t => $n) {
        $hours[(int)date('G', $t)] += $n;
    }

    // new users, uploads and downloads per month
    $first = new DateTimeImmutable('first day of this month 00:00');
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $months[] = $first->modify("-$i months")->format('Y-m');
    }
    $mStart = $first->modify('-11 months')->getTimestamp();
    $users = array_values(a_group(a_buckets('SELECT ' . DB::bucket('created', A_BUCKET) . ' AS b, COUNT(*) AS n FROM {users} WHERE created>=? GROUP BY b', [$mStart]), 'Y-m', $months));
    $ups = array_values(a_group(a_buckets('SELECT ' . DB::bucket('time', A_BUCKET) . " AS b, COUNT(*) AS n FROM {log} WHERE time>=? AND text LIKE 'Upload %' GROUP BY b", [$mStart]), 'Y-m', $months));
    $downs = array_values(a_group(a_buckets('SELECT ' . DB::bucket('time', A_BUCKET) . " AS b, COUNT(*) AS n FROM {log} WHERE time>=? AND text LIKE 'Download %' GROUP BY b", [$mStart]), 'Y-m', $months));
    $mNames = [t('Jan'), t('Feb'), t('Mar'), t('Apr'), t('May'), t('Jun'), t('Jul'), t('Aug'), t('Sep'), t('Oct'), t('Nov'), t('Dec')];
    $mLabels = array_map(static fn($m) => $mNames[(int)substr($m, 5, 2) - 1], $months);
    $mTitles = array_map(static fn($m) => $mNames[(int)substr($m, 5, 2) - 1] . ' ' . substr($m, 0, 4), $months);

    echo '<div class="charts">';
    echo '<div class="wide">' . a_chart(t('Calls per day'), [['', 's1', $perDay]], $dayLabels, array_map(static fn($d) => date('d.m.Y', (int)strtotime($d)), $days),
        t('There were no calls in this period.'), $switch) . '</div>';
    echo a_chart(t('Calls by time of day, {1} days', $range), [['', 's1', $hours]], array_map(static fn($i) => $i % 3 === 0 ? (string)$i : '', range(0, 23)),
        array_map(static fn($i) => t('{1} o\'clock', $i), range(0, 23)), t('There were no calls in this period.'));
    echo a_chart(t('New users per month'), [['', 's1', $users]], $mLabels, $mTitles, t('There were no new users in this period.'));
    echo a_chart(t('Uploads and downloads per month'), [[t('Uploads'), 's1', $ups], [t('Downloads'), 's2', $downs]], $mLabels, $mTitles,
        t('There were no uploads or downloads in this period.'));
    echo '</div>';

    $files = array_map(static fn($r) => [$r['filename'], $r['downloads']],
        DB::all('SELECT filename, downloads FROM {files} WHERE downloads>0 ORDER BY downloads DESC, filename LIMIT 10'));
    $writers = array_map(static fn($r) => [$r['handle'], $r['posts']],
        DB::all('SELECT handle, posts FROM {users} WHERE posts>0 AND level<? AND pending=0 ORDER BY posts DESC, handle_lc LIMIT 10', [$sys]));
    $callers = array_map(static fn($r) => [$r['handle'], $r['calls']],
        DB::all('SELECT handle, calls FROM {users} WHERE calls>0 AND level<? AND pending=0 ORDER BY calls DESC, handle_lc LIMIT 10', [$sys]));
    echo '<div class="tops">' . a_toplist(t('Most downloaded files'), $files, t('File'), t('No file has been downloaded yet.')) .
        a_toplist(t('Most active writers'), $writers, t('User'), t('Nobody has written a message yet.')) .
        a_toplist(t('Most frequent callers'), $callers, t('User'), t('Nobody has called yet.')) . '</div>';
    echo '<p class="note">' . h(t('Uploads and downloads are counted from the log. Clearing the log also clears these numbers. Sysops and users waiting for validation are not part of the calls, the active users and the top lists.')) . '</p>';
}
