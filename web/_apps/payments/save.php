<?php
// Path: public_html/payments/save.php
/**
 * Payments — provider configuration save.
 *
 * @package   Portal\Payments
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
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

$upsert($db, 'payments.paypal.clientId', trim((string) ($_POST['pp_client'] ?? '')), false);
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
