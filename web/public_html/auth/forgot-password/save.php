<?php
// Path: apps/auth/forgot-password/save.php
/**
 * -----------------------------------------------------------------------------
 * Forgot Password – Save Handler 🔓
 * -----------------------------------------------------------------------------
 * Generates a cryptographically secure reset token, stores its SHA-256 hash in
 * tblPasswordResets, and emails the plaintext link to the user (when the Mailer
 * is configured).
 *
 * Security:
 *   - Always shows the same generic message to prevent email enumeration.
 *   - Token is hashed before storage — a DB breach never exposes valid tokens.
 *   - Existing unused tokens for the same user are invalidated.
 *   - Rate-limited via RateLimiter to prevent abuse.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
 * -----------------------------------------------------------------------------
 * @package    Portal\Auth
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-2026 MWBM Partners Ltd (t/a MWservices)
 * @license    MIT
 * @version    0.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\RateLimiter;

// -----------------------------------------------------------------------------
// 1. 🛡️ Only accept POST
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /forgot-password', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 2. 🔒 Security checks (CSRF, captcha, rate limit)
// -----------------------------------------------------------------------------

Auth::ensureSession();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('PasswordResetFailed', 'Invalid CSRF token on forgot-password');
    header('Location: /forgot-password', true, 302);
    exit();
}

if (Captcha::verify($_POST) === false) {
    Logger::activity('PasswordResetFailed', 'Captcha failed on forgot-password');
    header('Location: /forgot-password', true, 302);
    exit();
}

if (RateLimiter::isBlocked() === true) {
    Logger::activity('PasswordResetBlocked', 'Rate-limited forgot-password request');
    // 🛡️ Still redirect to success page to prevent enumeration
    header('Location: /forgot-password?sent=1', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 3. 📧 Look up user by email (must have a local account)
// -----------------------------------------------------------------------------

$email = strtolower(trim($_POST['email'] ?? ''));

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    // 🛡️ Invalid email — still redirect to success to prevent enumeration
    header('Location: /forgot-password?sent=1', true, 302);
    exit();
}

$stmt = $mysqli->prepare(
    'SELECT U.userID, U.fullName, U.emailAddress '
    . 'FROM tblUsers U '
    . 'JOIN tblLocalAccounts LA ON LA.userID = U.userID '
    . 'WHERE U.emailAddress = ? AND U.isActive = 1 '
    . 'LIMIT 1'
);

if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'RESET_PREP_FAIL', $mysqli->error, '');
    header('Location: /forgot-password?sent=1', true, 302);
    exit();
}

$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();

// 🛡️ If user not found, still redirect to same success page (timing-safe)
if ($user === null) {
    Logger::activity('PasswordResetNoUser', 'Reset requested for unknown email');
    header('Location: /forgot-password?sent=1', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 4. 🔑 Generate token and store hash in tblPasswordResets
// -----------------------------------------------------------------------------

$userId = (int) $user['userID'];

// 🧹 Invalidate any existing unused tokens for this user
$invalidateStmt = $mysqli->prepare(
    'UPDATE tblPasswordResets SET usedAt = NOW() WHERE userID = ? AND usedAt IS NULL'
);
if ($invalidateStmt !== false) {
    $invalidateStmt->bind_param('i', $userId);
    $invalidateStmt->execute();
    $invalidateStmt->close();
}

// 🔐 Generate a cryptographically secure plaintext token (64-char hex)
$plaintextToken = bin2hex(random_bytes(32));
$tokenHash      = hash('sha256', $plaintextToken);

// 🕐 Calculate expiry based on setting (default 60 minutes)
$expiryMinutes = (int) (App::settings('auth.passwordReset.tokenExpiry') ?? '60');
$expiresAt     = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));

// 🌐 Capture the requester's IP for audit trail
$createdIP = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '';
// Take only the first IP if X-Forwarded-For has a chain
if (str_contains($createdIP, ',') === true) {
    $createdIP = trim(explode(',', $createdIP)[0]);
}

$insertStmt = $mysqli->prepare(
    'INSERT INTO tblPasswordResets (userID, tokenHash, expiresAt, createdIP) '
    . 'VALUES (?, ?, ?, ?)'
);

if ($insertStmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'RESET_INSERT_FAIL', $mysqli->error, '');
    header('Location: /forgot-password?sent=1', true, 302);
    exit();
}

$insertStmt->bind_param('isss', $userId, $tokenHash, $expiresAt, $createdIP);
$insertStmt->execute();
$insertStmt->close();

Logger::activity('PasswordResetRequested', 'Password reset token created for: ' . $email);

// -----------------------------------------------------------------------------
// 5. 📨 Send reset email (if Mailer is configured)
// -----------------------------------------------------------------------------

$mailerClientID  = App::settings('auth.ms365.appwide.clientID') ?? '';
$mailerFromAddr  = App::settings('mail.defaultFromAddress') ?? '';
$mailerConfigured = ($mailerClientID !== '' && $mailerFromAddr !== '');

if ($mailerConfigured === true) {
    // 🔗 Build the reset link
    $protocol  = (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $resetLink = $protocol . '://' . $host . '/reset-password?token=' . urlencode($plaintextToken);

    $siteName = App::settings('site.name') ?? 'Portal';
    $userName = htmlspecialchars($user['fullName'] ?? 'User', ENT_QUOTES, 'UTF-8');

    $htmlBody = '<!DOCTYPE html><html><body style="font-family:system-ui,sans-serif;color:#333;">'
        . '<h2 style="color:#0d6efd;">Password Reset Request</h2>'
        . '<p>Hi ' . $userName . ',</p>'
        . '<p>We received a request to reset your password for your '
        . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . ' account.</p>'
        . '<p style="margin:24px 0;">'
        . '<a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" '
        . 'style="background:#198754;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;">'
        . 'Reset My Password</a></p>'
        . '<p>Or copy and paste this link into your browser:</p>'
        . '<p style="word-break:break-all;color:#6c757d;font-size:0.875rem;">'
        . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="color:#6c757d;font-size:0.875rem;">This link expires in '
        . $expiryMinutes . ' minutes. If you did not request this, you can safely ignore this email.</p>'
        . '<hr style="border:none;border-top:1px solid #dee2e6;margin:24px 0;">'
        . '<p style="color:#999;font-size:0.75rem;">'
        . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</body></html>';

    try {
        Mailer::send(
            [$user['emailAddress']],
            'Password Reset Request – ' . $siteName,
            $htmlBody
        );
        Logger::activity('PasswordResetEmailed', 'Reset email sent to: ' . $email);
    } catch (\Throwable $ex) {
        // 📝 Log the error but don't expose it to the user
        Logger::errorPlatform('Mailer', 'Error', 'RESET_MAIL_FAIL', $ex->getMessage(), '');
    }
} else {
    // 📝 Log that mailer is not configured — admin must reset manually
    Logger::activity('PasswordResetNoMailer', 'Reset requested but mailer not configured for: ' . $email);
}

// -----------------------------------------------------------------------------
// 6. 🔀 Always redirect to the same success page (prevents enumeration)
// -----------------------------------------------------------------------------

header('Location: /forgot-password?sent=1', true, 302);
exit();
