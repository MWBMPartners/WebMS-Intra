<?php
// Path: _apps/admin/integrations/push/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Web Push settings save handler 🔔💾 (#322)
 * -----------------------------------------------------------------------------
 * Three actions, one CSRF'd POST endpoint (action-dispatched, admin/
 * livestream/index.php precedent):
 *
 *   generate — WebPush::generateKeys() mints a fresh VAPID P-256 pair.
 *              WARNING (flashed to the admin): any subscriber whose browser
 *              already holds a PushSubscription bound to the OLD public key
 *              will silently stop receiving pushes until they revisit the
 *              portal and re-subscribe — a real push service validates that
 *              the sender's VAPID public key matches the one the browser
 *              was given at subscribe time. Regenerating is a "start over"
 *              action, not a rotation.
 *   import   — Paste EITHER a full PEM (`openssl ecparam -genkey` output)
 *              OR the bare base64url 32-byte scalar `d` some tools export
 *              standalone. WebPush::importPrivateKey() validates + derives
 *              the matching public key; the ORIGINAL pasted text is what
 *              gets encrypted at rest (WebPush's own resolvePrivateKey()
 *              accepts either shape again at send time).
 *   save     — General settings: contact (mailto:/https:), master enable,
 *              TTLs, auto-notify toggles, SSRF host allowlist.
 *
 * Private key handling: encrypted via `encrypt_setting()` (sodium
 * secretbox, bootstrap.php), NEVER re-displayed once saved (index.php shows
 * only a "configured" badge + a public-key fingerprint), NEVER sent to the
 * client, NEVER logged. Only the derived PUBLIC key is ever stored in the
 * clear (`push.vapidPublicKey`, isSensitive=0 — by design, RFC 8292 §3.2
 * publishes it in every `Authorization: vapid k=…` header anyway).
 *
 * WHO MAY SAVE HERE
 * -----------------------------------------------------------------------------
 * Every setting this handler saves is portal-wide: it is written with nothing
 * in the siteID column, so it is the value EVERY organisation on this
 * installation uses. That includes the key pair browser notifications are
 * signed with, and the list of notification services the server is allowed to
 * contact.
 *
 * Until 11 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of any one
 * organisation could replace the key pair, which silently stops notifications
 * for every subscriber in every organisation, or widen the list of services the
 * server will contact. The owner decided that settings affecting every
 * organisation are for a global administrator only. Anybody else is now
 * refused, is told why on screen, and nothing is saved.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\WebPush;

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

$redirect = '/admin/integrations/push';

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
//    (web/_apps/admin/integrations/push/index.php) was not changed, so an
//    administrator of one organisation still sees a Save button there. This
//    refusal is what actually enforces the rule.
if (App::isRootAdmin() === false) {
    Logger::activity(
        'SettingsGroupSaveRefused',
        'Refused: portal-wide settings group "push" may only be changed by a global administrator',
        $_SESSION['user_id'] ?? null
    );
    $reject(
        'These settings are portal-wide: they apply to every '
            . 'organisation on this installation, not only yours. Only a global '
            . 'administrator can change them. Your own organisation\'s settings '
            . 'are on the main Settings page and are unaffected.'
    );
}

/**
 * 🚩 Flash a success message and redirect back.
 */
$succeed = static function (string $message) use ($redirect): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . $redirect, true, 302);
    exit();
};

$db = App::db();

/** Per-key upsert; sensitive values encrypted (cloudflare-stream/save.php precedent). */
$upsert = static function (\mysqli $db, string $key, string $value, bool $isSensitive): void {
    if ($isSensitive === true && $value !== '' && function_exists('encrypt_setting') === true) {
        $value = encrypt_setting($value);
    }
    $sens = $isSensitive === true ? 1 : 0;
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
        $u = $db->prepare('UPDATE tblSettings SET settingValue = ?, isSensitive = ?, updatedAt = NOW() WHERE settingID = ?');
        if ($u !== false) {
            $u->bind_param('sii', $value, $sens, $id);
            $u->execute();
            $u->close();
        }
    } else {
        $u = $db->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) VALUES (?, ?, ?, NULL, NOW())');
        if ($u !== false) {
            $u->bind_param('ssi', $key, $value, $sens);
            $u->execute();
            $u->close();
        }
    }
};

$action = (string) ($_POST['action'] ?? 'save');

// =============================================================================
// 🎲 Generate a fresh VAPID key pair.
// =============================================================================
if ($action === 'generate') {
    try {
        $keys = WebPush::generateKeys();
    } catch (\Throwable $e) {
        Logger::errorPlatform('WebPush', 'Error', 'PUSH_GENERATE_FAIL', $e->getMessage(), '');
        $reject('Key generation failed: ' . $e->getMessage());
    }
    $upsert($db, 'push.vapidPublicKey', $keys['publicKey'], false);
    $upsert($db, 'push.vapidPrivateKey', $keys['privateKeyPem'], true);
    Logger::activity('WebPushKeysGenerated', 'New VAPID key pair generated');
    $succeed('New VAPID key pair generated. Existing subscribers (if any) will need to re-subscribe — their old subscription was bound to the previous public key.');
}

// =============================================================================
// 📋 Import a pasted private key (PEM or bare base64url scalar).
// =============================================================================
if ($action === 'import') {
    $pasted = trim((string) ($_POST['privateKeyPaste'] ?? ''));
    if ($pasted === '') {
        $reject('Paste a private key (PEM or the bare base64url scalar) first.');
    }
    $publicKey = WebPush::importPrivateKey($pasted);
    if ($publicKey === null) {
        $reject('Could not parse that as a P-256 private key. Expected a PEM (openssl ecparam output) or a bare base64url 32-byte scalar.');
    }
    $upsert($db, 'push.vapidPublicKey', $publicKey, false);
    $upsert($db, 'push.vapidPrivateKey', $pasted, true);
    Logger::activity('WebPushKeysImported', 'VAPID key pair imported from pasted value');
    $succeed('VAPID key pair imported. Existing subscribers (if any) will need to re-subscribe — their old subscription was bound to the previous public key.');
}

// =============================================================================
// ⚙️ General settings.
// =============================================================================
$enabled          = isset($_POST['enabled']) === true ? 'true' : 'false';
$contact          = trim((string) ($_POST['contact'] ?? ''));
$ttlGolive        = (int) ($_POST['ttlGolive'] ?? 900);
$ttlReminder      = (int) ($_POST['ttlReminder'] ?? 3600);
$autoGolive       = isset($_POST['golive_auto']) === true ? 'true' : 'false';
$broadcast        = isset($_POST['reminders_broadcast']) === true ? 'true' : 'false';
$allowlistRaw     = trim((string) ($_POST['endpointHostAllowlist'] ?? ''));

// 🛡️ RFC 8292 §2.1 — sub MUST be a mailto: or https: contact URI.
if ($contact !== '' && preg_match('/^(mailto:|https:)\S+$/i', $contact) !== 1) {
    $reject('Contact must start with "mailto:" or "https:" (RFC 8292 requires it).');
}

// 🛡️ SSRF allowlist — comma-separated bare hostnames only, capped at 20.
$allowlist = '';
if ($allowlistRaw !== '') {
    $parts = array_filter(array_map('trim', explode(',', $allowlistRaw)));
    $parts = array_slice(array_values($parts), 0, 20);
    foreach ($parts as $part) {
        if (preg_match('/^[a-z0-9.-]+$/i', $part) !== 1) {
            $reject('Allowed push-service hosts must be bare hostnames (no scheme, no path).');
        }
    }
    $allowlist = implode(',', array_map('strtolower', $parts));
}

if ($ttlGolive < 60 || $ttlGolive > 86400) {
    $ttlGolive = 900;
}
if ($ttlReminder < 60 || $ttlReminder > 86400) {
    $ttlReminder = 3600;
}

$upsert($db, 'push.enabled',                $enabled,               false);
$upsert($db, 'push.contact',                $contact,               false);
$upsert($db, 'push.ttl.golive',             (string) $ttlGolive,    false);
$upsert($db, 'push.ttl.reminder',           (string) $ttlReminder,  false);
$upsert($db, 'push.golive.auto',            $autoGolive,            false);
$upsert($db, 'push.reminders.broadcast',    $broadcast,             false);
if ($allowlist !== '') {
    $upsert($db, 'push.endpointHostAllowlist', $allowlist, false);
}

Logger::activity('WebPushSettingsSaved', 'Web Push settings updated');
$succeed('Web Push settings saved.');
