<?php
// Path: public_html/payments/save.php
/**
 * Payments — provider configuration save.
 *
 * WHO MAY SAVE HERE
 * -----------------------------------------------------------------------------
 * Every setting this handler saves is portal-wide: it is written with nothing
 * in the siteID column, so it is the value EVERY organisation on this
 * installation uses. That includes the Stripe secret key, the Stripe webhook
 * secret, the PayPal client and webhook, and the switch that turns card
 * payments on.
 *
 * Until 11 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of any one
 * organisation could point every organisation's card payments at a Stripe or
 * PayPal account of their own choosing. The owner decided that settings
 * affecting every organisation are for a global administrator only. Anybody
 * else is now refused, is told why on screen, and nothing is saved.
 *
 * @package   Portal\Payments
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
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

// 🚧 Portal-wide settings are for a global administrator only — see "WHO MAY
//    SAVE HERE" in the file header. The refusal gives the reason rather than a
//    bare "forbidden": an administrator who presses Save and is told nothing
//    assumes the portal is broken and tries again. It comes after the
//    form-token check, so a forged request from another website cannot fill
//    the activity log with refusals.
//
//    What this cannot do: the page that shows the form
//    (web/_apps/payments/index.php) was not changed, so an administrator of
//    one organisation still sees a Save button there. This refusal is what
//    actually enforces the rule.
if (App::isRootAdmin() === false) {
    Logger::activity(
        'SettingsGroupSaveRefused',
        'Refused: portal-wide settings group "payments" may only be changed by a global administrator',
        $_SESSION['user_id'] ?? null
    );
    $_SESSION['flash_msg']  = 'These settings are portal-wide: they apply to every '
        . 'organisation on this installation, not only yours. Only a global '
        . 'administrator can change them. Your own organisation\'s settings '
        . 'are on the main Settings page and are unaffected.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /payments');
    exit();
}

$db = App::db();

$upsert = static function (mysqli $db, string $key, string $value, bool $isSensitive): void {
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

$provider = (string) ($_POST['provider'] ?? 'stripe');
if (in_array($provider, ['stripe','paypal','gocardless'], true) === false) {
    $provider = 'stripe';
}
$currency = (string) ($_POST['currency'] ?? 'GBP');
if (in_array($currency, ['GBP','EUR','USD'], true) === false) {
    $currency = 'GBP';
}

$upsert($db, 'payments.enabled',    isset($_POST['enabled']) === true ? '1' : '0', false);
$upsert($db, 'payments.test_mode',  isset($_POST['test_mode']) === true ? '1' : '0', false);
$upsert($db, 'payments.provider',   $provider, false);
$upsert($db, 'payments.currency',   $currency, false);

$upsert($db, 'payments.stripe.publishable', trim((string) ($_POST['stripe_pub'] ?? '')), false);
$stSec = trim((string) ($_POST['stripe_secret'] ?? ''));
if ($stSec !== '') {
    $upsert($db, 'payments.stripe.secret', $stSec, true);
}
$stWh = trim((string) ($_POST['stripe_wh'] ?? ''));
if ($stWh !== '') {
    $upsert($db, 'payments.stripe.webhookSecret', $stWh, true);
}

$ppMode = (string) ($_POST['pp_mode'] ?? 'sandbox');
if (in_array($ppMode, ['sandbox','live'], true) === false) {
    $ppMode = 'sandbox';
}
$upsert($db, 'payments.paypal.mode', $ppMode, false);

// Clearing the webhook ID is a legitimate admin action (disables PayPal
// webhook verification until it's set again) — write unconditionally.
$upsert($db, 'payments.paypal.webhookId', trim((string) ($_POST['pp_webhook_id'] ?? '')), false);

// 🔐 Sensitive keep-if-blank pattern (mirrors pp_secret below) — PayPal
// treats client ids as public identifiers, but the gap-item directive
// calls for encrypting it at rest as defence in depth (migration 167
// flips isSensitive 0→1 for any site that hasn't already saved one).
$ppClient = trim((string) ($_POST['pp_client'] ?? ''));
if ($ppClient !== '') {
    $upsert($db, 'payments.paypal.clientId', $ppClient, true);
}
$ppSec = trim((string) ($_POST['pp_secret'] ?? ''));
if ($ppSec !== '') {
    $upsert($db, 'payments.paypal.secret', $ppSec, true);
}

$gcTok = trim((string) ($_POST['gc_token'] ?? ''));
if ($gcTok !== '') {
    $upsert($db, 'payments.gocardless.token', $gcTok, true);
}

$_SESSION['flash_msg']  = 'Payment settings saved.';
$_SESSION['flash_type'] = 'success';
header('Location: /payments');
exit();
