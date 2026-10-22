<?php
/**
 * WebCarrier BBS: check of uploads from the terminal (file signature, damaged ZIP archives,
 * doubles by checksum), checksums of stored files and the BBS ad that goes into uploaded ZIPs.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Defaults for the ZIP check, changeable in the settings (upload_zip_entries, upload_zip_mb). */
const CB_ZIP_MAX_ENTRIES = 2000;
const CB_ZIP_MAX_MB = 256;

/** File name of the BBS ad: 8.3, characters valid in archives on DOS, Windows and Linux, no path. */
const CB_AD_NAME_RE = "/^[A-Za-z0-9_\\-!#$%&'()@^{}~]{1,8}(\\.[A-Za-z0-9_\\-!#$%&'()@^{}~]{1,3})?$/";

/** Limits of the ZIP check: [entries, uncompressed bytes]. */
function cb_zip_limits(): array
{
    $entries = max(1, Settings::int('upload_zip_entries', CB_ZIP_MAX_ENTRIES));
    $mb = max(1, Settings::int('upload_zip_mb', CB_ZIP_MAX_MB));
    return [$entries, $mb * 1048576];
}

/** Archive format by the name: zip, rar, 7z, arj, lzh or gz (also .tar.gz and .tgz), null for anything else. */
function cb_archive_kind(string $name): ?string
{
    $n = strtolower($name);
    if (str_ends_with($n, '.gz') || str_ends_with($n, '.tgz')) {
        return 'gz';
    }
    return match (pathinfo($n, PATHINFO_EXTENSION)) {
        'zip' => 'zip',
        'rar' => 'rar',
        '7z' => '7z',
        'arj' => 'arj',
        'lha', 'lzh' => 'lzh',
        default => null,
    };
}

/** True if the start of the file matches the archive format of its name. Other files always pass. */
function cb_signature_ok(string $path, string $name): bool
{
    $kind = cb_archive_kind($name);
    if ($kind === null) {
        return true;
    }
    $head = (string)@file_get_contents($path, false, null, 0, 16);
    return match ($kind) {
        'zip' => str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06") || str_starts_with($head, "PK\x07\x08"),
        'rar' => str_starts_with($head, "Rar!\x1a\x07"),
        '7z' => str_starts_with($head, "7z\xbc\xaf\x27\x1c"),
        'arj' => str_starts_with($head, "\x60\xea"),
        'lzh' => (bool)preg_match('/^..-l(h[0-9a-z]|z[s45])-/s', $head),
        'gz' => str_starts_with($head, "\x1f\x8b"),
    };
}

/**
 * Check a ZIP archive: open with consistency check, the limits for entries and unpacked size, then
 * every entry is read and its CRC compared. Null if fine, otherwise 'damaged' or 'limit'.
 * Encrypted entries cannot be read without the password, their CRC is not checked.
 */
function cb_zip_check(string $path): ?string
{
    [$maxEntries, $maxBytes] = cb_zip_limits();
    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::CHECKCONS) !== true) {
        return 'damaged';
    }
    try {
        if ($z->numFiles > $maxEntries) {
            return 'limit';
        }
        $stats = [];
        $total = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            if ($st === false) {
                return 'damaged';
            }
            $total += max(0, (int)$st['size']);
            if ($total > $maxBytes) {
                return 'limit';
            }
            $stats[$i] = $st;
        }
        // the sizes in the archive may lie, so the bytes really read count as well
        $read = 0;
        foreach ($stats as $i => $st) {
            if (!empty($st['encryption_method']) || (str_ends_with((string)$st['name'], '/') && (int)$st['size'] === 0)) {
                continue;
            }
            $fp = method_exists($z, 'getStreamIndex') ? @$z->getStreamIndex($i) : @$z->getStream((string)$st['name']);
            if (!$fp) {
                return 'damaged';
            }
            $crc = hash_init('crc32b');
            $len = 0;
            while (!feof($fp)) {
                $chunk = @fread($fp, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $len += strlen($chunk);
                $read += strlen($chunk);
                if ($read > $maxBytes) {
                    fclose($fp);
                    return 'limit';
                }
                hash_update($crc, $chunk);
            }
            fclose($fp);
            if ($len !== (int)$st['size'] || hash_final($crc) !== sprintf('%08x', (int)$st['crc'] & 0xFFFFFFFF)) {
                return 'damaged';
            }
        }
        return null;
    } finally {
        $z->close();
    }
}

/**
 * Check of an upload from the terminal. Null if it may stay, otherwise the reason: 'type' (content does
 * not match the extension), 'damaged' or 'limit' (ZIP only). Without ZipArchive ZIPs are not opened.
 */
function cb_upload_check(string $path, string $name): ?string
{
    if (!cb_signature_ok($path, $name)) {
        return 'type';
    }
    if (cb_archive_kind($name) === 'zip' && class_exists('ZipArchive')) {
        @set_time_limit(120);
        return cb_zip_check($path);
    }
    return null;
}

/** An existing file with this checksum (as stored or before the BBS ad), approved ones first, or null. */
function cb_file_dupe(string $sha256): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
        return null;
    }
    return DB::row('SELECT f.id, f.filename, f.approved, f.area_id, a.name AS area_name, a.dl_level FROM {files} f
        LEFT JOIN {file_areas} a ON a.id=f.area_id WHERE f.sha256=? OR f.sha256_orig=? ORDER BY f.approved DESC, f.id LIMIT 1', [$sha256, $sha256]);
}

/**
 * Message for a double: [language key, arguments, text for the log]. Waiting files and files in areas the
 * caller may not see are not named.
 */
function cb_dupe_message(array $dupe, int $level): array
{
    if ((int)$dupe['approved'] !== 1) {
        return ['fup_rej_wait', [], 'double of a waiting upload'];
    }
    $log = 'double of ' . $dupe['filename'] . ' in ' . ($dupe['area_name'] ?? '?');
    if ($dupe['area_name'] === null || (int)$dupe['dl_level'] > $level) {
        return ['fup_rej_dupe_x', [], $log];
    }
    return ['fup_rej_dupe', [mb_substr((string)$dupe['filename'], 0, 40), mb_substr((string)$dupe['area_name'], 0, 30)], $log];
}

/** Number of files without checksum. */
function cb_hash_left(): int
{
    return (int)DB::val("SELECT COUNT(*) FROM {files} WHERE sha256=''");
}

/**
 * Checksums for files stored before version 1.11.0: at most $max files, stops after $seconds.
 * A file missing on the disk gets the mark '-', so it does not come back with every step.
 * Returns the number of files done.
 */
function cb_hash_pending(int $max = 50, float $seconds = 2.0): int
{
    $start = microtime(true);
    $done = 0;
    foreach (DB::all("SELECT id, storage FROM {files} WHERE sha256='' ORDER BY id LIMIT " . max(1, $max)) as $f) {
        $p = CB_DATA . '/files/' . $f['storage'];
        $h = (string)$f['storage'] !== '' && is_file($p) ? @hash_file('sha256', $p) : false;
        $h = $h === false ? '-' : $h;
        DB::q('UPDATE {files} SET sha256=?, sha256_orig=? WHERE id=?', [$h, $h, (int)$f['id']]);
        $done++;
        if (microtime(true) - $start > $seconds) {
            break;
        }
    }
    return $done;
}

/* ------------------------------------------------------------------ BBS ad */

/** File name of the ad from the name of the board: A-Z and 0-9, at most 8 characters, .TXT. */
function cb_ad_default_name(?string $bbs = null): string
{
    $s = strtr($bbs ?? Settings::get('bbs_name', ''), ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ä' => 'AE', 'ö' => 'OE', 'ü' => 'UE', 'ß' => 'SS']);
    $s = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? ''), 0, 8);
    return $s === '' ? 'BBS-AD.TXT' : $s . '.TXT';
}

/** Valid file name for the ad: 8.3, no device name of DOS, never FILE_ID.DIZ. */
function cb_ad_name_ok(string $name): bool
{
    if (!preg_match(CB_AD_NAME_RE, $name)) {
        return false;
    }
    $base = strtoupper(explode('.', $name)[0]);
    if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9]|CLOCK\$)$/', $base)) {
        return false;
    }
    return !in_array(strtoupper($name), ['FILE_ID.DIZ', 'DESC.SDI'], true);
}

function cb_ad_name(): string
{
    $n = Settings::get('bbs_ad_name');
    return cb_ad_name_ok($n) ? $n : cb_ad_default_name();
}

/** Default text of the ad in the language of the board, a framed box of 64 columns. */
function cb_ad_default_text(?string $lang = null): string
{
    $de = ($lang ?? Settings::get('language', 'en')) === 'de';
    $row = static fn(string $s): string => '║' . cb_pad($s, 62) . '║';
    $lines = ['╔' . str_repeat('═', 62) . '╗', $row('')];
    if ($de) {
        $lines[] = $row('   Diese Datei wurde hochgeladen auf');
        $lines[] = $row('');
        $lines[] = '║   @BBSNAME:56L@   ║';
        $lines[] = $row('');
        $lines[] = '║   Sysop .....: @SYSOP:43L@   ║';
        $lines[] = '║   Adresse ...: @BBSURL:43L@   ║';
        $lines[] = '║   Hochgeladen: @DATE:43L@   ║';
        $lines[] = $row('');
        $lines[] = $row('   Ruf an, die Leitung ist rund um die Uhr offen!');
    } else {
        $lines[] = $row('   This file was uploaded to');
        $lines[] = $row('');
        $lines[] = '║   @BBSNAME:56L@   ║';
        $lines[] = $row('');
        $lines[] = '║   Sysop .....: @SYSOP:43L@   ║';
        $lines[] = '║   Online ....: @BBSURL:43L@   ║';
        $lines[] = '║   Uploaded ..: @DATE:43L@   ║';
        $lines[] = $row('');
        $lines[] = $row('   Call today, the line is open 24 hours a day!');
    }
    $lines[] = $row('');
    $lines[] = '╚' . str_repeat('═', 62) . '╝';
    return implode("\n", $lines);
}

function cb_ad_template(): string
{
    $t = Settings::get('bbs_ad_text');
    return trim($t) !== '' ? $t : cb_ad_default_text();
}

/** Macros of the ad. */
function cb_ad_macro(string $n, string $uploader, int $time): ?string
{
    return match ($n) {
        'BBSNAME' => Settings::get('bbs_name', 'WebCarrier BBS'),
        'BBSLOC' => Settings::get('bbs_location', ''),
        'SYSOP' => Settings::get('sysop_name', 'Sysop'),
        'BBSURL' => cb_bbs_url(),
        'DATE' => date(Lang::get('fmt_date'), $time),
        'TIME' => date('H:i', $time),
        'USER' => $uploader,
        default => null,
    };
}

/** Text of the ad with the macros filled in (UTF-8, lines separated by \n). */
function cb_ad_text(string $tpl, string $uploader, int $time): string
{
    $tpl = str_replace(["\r\n", "\r"], "\n", $tpl);
    return cb_macros($tpl, static fn($n) => cb_ad_macro($n, $uploader, $time));
}

/** Ad as file content: CP437 with CRLF, ends with a line break. */
function cb_ad_bytes(string $text): string
{
    $lines = explode("\n", rtrim(str_replace(["\r\n", "\r"], "\n", $text), "\n"));
    return CP437::from(implode("\r\n", array_map('rtrim', $lines)) . "\r\n");
}

/**
 * Put the ad into the top folder of a ZIP archive. An entry with the same name (any case) is replaced,
 * all other entries stay as they are. Null on success, otherwise the reason. The archive is unchanged
 * when it fails.
 */
function cb_ad_insert(string $path, string $uploader, ?int $time = null): ?string
{
    if (!class_exists('ZipArchive')) {
        return 'ZipArchive is missing';
    }
    $name = cb_ad_name();
    $data = cb_ad_bytes(cb_ad_text(cb_ad_template(), $uploader, $time ?? time()));
    $z = new ZipArchive();
    $r = $z->open($path);
    if ($r !== true) {
        return 'cannot open the archive (' . $r . ')';
    }
    for ($i = $z->numFiles - 1; $i >= 0; $i--) {
        $n = (string)$z->getNameIndex($i);
        if ($n !== $name && strcasecmp($n, $name) === 0 && !$z->deleteIndex($i)) {
            $z->unchangeAll();
            $z->close();
            return 'cannot remove ' . $n;
        }
    }
    if (!$z->addFromString($name, $data)) {
        $z->unchangeAll();
        $z->close();
        return 'cannot add ' . $name;
    }
    if (!@$z->close()) {
        return 'cannot write the archive';
    }
    return null;
}
