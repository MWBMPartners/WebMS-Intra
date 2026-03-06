<?php
// Path: apps/auth/reset-password/save.php
/**
 * -----------------------------------------------------------------------------
 * Reset Password – Save Handler 🔐
 * -----------------------------------------------------------------------------
 * Validates the reset token, enforces the password policy, hashes the new
 * password, updates tblLocalAccounts, and marks the token as consumed.
 * All other pending tokens for the same user are also invalidated.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
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
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\RateLimiter;

// -----------------------------------------------------------------------------
// 1. 🛡️ Only accept POST
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /forgot-password', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 2. 🔒 Security checks
// -----------------------------------------------------------------------------

Auth::ensureSession();

// 🚦 Rate-limit check
if (RateLimiter::isBlocked() === true) {
    header('Location: /forgot-password', true, 302);
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('PasswordResetFailed', 'Invalid CSRF token on reset-password');
    header('Location: /forgot-password', true, 302);
    exit();
}

if (Captcha::verify($_POST) === false) {
    Logger::activity('PasswordResetFailed', 'Captcha failed on reset-password');
    header('Location: /forgot-password', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 3. 🔑 Validate the reset token
// -----------------------------------------------------------------------------

$plaintextToken = $_POST['token'] ?? '';

if ($plaintextToken === '') {
    Logger::activity('PasswordResetFailed', 'Empty token on reset-password submit');
    header('Location: /forgot-password', true, 302);
    exit();
}

$tokenHash = hash('sha256', $plaintextToken);

$stmt = $mysqli->prepare(
    'SELECT PR.resetID, PR.userID, PR.expiresAt '
    . 'FROM tblPasswordResets PR '
    . 'WHERE PR.tokenHash = ? AND PR.usedAt IS NULL '
    . 'LIMIT 1'
);

if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'RESET_VALIDATE_FAIL', $mysqli->error, '');
    header('Location: /forgot-password', true, 302);
    exit();
}

$stmt->bind_param('s', $tokenHash);
$stmt->execute();
$result = $stmt->get_result();
$reset  = $result->fetch_assoc();
$stmt->close();

if ($reset === null || strtotime($reset['expiresAt']) < time()) {
    Logger::activity('PasswordResetFailed', 'Invalid or expired token used');
    // 🔀 Redirect back to the reset page with the (now-invalid) token so it shows the error
    header('Location: /reset-password?token=' . urlencode($plaintextToken), true, 302);
    exit();
}

$resetId = (int) $reset['resetID'];
$userId  = (int) $reset['userID'];

// -----------------------------------------------------------------------------
// 4. 📝 Validate the new password
// -----------------------------------------------------------------------------

$password        = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';

if ($password === '' || $passwordConfirm === '') {
    // 🔀 Redirect back with token to re-show the form
    header('Location: /reset-password?token=' . urlencode($plaintextToken), true, 302);
    exit();
}

if ($password !== $passwordConfirm) {
    Logger::activity('PasswordResetFailed', 'Password mismatch on reset');
    header('Location: /reset-password?token=' . urlencode($plaintextToken), true, 302);
    exit();
}

$validation = Auth::validatePassword($password);
if ($validation['valid'] === false) {
    Logger::activity('PasswordResetFailed', 'Password policy not met on reset');
    header('Location: /reset-password?token=' . urlencode($plaintextToken), true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 5. 🔐 Hash and update the password in tblLocalAccounts
// -----------------------------------------------------------------------------

$newHash = password_hash($password, PASSWORD_DEFAULT);

$mysqli->begin_transaction();

try {
    // 🔄 Update the password hash
    $updateStmt = $mysqli->prepare(
        'UPDATE tblLocalAccounts SET passwordHash = ? WHERE userID = ?'
    );
    if ($updateStmt === false) {
        throw new \RuntimeException('DB prepare failed: ' . $mysqli->error);
    }
    $updateStmt->bind_param('si', $newHash, $userId);
    $updateStmt->execute();
    $updateStmt->close();

    // ✅ Mark the token as used
    $consumeStmt = $mysqli->prepare(
        'UPDATE tblPasswordResets SET usedAt = NOW() WHERE resetID = ?'
    );
    if ($consumeStmt === false) {
        throw new \RuntimeException('DB prepare failed: ' . $mysqli->error);
    }
    $consumeStmt->bind_param('i', $resetId);
    $consumeStmt->execute();
    $consumeStmt->close();

    // 🧹 Invalidate all other pending tokens for this user
    $invalidateStmt = $mysqli->prepare(
        'UPDATE tblPasswordResets SET usedAt = NOW() WHERE userID = ? AND usedAt IS NULL'
    );
    if ($invalidateStmt !== false) {
        $invalidateStmt->bind_param('i', $userId);
        $invalidateStmt->execute();
        $invalidateStmt->close();
    }

    $mysqli->commit();

    // 📝 Log the successful password reset
    Logger::activity('PasswordResetCompleted', 'Password reset completed for userID: ' . $userId);

} catch (\Throwable $ex) {
    $mysqli->rollback();
    Logger::errorPlatform('Auth', 'Error', 'RESET_UPDATE_FAIL', $ex->getMessage(), '');
    header('Location: /reset-password?token=' . urlencode($plaintextToken), true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 6. 🔀 Redirect to login with success message
// -----------------------------------------------------------------------------

header('Location: /login?reset=1', true, 302);
exit();
