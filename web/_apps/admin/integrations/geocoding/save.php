<?php
// Path: _apps/admin/integrations/geocoding/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Geocoding settings save handler 💾 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Same per-key upsert pattern as every other integration save handler in
 * this codebase. `geo.google.apiKey` is sensitive (blank preserves the
 * existing value); `geo.autoGeocode` is a plain checkbox flag.
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

$redirect = '/admin/integrations/geocoding';

$googleApiKey = trim((string) ($_POST['googleApiKey'] ?? ''));
$autoGeocode  = isset($_POST['autoGeocode']) === true ? 'true' : 'false';

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

$upsert($db, 'geo.autoGeocode', $autoGeocode, false);
if ($googleApiKey !== '') {
    $upsert($db, 'geo.google.apiKey', $googleApiKey, true);
}

// 🔒 Never log the key value — path/action only.
Logger::activity('GeocodingSettingsSaved', 'Geocoding integration settings updated');

$_SESSION['flash_msg']  = 'Geocoding settings saved.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . $redirect, true, 302);
exit();
