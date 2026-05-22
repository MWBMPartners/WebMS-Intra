<?php
// Path: apps/auth/reset-password/index.php
/**
 * -----------------------------------------------------------------------------
 * Reset Password Page 🔐
 * -----------------------------------------------------------------------------
 * Validates the token from the email link, then displays a form for the user
 * to enter a new password.  If the token is invalid or expired, an error is
 * shown with a link back to the forgot-password page.
 *
 * Standalone layout (no header/footer templates) — like the login page.
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

use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Captcha;

// -----------------------------------------------------------------------------
// 1. 🔀 If already logged in, redirect to account page
// -----------------------------------------------------------------------------

if (Auth::check() === true) {
    header('Location: /account', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 2. 🔑 Validate the reset token
// -----------------------------------------------------------------------------

$plaintextToken = $_GET['token'] ?? '';
$tokenValid     = false;
$errorMsg       = '';

if ($plaintextToken === '') {
    $errorMsg = 'No reset token provided.';
} else {
    $tokenHash = hash('sha256', $plaintextToken);

    $stmt = $mysqli->prepare(
        'SELECT PR.resetID, PR.userID, PR.expiresAt '
        . 'FROM tblPasswordResets PR '
        . 'WHERE PR.tokenHash = ? AND PR.usedAt IS NULL '
        . 'LIMIT 1'
    );

    if ($stmt === false) {
        $errorMsg = 'A system error occurred. Please try again later.';
    } else {
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $result = $stmt->get_result();
        $reset  = $result->fetch_assoc();
        $stmt->close();

        if ($reset === null) {
            $errorMsg = 'This reset link is invalid or has already been used.';
        } elseif (strtotime($reset['expiresAt']) < time()) {
            $errorMsg = 'This reset link has expired. Please request a new one.';
        } else {
            $tokenValid = true;
        }
    }
}

// -----------------------------------------------------------------------------
// 3. 📋 Resolve password policy for display + the strength meter
// -----------------------------------------------------------------------------

$policy      = Auth::passwordPolicy();
$policyItems = $policy['rules'];

// -----------------------------------------------------------------------------
// 4. 🎨 Render page
// -----------------------------------------------------------------------------
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password &bull; <?php echo htmlspecialchars($SETTINGS['site']['name'] ?? 'Portal', ENT_QUOTES, 'UTF-8'); ?></title>

    <!-- 🎨 Stylesheets (CDN with local fallback) -->
    <?php echo Asset::bootstrapCss(); ?>
    <?php echo Asset::fontAwesomeCss(); ?>
    <?php echo Asset::portalCss(); ?>

    <!-- 🌙 Prevent FOUC: apply saved theme before first paint -->
    <script>
    (function(){
        var t = localStorage.getItem('portal-theme');
        if (t === 'dark' || t === 'light') {
            document.documentElement.setAttribute('data-bs-theme', t);
        }
    })();
    </script>

    <!-- 🤖 Captcha script (if configured) -->
    <?php echo Captcha::scriptTag(); ?>

</head>
<body class="d-flex align-items-center justify-content-center vh-100">
<div class="card shadow p-4" style="min-width:320px;max-width:420px;width:100%;">

    <!-- 🏷️ Header -->
    <div class="text-center mb-3">
        <i class="fa-solid fa-lock-open fa-2x text-muted mb-2"></i>
        <h1 class="h4 mb-1">Reset Password</h1>
    </div>

    <?php if ($tokenValid === false): ?>
        <!-- ❌ Invalid or expired token -->
        <div class="alert alert-danger small" role="alert">
            <i class="fa-solid fa-circle-exclamation me-1"></i>
            <?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <a href="/forgot-password" class="btn btn-outline-primary w-100 mb-2">
            <i class="fa-solid fa-paper-plane me-1"></i> Request a New Reset Link
        </a>
        <a href="/login" class="btn btn-outline-secondary w-100">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Sign In
        </a>
    <?php else: ?>
        <p class="text-muted small text-center mb-3">Enter your new password below.</p>

        <!-- 📝 New password form -->
        <form method="post" action="/reset-password/save" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($plaintextToken, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <input type="password" class="form-control" id="password" name="password"
                       autocomplete="new-password" required autofocus
                       minlength="<?php echo (int) $policy['minLength']; ?>"
                       maxlength="<?php echo (int) $policy['maxLength']; ?>"
                       data-portal-password-input>
                <div class="portal-password-strength mt-2" data-portal-password-meter hidden>
                    <div class="progress" style="height:6px;">
                        <div class="progress-bar" role="progressbar" style="width:0%"></div>
                    </div>
                    <small class="form-text" data-portal-password-meter-label>Password strength</small>
                </div>
            </div>

            <div class="mb-2">
                <label for="password_confirm" class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                       autocomplete="new-password" required
                       minlength="<?php echo (int) $policy['minLength']; ?>"
                       maxlength="<?php echo (int) $policy['maxLength']; ?>">
            </div>

            <!-- 📋 Password requirements -->
            <div class="small text-muted mb-3">
                <strong>Password requirements:</strong>
                <ul class="mb-0 ps-3">
                    <?php foreach ($policyItems as $item): ?>
                        <li><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php echo Captcha::widget(); ?>

            <button type="submit" class="btn btn-success w-100 mb-3">
                <i class="fa-solid fa-check me-1"></i> Update Password
            </button>

            <a href="/login" class="btn btn-outline-secondary w-100">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Sign In
            </a>
        </form>
    <?php endif; ?>

</div>

<!-- 📦 JavaScript (CDN with local fallback) -->
<?php echo Asset::bootstrapJs(); ?>
<?php echo Asset::portalJs(); ?>

</body>
</html>
