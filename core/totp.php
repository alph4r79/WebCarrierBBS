<?php
/**
 * WebCarrier BBS: second factor for logins. TOTP after RFC 6238 (SHA1, 6 digits, 30 seconds)
 * and one-time backup codes.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

const CB_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
/** Characters of the backup codes, without the ones that are easy to mix up (0 o, 1 l i). */
const CB_BACKUP_CHARS = 'abcdefghjkmnpqrstuvwxyz23456789';

function cb_base32_encode(string $bytes): string
{
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= CB_BASE32[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function cb_base32_decode(string $s): string
{
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s) ?? '')) as $c) {
        if ($c !== '') {
            $bits .= str_pad(decbin((int)strpos(CB_BASE32, $c)), 5, '0', STR_PAD_LEFT);
        }
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr((int)bindec($chunk));
        }
    }
    return $out;
}

/** New secret: 20 random bytes as 32 Base32 characters. */
function cb_totp_secret(): string
{
    return cb_base32_encode(random_bytes(20));
}

/** Code of a time step (unix time / 30) for a raw key. */
function cb_totp_at(string $key, int $step, int $digits = 6): string
{
    $h = hash_hmac('sha1', pack('N2', $step >> 32, $step & 0xFFFFFFFF), $key, true);
    $o = ord($h[19]) & 15;
    $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** Address for authenticator apps, shown as QR code. */
function cb_totp_uri(string $secret, string $issuer, string $account): string
{
    $issuer = str_replace(':', ' ', $issuer);
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?secret=' . $secret .
        '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * Check a code of the app against the secret of the user: the current, the previous and the next
 * time step count, each step only once (users.totp_last).
 */
function cb_totp_verify(array $u, string $code, ?int $now = null): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    $key = cb_base32_decode((string)$u['totp_secret']);
    if (strlen($code) !== 6 || $key === '') {
        return false;
    }
    $cur = intdiv($now ?? time(), 30);
    foreach ([$cur - 1, $cur, $cur + 1] as $step) {
        if ($step > (int)$u['totp_last'] && hash_equals(cb_totp_at($key, $step), $code)) {
            // only the request that moves totp_last wins, a second one with the same code finds 0 rows
            return DB::q('UPDATE {users} SET totp_last=? WHERE id=? AND totp_last<?', [$step, (int)$u['id'], $step])->rowCount() === 1;
        }
    }
    return false;
}

/** Ten new backup codes for a user, the old ones are void. Only the hashes are stored. */
function cb_totp_backup_codes(int $uid): array
{
    DB::q('DELETE FROM {totp_codes} WHERE user_id=?', [$uid]);
    $codes = [];
    $max = strlen(CB_BACKUP_CHARS) - 1;
    while (count($codes) < 10) {
        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= CB_BACKUP_CHARS[random_int(0, $max)];
        }
        if (!in_array($c, $codes, true)) {
            $codes[] = $c;
            DB::insert('totp_codes', ['user_id' => $uid, 'hash' => password_hash($c, PASSWORD_DEFAULT), 'used' => 0]);
        }
    }
    return $codes;
}

/** Use up a backup code. True if it was valid and unused. */
function cb_totp_backup_use(int $uid, string $code): bool
{
    $code = strtolower(preg_replace('/[\s\-]/', '', $code) ?? '');
    if (strlen($code) !== 8) {
        return false;
    }
    foreach (DB::all('SELECT id, hash FROM {totp_codes} WHERE user_id=? AND used=0 ORDER BY id', [$uid]) as $r) {
        if (password_verify($code, (string)$r['hash'])) {
            return DB::q('UPDATE {totp_codes} SET used=1 WHERE id=? AND used=0', [(int)$r['id']])->rowCount() === 1;
        }
    }
    return false;
}

/** Second factor of a login: 'totp' for a code of the app, 'backup' for a backup code, null if wrong. */
function cb_second_factor(array $u, string $input): ?string
{
    $input = trim($input);
    if (preg_match('/^\d{3}\s?\d{3}$/', $input)) {
        return cb_totp_verify($u, $input) ? 'totp' : null;
    }
    return cb_totp_backup_use((int)$u['id'], $input) ? 'backup' : null;
}

/** Switch the second factor of a user off and drop the secret and the backup codes. */
function cb_totp_disable(int $uid): void
{
    DB::q("UPDATE {users} SET totp_on=0, totp_secret='', totp_last=0 WHERE id=?", [$uid]);
    DB::q('DELETE FROM {totp_codes} WHERE user_id=?', [$uid]);
}

/** Users whose second factor was switched off with data/2fa_reset.txt and who have not set it up again. */
function cb_totp_reset_ids(): array
{
    return array_values(array_filter(array_map('intval', explode(',', Settings::get('totp_reset_ids')))));
}

/**
 * Emergency access: data/2fa_reset.txt with the handle as content. Called after the right password
 * of that handle in the backend. Switches the second factor off and removes the file.
 */
function cb_totp_reset_file(array $u): bool
{
    $file = CB_DATA . '/2fa_reset.txt';
    if (!is_file($file) || mb_strtolower(trim((string)file_get_contents($file, false, null, 0, 200))) !== (string)$u['handle_lc']) {
        return false;
    }
    @unlink($file);
    if ((int)$u['totp_on'] !== 1) {
        return false;
    }
    cb_totp_disable((int)$u['id']);
    Settings::set('totp_reset_ids', implode(',', array_unique(array_merge(cb_totp_reset_ids(), [(int)$u['id']]))));
    cb_log((int)$u['id'], (string)$u['handle'], 'Second factor switched off with data/2fa_reset.txt');
    return true;
}
