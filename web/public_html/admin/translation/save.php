<?php
// Path: public_html/admin/translation/save.php
/**
 * Admin — Translation settings save handler.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/278
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
