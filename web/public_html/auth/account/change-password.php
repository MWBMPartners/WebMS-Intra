<?php
// Path: apps/auth/account/change-password.php
/**
 * -----------------------------------------------------------------------------
 * Change Password Handler 🔐
 * -----------------------------------------------------------------------------
 * Verifies the user's current password, validates the new password against the
 * configurable policy, hashes it, and updates tblLocalAccounts.  Regenerates
 * the session ID after a credential change as a security best practice.
 * -----------------------------------------------------------------------------
 * @package    Portal\Auth
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Logger;

// -----------------------------------------------------------------------------
// 1. 🛡️ Only accept POST from authenticated users
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /account', true, 302);
    exit();
}

Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    header('Location: /account?error=csrf', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 2. 📝 Read and validate input
// -----------------------------------------------------------------------------

$userId          = (int) $_SESSION['user_id'];
$currentPassword = $_POST['current_password'] ?? '';
$newPassword     = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['new_password_confirm'] ?? '';

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    header('Location: /account?error=pw_empty', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 3. 🔑 Verify current password against tblLocalAccounts
// -----------------------------------------------------------------------------

$stmt = $mysqli->prepare(
    'SELECT localID, passwordHash FROM tblLocalAccounts WHERE userID = ? LIMIT 1'
);

if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'CHANGEPW_PREP_FAIL', $mysqli->error, '');
    header('Location: /account?error=db', true, 302);
    exit();
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$local  = $result->fetch_assoc();
$stmt->close();

if ($local === null || ($local['passwordHash'] ?? '') === '') {
    Logger::activity('PasswordChangeFailed', 'No local account found for userID: ' . $userId);
    header('Location: /account?error=db', true, 302);
    exit();
}

if (password_verify($currentPassword, $local['passwordHash']) === false) {
    Logger::activity('PasswordChangeFailed', 'Incorrect current password');
    header('Location: /account?error=pw_current', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 4. 📋 Validate new password
// -----------------------------------------------------------------------------

if ($newPassword !== $confirmPassword) {
    header('Location: /account?error=pw_match', true, 302);
    exit();
}

$validation = Auth::validatePassword($newPassword);
if ($validation['valid'] === false) {
    header('Location: /account?error=pw_policy', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 5. 🔐 Hash and update the password
// -----------------------------------------------------------------------------

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$localId = (int) $local['localID'];

$updateStmt = $mysqli->prepare(
    'UPDATE tblLocalAccounts SET passwordHash = ? WHERE localID = ?'
);

if ($updateStmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'CHANGEPW_UPDATE_FAIL', $mysqli->error, '');
    header('Location: /account?error=db', true, 302);
    exit();
}

$updateStmt->bind_param('si', $newHash, $localId);
$updateStmt->execute();
$updateStmt->close();

// 🔄 Regenerate session ID after credential change (security best practice)
// See: https://owasp.org/www-community/attacks/Session_fixation
session_regenerate_id(true);

// 📝 Log the successful password change
Logger::activity('PasswordChanged', 'User changed their password');

// -----------------------------------------------------------------------------
// 6. 🔀 Redirect with success message
// -----------------------------------------------------------------------------

header('Location: /account?pwchanged=1', true, 302);
exit();
