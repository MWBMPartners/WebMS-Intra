<?php
// Path: public_html/auth/2fa/verify.php
/**
 * -----------------------------------------------------------------------------
 * Auth — TOTP 2FA Verification (Post-Login Challenge)
 * -----------------------------------------------------------------------------
 * Shown after successful password/OAuth login when 2FA is enabled.
 * User must enter TOTP code or backup code to complete authentication.
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
use Portal\Core\Totp;

Auth::ensureSession();

// 🔍 Must have pending 2FA session
if (isset($_SESSION['2fa_user_id']) === false) {
    header('Location: /auth/login');
    exit();
}

$pendingUserId = (int) $_SESSION['2fa_user_id'];

// 📋 Handle POST verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /auth/2fa/verify');
        exit();
    }

    $code     = trim($_POST['code'] ?? '');
    $useBackup = isset($_POST['use_backup']) === true;

    if ($code === '') {
        $_SESSION['flash_msg']  = 'Please enter a code.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /auth/2fa/verify');
        exit();
    }

    // 🔍 Fetch user's TOTP secret
    $uStmt = $mysqli->prepare('SELECT totpSecret FROM tblUsers WHERE userID = ? AND totpEnabled = 1 LIMIT 1');
    if ($uStmt === false) {
        $_SESSION['flash_msg']  = 'Database error.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /auth/2fa/verify');
        exit();
    }
    $uStmt->bind_param('i', $pendingUserId);
    $uStmt->execute();
    $uRow = $uStmt->get_result()->fetch_assoc();
    $uStmt->close();

    if ($uRow === null) {
        // TOTP was disabled between login and verify
        unset($_SESSION['2fa_user_id']);
        $_SESSION['flash_msg']  = 'Two-factor authentication is not configured. Please login again.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: /auth/login');
        exit();
    }

    $verified = false;

    if ($useBackup === true) {
        // 🔍 Check backup codes
        $bcStmt = $mysqli->prepare(
            'SELECT codeID, codeHash FROM tblTotpBackupCodes WHERE userID = ? AND isUsed = 0'
        );
        if ($bcStmt !== false) {
            $bcStmt->bind_param('i', $pendingUserId);
            $bcStmt->execute();
            $bcResult = $bcStmt->get_result();
            while ($bcRow = $bcResult->fetch_assoc()) {
                if (password_verify($code, $bcRow['codeHash']) === true) {
                    // 📋 Mark code as used
                    $markStmt = $mysqli->prepare(
                        'UPDATE tblTotpBackupCodes SET isUsed = 1, usedAt = NOW() WHERE codeID = ?'
                    );
                    if ($markStmt !== false) {
                        $markStmt->bind_param('i', $bcRow['codeID']);
                        $markStmt->execute();
                        $markStmt->close();
                    }
                    $verified = true;
                    break;
                }
            }
            $bcStmt->close();
        }
    } else {
        // 🔍 Verify TOTP code
        $secret = Auth::decrypt($uRow['totpSecret']);
        if ($secret !== false) {
            $verified = Totp::verify($secret, $code);
        }
    }

    if ($verified === false) {
        Logger::activity('TotpVerifyFailed', 'Failed 2FA verification attempt', $pendingUserId);
        $_SESSION['flash_msg']  = 'Invalid code. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /auth/2fa/verify');
        exit();
    }

    // ✅ 2FA passed — complete login
    $_SESSION['user_id']    = $pendingUserId;
    $_SESSION['2fa_passed'] = true;
    unset($_SESSION['2fa_user_id']);

    // 🔐 "Trust this device" — if the user opted in, issue a cookie that
    // bypasses 2FA on this browser for the configured window. Done AFTER
    // session promotion so it's only set on a successful verification.
    if (isset($_POST['trust_device']) === true && $_POST['trust_device'] === '1') {
        Auth::issueTrustedDevice($pendingUserId);
        Logger::activity('TotpDeviceTrusted', 'Device marked trusted (2FA bypass for the trust window)', $pendingUserId);
    }

    Logger::activity('TotpVerified', '2FA verification successful' . ($useBackup === true ? ' (backup code)' : ''), $pendingUserId);

    $redirect = $_SESSION['login_redirect'] ?? '/dashboard';
    unset($_SESSION['login_redirect']);
    header('Location: ' . $redirect);
    exit();
}

// 📌 Page metadata
$pageTitle = 'Two-Factor Verification';

// 📄 Minimal page (no nav — user not fully authenticated)
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/fontawesome/all.min.css">
</head>
<body class="bg-light">
<div class="container">
    <div class="row justify-content-center mt-5">
        <div class="col-12 col-md-5">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h3 class="text-center mb-4">
                        <i class="fa-solid fa-shield-halved me-2"></i>Two-Factor Verification
                    </h3>

                    <?php if (isset($_SESSION['flash_msg']) === true): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($_SESSION['flash_type'] ?? 'info', ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
                            <?php echo htmlspecialchars($_SESSION['flash_msg'], ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
                    <?php endif; ?>

                    <p class="text-muted text-center">Enter the 6-digit code from your authenticator app.</p>

                    <form method="post" action="/auth/2fa/verify">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="mb-3">
                            <input type="text" class="form-control form-control-lg text-center" name="code"
                                   maxlength="8" pattern="[A-Za-z0-9]{6,8}" inputmode="numeric"
                                   autocomplete="one-time-code" placeholder="000000" required autofocus>
                        </div>
                        <?php
                        // 🔐 Trust-this-device opt-in (#v1.0). Default OFF — user opts in per session.
                        $trustDays = (int) (\Portal\Core\App::settings('auth.twoFactor.trustedDeviceDays') ?? '30');
                        if ($trustDays < 1) { $trustDays = 30; }
                        ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="trust_device" value="1" id="trust_device">
                            <label class="form-check-label small" for="trust_device">
                                Trust this device for the next <?php echo (int) $trustDays; ?> days
                                <span class="text-muted d-block small">
                                    Don't tick this on shared or public computers.
                                </span>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mb-3">
                            <i class="fa-solid fa-check me-1"></i>Verify
                        </button>
                    </form>

                    <hr>

                    <p class="text-center small text-muted mb-2">Lost your authenticator?</p>
                    <form method="post" action="/auth/2fa/verify">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="use_backup" value="1">
                        <div class="mb-2">
                            <input type="text" class="form-control text-center" name="code"
                                   maxlength="8" placeholder="Backup code" required>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary w-100 btn-sm">
                            Use Backup Code
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
