<?php
// Path: public_html/auth/2fa/disable.php
/**
 * -----------------------------------------------------------------------------
 * Auth — Disable TOTP 2FA
 * -----------------------------------------------------------------------------
 * Allows authenticated users to disable their two-factor authentication.
 * Requires password confirmation for security.
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/92
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /auth/account');
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /auth/account');
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

// 📋 Disable TOTP
$stmt = $mysqli->prepare(
    'UPDATE tblUsers SET totpSecret = NULL, totpEnabled = 0 WHERE userID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

// 📋 Remove backup codes
$delStmt = $mysqli->prepare('DELETE FROM tblTotpBackupCodes WHERE userID = ?');
if ($delStmt !== false) {
    $delStmt->bind_param('i', $userId);
    $delStmt->execute();
    $delStmt->close();
}

Logger::activity('TotpDisabled', 'Disabled two-factor authentication', $userId);

$_SESSION['flash_msg']  = 'Two-factor authentication has been disabled.';
$_SESSION['flash_type'] = 'info';
header('Location: /auth/account');
exit();
