<?php
// Path: public_html/admin/sms/save.php
/**
 * Admin — SMS settings save (provider + keys + cap).
 *
 * WHO MAY SAVE HERE
 * -----------------------------------------------------------------------------
 * Every setting this handler saves is portal-wide: it is written with nothing
 * in the siteID column, so it is the value EVERY organisation on this
 * installation uses. That includes which text-message provider is used, its
 * account keys, the number messages are sent from, and the daily limit.
 *
 * Until 11 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of any one
 * organisation could send every organisation's text messages through an account
 * of their choosing, or switch text messages off for everybody. The owner
 * decided that settings affecting every organisation are for a global
 * administrator only. Anybody else is now refused, is told why on screen, and
 * nothing is saved.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/272
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
//    (web/_apps/admin/sms/index.php) was not changed, so an administrator of
//    one organisation still sees a Save button there. This refusal is what
//    actually enforces the rule.
if (App::isRootAdmin() === false) {
    Logger::activity(
        'SettingsGroupSaveRefused',
        'Refused: portal-wide settings group "sms" may only be changed by a global administrator',
        $_SESSION['user_id'] ?? null
    );
    $_SESSION['flash_msg']  = 'These settings are portal-wide: they apply to every '
        . 'organisation on this installation, not only yours. Only a global '
        . 'administrator can change them. Your own organisation\'s settings '
        . 'are on the main Settings page and are unaffected.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/sms');
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

$provider  = (string) ($_POST['provider'] ?? 'twilio');
if (in_array($provider, ['twilio','messagebird','aws'], true) === false) {
    $provider = 'twilio';
}
$enabled   = isset($_POST['enabled']) === true ? '1' : '0';
$fromNum   = trim((string) ($_POST['fromNumber'] ?? ''));
$dailyCap  = max(1, (int) ($_POST['dailyCap'] ?? 100));

$upsert($db, 'sms.enabled',    $enabled,             false);
$upsert($db, 'sms.provider',   $provider,            false);
$upsert($db, 'sms.fromNumber', $fromNum,             false);
$upsert($db, 'sms.dailyCap',   (string) $dailyCap,   false);

$twSid = trim((string) ($_POST['twilio_sid'] ?? ''));
$upsert($db, 'sms.twilio.sid', $twSid, false);
$twTok = trim((string) ($_POST['twilio_token'] ?? ''));
if ($twTok !== '') {
    $upsert($db, 'sms.twilio.token', $twTok, true);
}

$mbKey = trim((string) ($_POST['mb_key'] ?? ''));
if ($mbKey !== '') {
    $upsert($db, 'sms.messagebird.apiKey', $mbKey, true);
}

$awsK  = trim((string) ($_POST['aws_key'] ?? ''));
$awsS  = trim((string) ($_POST['aws_secret'] ?? ''));
$awsR  = trim((string) ($_POST['aws_region'] ?? 'eu-west-1'));
$upsert($db, 'sms.aws.accessKey', $awsK, false);
$upsert($db, 'sms.aws.region',    $awsR, false);
if ($awsS !== '') {
    $upsert($db, 'sms.aws.secret', $awsS, true);
}

$_SESSION['flash_msg']  = 'SMS settings saved.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/sms');
exit();
