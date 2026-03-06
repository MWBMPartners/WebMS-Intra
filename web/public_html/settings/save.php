<?php
// Path: apps/settings/save.php
/**
 * -----------------------------------------------------------------------------
 * Settings Save Handler 💾
 * -----------------------------------------------------------------------------
 * Processes POST from settings add/edit forms. Performs:
 *   • CSRF verification
 *   • Role check (requires Admin or Global Admin)
 *   • Encryption for isSensitive values
 *   • INSERT (new) or UPDATE (existing) row in tblSettings
 *   • Logs activity and redirects back to settings list with flash status
 * -----------------------------------------------------------------------------
 * @package    Portal\Settings
 * @license    MIT
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;

// -----------------------------------------------------------------------------
// 1. Permission & CSRF checks
// -----------------------------------------------------------------------------

Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    echo 'Invalid CSRF token.';
    exit();
}

// Require Admin or Root Admin role
if (App::isAdmin() === false) {
    echo 'Access denied.';
    exit();
}

// -----------------------------------------------------------------------------
// 2. Collect and validate form fields
// -----------------------------------------------------------------------------

$settingId   = intval($_POST['settingID'] ?? 0);
$settingKey  = trim($_POST['settingKey'] ?? '');
$settingVal  = trim($_POST['settingValue'] ?? '');
$isSensitive = isset($_POST['isSensitive']) ? 1 : 0;

if ($settingKey === '') {
    echo 'Setting key is required.';
    exit();
}

// Encrypt sensitive value
if ($isSensitive === 1) {
    $settingVal = encrypt_setting($settingVal);
}

// -----------------------------------------------------------------------------
// 3. Insert or update DB row
// -----------------------------------------------------------------------------

if ($settingId > 0) {
    // Update existing
    $stmt = $mysqli->prepare('UPDATE tblSettings SET settingValue = ?, isSensitive = ?, updatedAt = NOW() WHERE settingID = ?');
    if ($stmt === false) {
        echo 'DB prepare failed.';
        exit();
    }
    $stmt->bind_param('sii', $settingVal, $isSensitive, $settingId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('SettingsUpdate', 'Updated setting ' . $settingKey);
} else {
    // Insert new – ensure unique key
    $stmt = $mysqli->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? LIMIT 1');
    $stmt->bind_param('s', $settingKey);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        echo 'Setting key already exists.';
        exit();
    }
    $stmt->close();

    $stmt = $mysqli->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, updatedAt) VALUES (?,?,?,NOW())');
    if ($stmt === false) {
        echo 'DB insert failed.';
        exit();
    }
    $stmt->bind_param('ssi', $settingKey, $settingVal, $isSensitive);
    $stmt->execute();
    $stmt->close();
    Logger::activity('SettingsInsert', 'Added setting ' . $settingKey);
}

// -----------------------------------------------------------------------------
// 4. Redirect back
// -----------------------------------------------------------------------------
header('Location: /settings?success=1');
exit();
