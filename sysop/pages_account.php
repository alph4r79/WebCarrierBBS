<?php
/**
 * Sysop backend: the own account (second factor) and the redirect to WAnsiDraw.
 *
 * Copyright (C) 2026 Christoph Scheel <https://chrisscheel.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

function page_account(array $admin): void
{
    $id = (int)$admin['id'];
    $on = (int)$admin['totp_on'] === 1;
    $secret = (string)$admin['totp_secret'];
    if (a_post()) {
        $pwOk = password_verify((string)($_POST['pass'] ?? ''), (string)$admin['pass']);
        if (isset($_POST['start']) && !$on) {
            DB::q('UPDATE {users} SET totp_secret=?, totp_last=0 WHERE id=?', [cb_totp_secret(), $id]);
        } elseif (isset($_POST['cancel']) && !$on) {
            DB::q("UPDATE {users} SET totp_secret='' WHERE id=?", [$id]);
        } elseif (isset($_POST['confirm']) && !$on && $secret !== '') {
            if (cb_totp_verify($admin, (string)($_POST['code'] ?? ''))) {
                DB::q('UPDATE {users} SET totp_on=1 WHERE id=?', [$id]);
                $_SESSION['cb_totp_codes'] = cb_totp_backup_codes($id);
                Settings::set('totp_reset_ids', implode(',', array_diff(cb_totp_reset_ids(), [$id])));
                cb_log($id, $admin['handle'], 'Second factor set up');
                a_flash(t('The second factor is active. From now on every login asks for a code from the app.'));
            } else {
                a_flash(t('The code is not correct. Check the time of your phone and try the current code.'), 'bad');
            }
        } elseif (isset($_POST['newcodes']) && $on) {
            if ($pwOk) {
                $_SESSION['cb_totp_codes'] = cb_totp_backup_codes($id);
                cb_log($id, $admin['handle'], 'New backup codes created');
                a_flash(t('New backup codes created, the old ones are void.'));
            } else {
                sleep(1);
                a_flash(t('The password is not correct.'), 'bad');
            }
        } elseif (isset($_POST['disable']) && $on) {
            if ($pwOk && cb_second_factor($admin, (string)($_POST['code'] ?? '')) !== null) {
                cb_totp_disable($id);
                cb_log($id, $admin['handle'], 'Second factor switched off');
                a_flash(t('The second factor is switched off.'));
            } else {
                sleep(1);
                a_flash(t('Password or code is not correct, the second factor stays on.'), 'bad');
            }
        }
        a_go('account');
    }

    echo '<h1>' . h(t('My account')) . '</h1>';
    $codes = $_SESSION['cb_totp_codes'] ?? null;
    unset($_SESSION['cb_totp_codes']);
    if (is_array($codes)) {
        echo '<section class="panel"><h2>' . h(t('Backup codes')) . '</h2><p>' .
            h(t('Each code works once instead of a code from the app, for example when your phone is gone. Write them down or print them.')) .
            '</p><ul class="codes">';
        foreach ($codes as $c) {
            echo '<li><code>' . h((string)$c) . '</code></li>';
        }
        echo '</ul><p><strong>' . h(t('These codes are only shown now.')) . '</strong></p></section>';
    }

    echo '<section class="panel"><h2>' . h(t('Second factor')) . '</h2>';
    if ($on) {
        $left = (int)DB::val('SELECT COUNT(*) FROM {totp_codes} WHERE user_id=? AND used=0', [$id]);
        echo '<p><span class="tag ok">' . h(t('active')) . '</span> ' .
            h(t('Logins of {1} in the backend and in the terminal ask for a code from the app. Unused backup codes: {2}.', $admin['handle'], $left)) . '</p></section>';
        echo '<form method="post" class="form panel" autocomplete="off"' . a_confirm(t('Create new backup codes? The old ones stop working.')) . '>' . a_csrf() .
            '<h2>' . h(t('New backup codes')) . '</h2><label>' . h(t('Password')) .
            '<input type="password" name="pass" required autocomplete="current-password"></label>' .
            '<button class="btn small" name="newcodes" value="1">' . h(t('Create new backup codes')) . '</button></form>';
        echo '<form method="post" class="form panel" autocomplete="off">' . a_csrf() . '<h2>' . h(t('Switch off')) . '</h2><div class="grid2"><label>' .
            h(t('Password')) . '<input type="password" name="pass" required autocomplete="current-password"></label><label>' . h(t('Code from the app')) .
            '<input name="code" class="mono" required inputmode="numeric" autocomplete="one-time-code" maxlength="12"><span class="hint">' .
            h(t('A backup code works too.')) . '</span></label></div><button class="btn small danger" name="disable" value="1">' .
            h(t('Switch off the second factor')) . '</button></form>';
        return;
    }
    if ($secret === '') {
        echo '<p>' . h(t('With a second factor a login needs your password and a code that an authenticator app on your phone shows. The code changes every 30 seconds. Any common app works, the board needs no service on the net for it.')) .
            '</p><form method="post">' . a_csrf() . '<button class="btn" name="start" value="1">' . h(t('Set up the second factor')) . '</button></form></section>';
        return;
    }
    $uri = cb_totp_uri($secret, Settings::get('bbs_name', 'WebCarrier BBS'), (string)$admin['handle']);
    echo '<ol class="steps"><li>' . h(t('Scan the QR code with your authenticator app, or type the secret into the app.')) .
        '<div id="qr" class="qr" data-uri="' . h($uri) . '"></div><p class="secret"><code>' . h(implode(' ', str_split($secret, 4))) . '</code></p>' .
        '<p class="note"><a href="' . h($uri) . '">' . h(t('Open in an authenticator app on this device')) . '</a></p></li>' .
        '<li>' . h(t('Type the code the app shows. Only then the second factor is active.')) .
        '<form method="post" class="form" autocomplete="off">' . a_csrf() . '<label>' . h(t('Code from the app')) .
        '<input name="code" class="mono" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus></label>' .
        '<p class="row-actions"><button class="btn" name="confirm" value="1">' . h(t('Activate')) . '</button>' .
        '<button class="btn ghost" name="cancel" value="1" formnovalidate>' . h(t('Cancel')) . '</button></p></form></li></ol>' .
        '<p class="note">' . h(t('The secret stays on this board, the QR code is drawn in your browser.')) . '</p></section>';
    echo '<script src="../assets/qrcode.js?v=' . CB_VERSION . '"></script><script>(function(){var e=document.getElementById("qr"),q=qrcode(0,"M");' .
        'q.addData(e.getAttribute("data-uri"));q.make();e.innerHTML=q.createSvgTag({cellSize:4,margin:4,scalable:true});}());</script>';
}

/** Build the share link of a screen (?screen=name.ext) or a file (?file=id) and send the browser there. */
function page_wansidraw(array $admin): never
{
    $url = null;
    $back = ['screens', []];
    if (isset($_GET['screen'])) {
        $f = basename((string)$_GET['screen']);
        $list = a_screen_list();
        if (isset($list[$f])) {
            $url = cb_wansidraw_link($list[$f], str_ends_with($f, '.txt') ? 'pipe' : 'ans');
        }
    } elseif (isset($_GET['file'])) {
        $row = DB::row('SELECT * FROM {files} WHERE id=?', [(int)$_GET['file']]);
        if ($row) {
            $back = ['files', ['area' => (int)$row['area_id']]];
            $url = $row['storage'] !== '' ? cb_wansidraw_link(CB_DATA . '/files/' . $row['storage'], '', (string)$row['filename']) : null;
        }
    }
    if ($url === null) {
        a_flash(cb_wansidraw_base() === '' ? t('WAnsiDraw is switched off in the settings.') : t('Too big for WAnsiDraw.'), 'bad');
        a_go($back[0], $back[1]);
    }
    if (strlen($url) <= 3000) {
        header('Location: ' . $url);
        exit;
    }
    // a long address does not fit into a header on every server, the page itself moves on
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo '<!DOCTYPE html><html lang="' . h(Lang::$code) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>WAnsiDraw</title></head>' .
        '<body><p><a id="go" rel="noopener" href="' . h($url) . '">' . h(t('Open in WAnsiDraw')) . '</a></p>' .
        '<script>location.replace(document.getElementById("go").href);</script></body></html>';
    exit;
}
