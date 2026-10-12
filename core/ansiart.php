<?php
/**
 * WebCarrier BBS: ANSI art as a grid of cells and the share link for WAnsiDraw.
 * The drawing travels in the part of the address after #, which browsers do not send to a server.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

const CB_ART_MAX_W = 160;
const CB_ART_MAX_H = 1000;

/** Split a file into the drawing and its SAUCE record: [data, sauce or null]. */
function cb_sauce_read(string $bytes): array
{
    $len = strlen($bytes);
    $sauce = null;
    $end = $len;
    if ($len >= 128 && substr($bytes, $len - 128, 7) === 'SAUCE00') {
        $r = substr($bytes, $len - 128);
        $text = static fn(int $o, int $n): string => rtrim(CP437::toUtf8(rtrim(substr($r, $o, $n), "\0 ")));
        $flags = ord($r[105]);
        $sauce = [
            'title' => $text(7, 35), 'author' => $text(42, 20), 'group' => $text(62, 20),
            'date' => preg_match('/^\d{8}$/', substr($r, 82, 8)) ? substr($r, 82, 8) : '',
            'datatype' => ord($r[94]), 'filetype' => ord($r[95]),
            'tinfo1' => ord($r[96]) | (ord($r[97]) << 8),
            'ice' => ($flags & 1) === 1, 'nine' => (($flags >> 1) & 3) === 2,
            'font' => substr(preg_replace('/[^\x20-\x7E]/', '', strtok(substr($r, 106, 22) . "\0", "\0") ?: '') ?? '', 0, 40),
            'comments' => [],
        ];
        $end = $len - 128;
        $n = ord($r[104]);
        $cpos = $end - 5 - 64 * $n;
        if ($n > 0 && $cpos >= 0 && substr($bytes, $cpos, 5) === 'COMNT') {
            for ($i = 0; $i < $n; $i++) {
                $line = rtrim(CP437::toUtf8(rtrim(substr($bytes, $cpos + 5 + 64 * $i, 64), "\0 ")));
                $sauce['comments'][] = mb_strcut($line, 0, 64, 'UTF-8');
            }
            $end = $cpos;
        }
    }
    $data = substr($bytes, 0, $end);
    $eof = strpos($data, "\x1A");
    return [$eof === false ? $data : substr($data, 0, $eof), $sauce];
}

/**
 * Draw a file into a grid. $kind: 'ans' (CP437 with ANSI sequences), 'pipe' (UTF-8 text with pipe
 * codes, a screen) or 'text' (plain text). Returns width, height, chars and attrs (one byte per cell,
 * index y * width + x), flags and the SAUCE texts, or null above 160 columns or 1000 lines.
 */
function cb_ansi_grid(string $bytes, string $kind = 'ans', string $title = ''): ?array
{
    $sauce = null;
    if ($kind === 'pipe') {
        $w = new AnsiWriter();
        $w->write('|07|16');
        $w->write(str_replace("\n", '|CR', rtrim(str_replace(["\r\n", "\r"], "\n", $bytes), "\n")));
        $data = $w->buf;
    } elseif ($kind === 'text') {
        $text = mb_check_encoding($bytes, 'UTF-8') ? $bytes : CP437::toUtf8($bytes);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $data = CP437::from(str_replace(["\r\n", "\r"], "\n", $text));
        $data = str_replace("\n", "\r\n", $data);
    } else {
        [$data, $sauce] = cb_sauce_read($bytes);
    }
    $width = 80;
    if ($sauce && $sauce['datatype'] === 1 && $sauce['filetype'] <= 2 && $sauce['tinfo1'] > 0) {
        $width = $sauce['tinfo1'];
    }
    if ($width > CB_ART_MAX_W) {
        return null;
    }
    $blankC = str_repeat(' ', $width);
    $blankA = str_repeat("\x07", $width);
    $ch = [];
    $at = [];
    $x = $y = $sx = $sy = 0;
    $last = -1;
    $fg = 7;
    $bg = 0;
    $bold = $blink = $rev = $bgHi = false;
    $map = [0, 4, 2, 6, 1, 5, 3, 7];
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($data[$i]);
        if ($c === 27) {
            if (($data[$i + 1] ?? '') !== '[') {
                continue;
            }
            $j = $i + 2;
            $par = '';
            while ($j < $len && ord($data[$j]) >= 0x30 && ord($data[$j]) <= 0x3F) {
                $par .= $data[$j++];
            }
            if ($j >= $len) {
                break;
            }
            $f = $data[$j];
            $i = $j;
            if (ord($f) < 0x40 || ord($f) > 0x7E) {
                continue;
            }
            $n = array_map('intval', explode(';', preg_replace('/[?=>]/', '', $par) ?? ''));
            $one = max(1, $n[0]);
            switch ($f) {
                case 'm':
                    foreach ($n as $v) {
                        if ($v === 0) {
                            $fg = 7;
                            $bg = 0;
                            $bold = $blink = $rev = $bgHi = false;
                        } elseif ($v === 1) {
                            $bold = true;
                        } elseif ($v === 22) {
                            $bold = false;
                        } elseif ($v === 5) {
                            $blink = true;
                        } elseif ($v === 25) {
                            $blink = false;
                        } elseif ($v === 7) {
                            $rev = true;
                        } elseif ($v === 27) {
                            $rev = false;
                        } elseif ($v >= 30 && $v <= 37) {
                            $fg = $map[$v - 30];
                        } elseif ($v === 39) {
                            $fg = 7;
                        } elseif ($v >= 40 && $v <= 47) {
                            $bg = $map[$v - 40];
                            $bgHi = false;
                        } elseif ($v === 49) {
                            $bg = 0;
                            $bgHi = false;
                        } elseif ($v >= 90 && $v <= 97) {
                            $fg = $map[$v - 90];
                            $bold = true;
                        } elseif ($v >= 100 && $v <= 107) {
                            $bg = $map[$v - 100];
                            $bgHi = true;
                        }
                    }
                    break;
                case 'A':
                    $y = max(0, $y - $one);
                    break;
                case 'B':
                    $y += $one;
                    break;
                case 'C':
                    $x = min($width - 1, $x + $one);
                    break;
                case 'D':
                    $x = max(0, $x - $one);
                    break;
                case 'H':
                case 'f':
                    $y = max(0, $one - 1);
                    $x = min($width - 1, max(0, max(1, $n[1] ?? 1) - 1));
                    break;
                case 's':
                    $sx = $x;
                    $sy = $y;
                    break;
                case 'u':
                    $x = $sx;
                    $y = $sy;
                    break;
                case 'J':
                    if ($n[0] === 2) {
                        $ch = [];
                        $at = [];
                        $x = $y = 0;
                        $last = -1;
                    }
                    break;
                case 'K':
                    if (isset($ch[$y])) {
                        [$from, $to] = $n[0] === 1 ? [0, $x + 1] : ($n[0] === 2 ? [0, $width] : [$x, $width]);
                        for ($k = $from; $k < $to; $k++) {
                            $ch[$y][$k] = ' ';
                            $at[$y][$k] = "\x07";
                        }
                    }
                    break;
            }
            continue;
        }
        if ($c === 13) {
            $x = 0;
        } elseif ($c === 10) {
            $y++;
        } elseif ($c === 8) {
            $x = max(0, $x - 1);
        } elseif ($c === 9) {
            $x = min($width - 1, ($x + 8) & ~7);
        } elseif ($c === 12) {
            $ch = [];
            $at = [];
            $x = $y = 0;
            $last = -1;
        } elseif ($c !== 0 && $c !== 7) {
            if ($y >= CB_ART_MAX_H) {
                return null;
            }
            $ch[$y] ??= $blankC;
            $at[$y] ??= $blankA;
            $f = $fg + ($bold ? 8 : 0);
            $b = $bg;
            if ($rev) {
                [$f, $b] = [$b, $f & 7];
            }
            $ch[$y][$x] = $data[$i];
            $at[$y][$x] = chr(((($b + ($blink || $bgHi ? 8 : 0)) & 15) << 4) | ($f & 15));
            $last = max($last, $y);
            if (++$x >= $width) {
                $x = 0;
                $y++;
            }
        }
    }
    $height = max(1, $last + 1);
    $chars = $attrs = '';
    for ($r = 0; $r < $height; $r++) {
        $chars .= $ch[$r] ?? $blankC;
        $attrs .= $at[$r] ?? $blankA;
    }
    return [
        'w' => $width, 'h' => $height, 'chars' => $chars, 'attrs' => $attrs,
        'ice' => (bool)($sauce['ice'] ?? false), 'nine' => (bool)($sauce['nine'] ?? false), 'font' => (string)($sauce['font'] ?? ''),
        'title' => $sauce ? $sauce['title'] : $title, 'author' => (string)($sauce['author'] ?? ''), 'group' => (string)($sauce['group'] ?? ''),
        'date' => (string)($sauce['date'] ?? ''), 'comments' => $sauce['comments'] ?? [],
    ];
}

/** A grid in the byte format of the WAnsiDraw share link (version 1). */
function cb_wansidraw_bytes(array $g): string
{
    $str = static fn(string $s, int $max = 255): string => chr(strlen($s = mb_strcut($s, 0, $max, 'UTF-8'))) . $s;
    $out = "\x01" . chr(($g['ice'] ? 1 : 0) | ($g['nine'] ? 2 : 0)) . pack('vv', $g['w'], $g['h']) . $str((string)$g['font'], 40);
    $out .= $str((string)$g['title']) . $str((string)$g['author']) . $str((string)$g['group']) . $str((string)$g['date'], 8);
    $comments = array_slice((array)$g['comments'], 0, 255);
    $out .= chr(count($comments));
    foreach ($comments as $c) {
        $out .= $str((string)$c, 64);
    }
    return $out . $g['chars'] . $g['attrs'];
}

/** Data part of the link: "z" + deflate or "r" + raw, base64url without padding. Without $form the shorter one. */
function cb_wansidraw_pack(string $bytes, string $form = ''): string
{
    $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $r = 'r' . $b64($bytes);
    $z = function_exists('gzdeflate') ? 'z' . $b64((string)gzdeflate($bytes, 9)) : $r;
    if ($form !== '') {
        return $form === 'z' ? $z : $r;
    }
    return strlen($r) < strlen($z) ? $r : $z;
}

/** Address of the editor from the settings, '' if the function is off. English boards get the English editor of the default address. */
function cb_wansidraw_base(): string
{
    $url = trim(Settings::get('wansidraw_url', CB_WANSIDRAW_URL));
    if (!preg_match('~^https?://[^\s"\'<>#]+$~i', $url)) {
        return '';
    }
    return $url === CB_WANSIDRAW_URL && Lang::$code === 'en' ? $url . 'en/' : $url;
}

/**
 * Link that opens a file in WAnsiDraw, or null if the function is off, the file cannot be read or the
 * drawing is too big. $kind as in cb_ansi_grid(), by default from the extension and the content.
 * $name is the title without SAUCE (default: the file name without extension).
 */
function cb_wansidraw_link(string $file, string $kind = '', ?string $name = null): ?string
{
    $base = cb_wansidraw_base();
    if ($base === '' || !is_file($file) || (int)filesize($file) > 1048576) {
        return null;
    }
    $bytes = (string)file_get_contents($file);
    if ($kind === '') {
        $kind = strtolower(pathinfo($name ?? $file, PATHINFO_EXTENSION)) === 'ans' || str_contains($bytes, "\e[") ? 'ans' : 'text';
    }
    $g = cb_ansi_grid($bytes, $kind, pathinfo($name ?? $file, PATHINFO_FILENAME));
    return $g === null ? null : $base . '#art=' . cb_wansidraw_pack(cb_wansidraw_bytes($g));
}
