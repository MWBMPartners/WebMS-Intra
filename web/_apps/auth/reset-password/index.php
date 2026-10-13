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
 * @version    0.2.1
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

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

// 🔗 This page's address IS the reset link, so the browser is told never to
//    pass it on (#580). Without this the browser names the page it came from
//    when the form is sent, and a refused save used to store the whole link
//    in the activity log (see Logger::redactSensitiveHeaders()). This
//    replaces, for this page only, the policy the portal's start-up sets.
//    WHAT IT CANNOT DO: it is a request to the browser. An old browser, or
//    one set up otherwise, may ignore it; the logger's own cut is what the
//    portal relies on.
header('Referrer-Policy: no-referrer');

// -----------------------------------------------------------------------------
// 2. 🔑 Validate the reset token
// -----------------------------------------------------------------------------

// Text or nothing: a list sent in its place ("token[]=x") is read as empty.
$plaintextToken = (isset($_GET['token']) === true && is_string($_GET['token']) === true) ? $_GET['token'] : '';
$tokenValid     = false;
$errorMsg       = '';

if ($plaintextToken === '') {
    $errorMsg = 'No reset token provided.';
} else {
    // Auth::findResetLink() is the one place that decides. This page used to
    // carry its own copy of the test, on the page's own clock (#580).
    try {
        $linkState = Auth::findResetLink($mysqli, $plaintextToken)['state'];
    } catch (\Throwable $ex) {
        $linkState = 'error';
    }

    if ($linkState === 'usable') {
        $tokenValid = true;
    } elseif ($linkState === 'expired') {
        $errorMsg = 'This reset link has expired. Please request a new one.';
    } elseif ($linkState === 'error') {
        $errorMsg = 'A system error occurred. Please try again later.';
    } else {
        $errorMsg = 'This reset link is invalid or has already been used.';
    }
}

// ⏳ Sent back here by the save page because this address has tried to use
//    reset links too many times (#580). `wait` is a number of minutes the save
//    page worked out. It arrives in the address, so a visitor can change it,
//    but only what their own screen says: the limit itself is kept in the
//    database. Held to 1 to 60 so that nothing odd can be printed.
$waitMinutes = 0;
if (isset($_GET['wait']) === true && is_string($_GET['wait']) === true && ctype_digit($_GET['wait']) === true) {
    $waitMinutes = max(1, min(60, (int) $_GET['wait']));
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
<html lang="<?php echo htmlspecialchars(\Portal\Core\I18n::locale(), ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo \Portal\Core\I18n::dir(); ?>" data-bs-theme="light">
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
<body class="d-flex align-items-center justify-content-center min-vh-100 py-4">
<div class="card shadow p-4" style="min-width:min(320px,100%);max-width:420px;width:100%;">

    <!-- 🏷️ Header -->
    <div class="text-center mb-3">
        <i class="fa-solid fa-lock-open fa-2x text-muted mb-2"></i>
        <h1 class="h4 mb-1">Reset Password</h1>
    </div>

    <?php if ($waitMinutes > 0): ?>
        <!-- ⏳ Too many tries from this address. Shown whether or not the link
             is a real one, so it gives nothing away about the link. -->
        <div class="alert alert-warning small" role="alert">
            <i class="fa-solid fa-hourglass-half me-1" aria-hidden="true"></i>
            Too many tries from this connection, so your password has not been changed.
            Please wait about <?php echo $waitMinutes; ?> minute(s) and try again.
            A reset link that has not run out will still work then.
        </div>
    <?php endif; ?>

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

            <?php echo Captcha::widget(Captcha::FOR_PASSWORD_RESET); ?>

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
