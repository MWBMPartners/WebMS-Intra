<?php
// Path: public_html/admin/integrations/monitoring/save.php
/**
 * Admin — Error-monitoring settings save handler.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/143
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

$enabled = isset($_POST['enabled']) === true ? '1' : '0';
$env     = trim((string) ($_POST['environment'] ?? ''));
$rateRaw = (float) ($_POST['sampleRate'] ?? 1.0);
if ($rateRaw < 0.0) {
    $rateRaw = 0.0;
} elseif ($rateRaw > 1.0) {
    $rateRaw = 1.0;
}
$sampleRate = sprintf('%.3f', $rateRaw);

$upsert($db, 'monitoring.enabled',     $enabled, false);
$upsert($db, 'monitoring.environment', $env,     false);
$upsert($db, 'monitoring.sampleRate',  $sampleRate, false);

$dsn = trim((string) ($_POST['dsn'] ?? ''));
if ($dsn !== '') {
    $upsert($db, 'monitoring.sentryDsn', $dsn, true);
}

$_SESSION['flash_msg']  = 'Error-monitoring settings saved.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/integrations/monitoring');
exit();
