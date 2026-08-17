<?php
// Path: public_html/auth/2fa/disable.php
/**
 * -----------------------------------------------------------------------------
 * Auth — Disable TOTP 2FA
 * -----------------------------------------------------------------------------
 * Allows authenticated users to disable their two-factor authentication.
 * Requires password confirmation for security (current_password field,
 * verified against tblLocalAccounts before TOTP is disabled — #B5b).
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/92
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /account');
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

// 📌 Flash messages on this handler use the SAME `/account?error=CODE`
// query-string convention as unlink.php / webauthn-delete.php (the page
// only ever reads `$_GET['error']` / `$_GET['success']` — it does not
// render `$_SESSION['flash_msg']`), so every early-exit below follows that
// convention rather than the session-flash pattern used elsewhere in Auth.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    header('Location: /account?error=csrf');
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🔐 Password re-confirmation (#B5b). CSRF alone only proves the POST came
// from a page this browser was served — it does NOT prove the operator is
// the account owner rather than someone who has taken over an already-
// authenticated session (stolen cookie, unlocked/shared device, XSS).
// Disabling 2FA REMOVES a security control, so — exactly like this
// docblock always promised, but the code never enforced — re-prove
// "something you know" immediately before it takes effect. Uses the same
// password_verify() check against tblLocalAccounts.passwordHash that
// Auth::loginLocal() and account/change-password.php use.
$currentPassword = (string) ($_POST['current_password'] ?? '');

$pwStmt = $mysqli->prepare('SELECT passwordHash FROM tblLocalAccounts WHERE userID = ? LIMIT 1');
if ($pwStmt === false) {
    header('Location: /account?error=db');
    exit();
}
$pwStmt->bind_param('i', $userId);
$pwStmt->execute();
$pwRow = $pwStmt->get_result()->fetch_assoc();
$pwStmt->close();

if ($pwRow === null || ($pwRow['passwordHash'] ?? '') === '') {
    // 🚫 No local password to confirm against (SSO/passkey-only account) —
    // fail CLOSED rather than silently skipping the re-auth check.
    Logger::activity('TotpDisableFailed', 'Disable refused — no local password to confirm', $userId);
    header('Location: /account?error=totp_nopw');
    exit();
}

if ($currentPassword === '' || password_verify($currentPassword, $pwRow['passwordHash']) === false) {
    Logger::activity('TotpDisableFailed', 'Incorrect password on 2FA disable attempt', $userId);
    header('Location: /account?error=pw_current');
    exit();
}

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

header('Location: /account?totp_disabled=1');
exit();
