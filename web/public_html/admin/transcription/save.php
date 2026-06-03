<?php
// Path: public_html/admin/transcription/save.php
/**
 * Admin — Transcription settings save.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
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

$provider = (string) ($_POST['provider'] ?? 'openai');
if (in_array($provider, ['openai','assemblyai','local'], true) === false) {
    $provider = 'openai';
}
$upsert($db, 'transcription.enabled',    isset($_POST['enabled']) === true ? '1' : '0', false);
$upsert($db, 'transcription.provider',   $provider, false);
$upsert($db, 'transcription.language',   substr(trim((string) ($_POST['language'] ?? 'en')), 0, 10), false);
$upsert($db, 'transcription.batchSize',  (string) max(1, min(50, (int) ($_POST['batchSize'] ?? 5))), false);
$upsert($db, 'transcription.openai.model', trim((string) ($_POST['oa_model'] ?? 'whisper-1')), false);
$upsert($db, 'transcription.local.binPath', trim((string) ($_POST['local_bin'] ?? '')), false);

$oa = trim((string) ($_POST['oa_key'] ?? ''));
if ($oa !== '') {
    $upsert($db, 'transcription.openai.apiKey', $oa, true);
}
$aa = trim((string) ($_POST['aa_key'] ?? ''));
if ($aa !== '') {
    $upsert($db, 'transcription.assemblyai.apiKey', $aa, true);
}

$_SESSION['flash_msg']  = 'Transcription settings saved.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/transcription');
exit();
