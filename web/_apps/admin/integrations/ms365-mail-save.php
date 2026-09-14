<?php
// Path: _apps/admin/integrations/ms365-mail-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — MS365 shared-mailbox mail settings save handler 💾 (#234)
 * -----------------------------------------------------------------------------
 * Per-key upsert (mirrors admin/integrations/cloudflare-stream/save.php):
 * none of the four values here are sensitive, so there is no
 * encrypt-on-save / blank-preserves-existing branch — a submitted blank
 * genuinely clears the setting (empty `sharedMailbox` is the documented
 * "feature off" state, #234 Q4 — one fewer toggle, impossible to
 * half-configure toggle-on-but-empty-mailbox).
 *
 * SECURITY: this is the ONLY place `mail.ms365.sharedMailbox` is ever
 * written. Mailer::effectiveSender() reads it exclusively from
 * tblSettings — never from a request — so the shared-mailbox identity is
 * admin-config-only by construction; there is no code path that lets a
 * caller pick an arbitrary mailbox to send as.
 *
 * WHO MAY SAVE HERE
 * -----------------------------------------------------------------------------
 * Every setting this handler saves is portal-wide: it is written with nothing
 * in the siteID column, so it is the value EVERY organisation on this
 * installation uses. That includes the shared mailbox every organisation's
 * email is sent from, and the service to fall back to if sending fails.
 *
 * Until 11 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of any one
 * organisation could make every organisation's email go out from a mailbox of
 * their choosing. The owner decided that settings affecting every organisation
 * are for a global administrator only. Anybody else is now refused, is told why
 * on screen, and nothing is saved.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/234
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$redirect = '/admin/integrations';

/**
 * 🚩 Flash an error and redirect back WITHOUT saving anything.
 */
$reject = static function (string $message) use ($redirect): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect, true, 302);
    exit();
};

// 🚧 Portal-wide settings are for a global administrator only — see "WHO MAY
//    SAVE HERE" in the file header. The refusal gives the reason rather than a
//    bare "forbidden": an administrator who presses Save and is told nothing
//    assumes the portal is broken and tries again. It comes after the
//    form-token check, so a forged request from another website cannot fill
//    the activity log with refusals. It also comes before the checks on what
//    was typed in: there is no point telling somebody their input is wrong
//    when they may not save it at all. $reject (above) shows the reason and
//    saves nothing.
//
//    What this cannot do: the page that shows the form
//    (web/_apps/admin/integrations/index.php) was not changed, so an
//    administrator of one organisation still sees a Save button there. This
//    refusal is what actually enforces the rule.
if (App::isRootAdmin() === false) {
    Logger::activity(
        'SettingsGroupSaveRefused',
        'Refused: portal-wide settings group "ms365-mail" may only be changed by a global administrator',
        $_SESSION['user_id'] ?? null
    );
    $reject(
        'These settings are portal-wide: they apply to every '
            . 'organisation on this installation, not only yours. Only a global '
            . 'administrator can change them. Your own organisation\'s settings '
            . 'are on the main Settings page and are unaffected.'
    );
}

$sharedMailbox     = trim((string) ($_POST['sharedMailbox'] ?? ''));
$sharedMailboxName = trim((string) ($_POST['sharedMailboxName'] ?? ''));
$saveToSentItems   = isset($_POST['saveToSentItems']) === true ? 'true' : 'false';
$fallbackProvider  = strtolower(trim((string) ($_POST['fallbackProvider'] ?? '')));

// 🛡️ sharedMailbox — empty is valid (means "off", the legacy direct-send
//    path stays exactly as it was). A NON-empty value MUST be a real
//    email address — this is the only write path for it, so a bad value
//    here is the only way a malformed mailbox could ever reach Mailer.
if ($sharedMailbox !== '' && filter_var($sharedMailbox, FILTER_VALIDATE_EMAIL) === false) {
    $reject('Shared mailbox must be a valid email address.');
}

// 🛡️ fallbackProvider — closed vocabulary. Only 'google' is a real
//    provider fallback in this codebase; anything else is rejected
//    outright rather than silently coerced.
if ($fallbackProvider !== '' && $fallbackProvider !== 'google') {
    $reject('Fallback provider must be "google" or left blank.');
}

$db = App::db();

// Per-key upsert — none of these four keys is sensitive.
$upsert = static function (mysqli $db, string $key, string $value): void {
    $stmt = $db->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
    if ($stmt === false) {
        return;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($id);
    $exists = $stmt->fetch() === true;
    $stmt->close();
    if ($exists === true) {
        $u = $db->prepare('UPDATE tblSettings SET settingValue = ?, updatedAt = NOW() WHERE settingID = ?');
        if ($u !== false) {
            $u->bind_param('si', $value, $id);
            $u->execute();
            $u->close();
        }
    } else {
        $u = $db->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) VALUES (?, ?, 0, NULL, NOW())');
        if ($u !== false) {
            $u->bind_param('ss', $key, $value);
            $u->execute();
            $u->close();
        }
    }
};

$upsert($db, 'mail.ms365.sharedMailbox', $sharedMailbox);
$upsert($db, 'mail.ms365.sharedMailboxName', $sharedMailboxName);
$upsert($db, 'mail.ms365.saveToSentItems', $saveToSentItems);
$upsert($db, 'mail.fallbackProvider', $fallbackProvider);

// 🔎 Never log the shared-mailbox VALUE choice here beyond a generic
//    activity note — it's a public address, not a secret, but the
//    activity log isn't the place to echo settings payloads either.
Logger::activity('Ms365MailSettingsSaved', 'MS365 shared-mailbox mail settings updated');

$_SESSION['flash_msg']  = 'MS365 mail settings saved.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . $redirect, true, 302);
exit();
