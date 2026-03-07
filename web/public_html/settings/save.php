<?php
// Path: public_html/settings/save.php
/**
 * -----------------------------------------------------------------------------
 * Settings Save Handler 💾
 * -----------------------------------------------------------------------------
 * Processes POST from settings add/edit forms. Performs:
 *   - CSRF verification
 *   - Role check (requires Admin or Root Admin)
 *   - Encryption for isSensitive values
 *   - INSERT (new) or UPDATE (existing) row in tblSettings
 *   - Logs activity and redirects back with flash message
 *
 * @package   Portal\Settings
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /settings');
    exit();
}

// 🛡️ CSRF verification
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

// -----------------------------------------------------------------------------
// 📋 Collect and validate form fields
// -----------------------------------------------------------------------------
$settingId   = (int) ($_POST['settingID'] ?? 0);
$settingKey  = trim($_POST['settingKey'] ?? '');
$settingVal  = trim($_POST['settingValue'] ?? '');
$isSensitive = isset($_POST['isSensitive']) === true ? 1 : 0;

if ($settingKey === '') {
    $_SESSION['admin_flash_msg']  = 'Setting key is required.';
    $_SESSION['admin_flash_type'] = 'danger';
    header('Location: /settings');
    exit();
}

// 🔒 Encrypt sensitive value
if ($isSensitive === 1 && function_exists('encrypt_setting') === true) {
    $settingVal = encrypt_setting($settingVal);
}

// -----------------------------------------------------------------------------
// 💾 Insert or update DB row
// -----------------------------------------------------------------------------
if ($settingId > 0) {
    // ✏️ Update existing
    $stmt = $mysqli->prepare('UPDATE tblSettings SET settingValue = ?, isSensitive = ?, updatedAt = NOW() WHERE settingID = ?');
    if ($stmt === false) {
        $_SESSION['admin_flash_msg']  = 'Database error updating setting.';
        $_SESSION['admin_flash_type'] = 'danger';
        header('Location: /settings');
        exit();
    }
    $stmt->bind_param('sii', $settingVal, $isSensitive, $settingId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('SettingsUpdate', 'Updated setting: ' . $settingKey, $_SESSION['user_id'] ?? null);
    $_SESSION['admin_flash_msg']  = 'Setting "' . $settingKey . '" updated.';
    $_SESSION['admin_flash_type'] = 'success';
} else {
    // ➕ Insert new — ensure unique key
    $stmt = $mysqli->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('s', $settingKey);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            $_SESSION['admin_flash_msg']  = 'Setting key "' . $settingKey . '" already exists.';
            $_SESSION['admin_flash_type'] = 'danger';
            header('Location: /settings');
            exit();
        }
        $stmt->close();
    }

    $stmt = $mysqli->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, updatedAt) VALUES (?, ?, ?, NOW())');
    if ($stmt === false) {
        $_SESSION['admin_flash_msg']  = 'Database error adding setting.';
        $_SESSION['admin_flash_type'] = 'danger';
        header('Location: /settings');
        exit();
    }
    $stmt->bind_param('ssi', $settingKey, $settingVal, $isSensitive);
    $stmt->execute();
    $stmt->close();
    Logger::activity('SettingsInsert', 'Added setting: ' . $settingKey, $_SESSION['user_id'] ?? null);
    $_SESSION['admin_flash_msg']  = 'Setting "' . $settingKey . '" added.';
    $_SESSION['admin_flash_type'] = 'success';
}

// 🔄 Redirect back (PRG pattern)
header('Location: /settings');
exit();
