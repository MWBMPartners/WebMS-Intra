<?php
// Path: _apps/admin/integrations/what3words/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — what3words settings save handler 💾 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Clone of admin/integrations/cloudflare-stream/save.php — same gates +
 * CSRF, per-key upsert closure, `encrypt_setting()` when sensitive, blank
 * input PRESERVES the existing secret. Never logs the key value.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
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

$redirect = '/admin/integrations/what3words';

$enabled = isset($_POST['enabled']) === true ? 'true' : 'false';
$apiKey  = trim((string) ($_POST['apiKey'] ?? ''));

$db = App::db();

/**
 * Per-key upsert; sensitive values encrypted, blanks preserve existing.
 */
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

$upsert($db, 'w3w.enabled', $enabled, false);
if ($apiKey !== '') {
    $upsert($db, 'w3w.apiKey', $apiKey, true);
}

// 🔒 Never log the key value — path/action only.
Logger::activity('W3wSettingsSaved', 'what3words integration settings updated');

$_SESSION['flash_msg']  = 'what3words settings saved.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . $redirect, true, 302);
exit();
