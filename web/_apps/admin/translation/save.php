<?php
// Path: public_html/admin/translation/save.php
/**
 * Admin — Translation settings save handler.
 *
 * WHO MAY SAVE HERE
 * -----------------------------------------------------------------------------
 * Every setting this handler saves is portal-wide: it is written with nothing
 * in the siteID column, so it is the value EVERY organisation on this
 * installation uses. That includes the translation provider, its account keys,
 * the address of a self-hosted translation server, and the monthly spending
 * limit.
 *
 * Until 11 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of any one
 * organisation could send every organisation's text to a translation account or
 * server of their choosing, or lift the monthly spending limit. The owner
 * decided that settings affecting every organisation are for a global
 * administrator only. Anybody else is now refused, is told why on screen, and
 * nothing is saved.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/278
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
//    (web/_apps/admin/translation/index.php) was not changed, so an
//    administrator of one organisation still sees a Save button there. This
//    refusal is what actually enforces the rule.
if (App::isRootAdmin() === false) {
    Logger::activity(
        'SettingsGroupSaveRefused',
        'Refused: portal-wide settings group "translation" may only be changed by a global administrator',
        $_SESSION['user_id'] ?? null
    );
    $_SESSION['flash_msg']  = 'These settings are portal-wide: they apply to every '
        . 'organisation on this installation, not only yours. Only a global '
        . 'administrator can change them. Your own organisation\'s settings '
        . 'are on the main Settings page and are unaffected.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/translation');
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

$provider = (string) ($_POST['provider'] ?? 'anthropic');
if (in_array($provider, ['anthropic','openai','google','deepl','libre'], true) === false) {
    $provider = 'anthropic';
}

$capPence = (int) round(((float) (string) ($_POST['monthCap'] ?? 0)) * 100);

$upsert($db, 'translation.enabled',        isset($_POST['enabled']) === true ? '1' : '0', false);
$upsert($db, 'translation.provider',       $provider, false);
$upsert($db, 'translation.monthCapPence',  (string) max(0, $capPence), false);
$upsert($db, 'translation.anthropic.model', trim((string) ($_POST['anth_model']  ?? 'claude-haiku-4-5-20251001')), false);
$upsert($db, 'translation.openai.model',    trim((string) ($_POST['oa_model']    ?? 'gpt-4o-mini')), false);
$upsert($db, 'translation.libre.baseUrl',   trim((string) ($_POST['libre_base']  ?? 'https://libretranslate.com')), false);

foreach (
    [
        'translation.anthropic.apiKey' => 'anth_key',
        'translation.openai.apiKey'    => 'oa_key',
        'translation.google.apiKey'    => 'goog_key',
        'translation.deepl.apiKey'     => 'deepl_key',
        'translation.libre.apiKey'     => 'libre_key',
    ] as $settingKey => $postKey
) {
    $val = trim((string) ($_POST[$postKey] ?? ''));
    if ($val !== '') {
        $upsert($db, $settingKey, $val, true);
    }
}

$_SESSION['flash_msg']  = 'Translation settings saved.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/translation');
exit();
