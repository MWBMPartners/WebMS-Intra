<?php
// Path: apps/auth/forgot-password/index.php
/**
 * -----------------------------------------------------------------------------
 * Forgot Password Page 🔓
 * -----------------------------------------------------------------------------
 * Displays a simple form asking for the user's email address.  On submission
 * the save handler generates a time-limited reset token and emails a link
 * (when the Mailer is configured).  The page always shows the same generic
 * confirmation message to prevent email-address enumeration.
 *
 * Standalone layout (no header/footer templates) — like the login page.
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
// 2. 📨 Check for flash message from save handler
// -----------------------------------------------------------------------------

$sent = (isset($_GET['sent']) === true && $_GET['sent'] === '1');

// -----------------------------------------------------------------------------
// 3. 🎨 Render page
// -----------------------------------------------------------------------------
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password &bull; <?php echo htmlspecialchars($SETTINGS['site']['name'] ?? 'Portal', ENT_QUOTES, 'UTF-8'); ?></title>

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
        <i class="fa-solid fa-key fa-2x text-muted mb-2"></i>
        <h1 class="h4 mb-1">Forgot Password</h1>
        <p class="text-muted small mb-0">Enter your email address and we'll send you a reset link.</p>
    </div>

    <?php if ($sent === true): ?>
        <!-- ✅ Generic confirmation (same message regardless of email match) -->
        <div class="alert alert-success small" role="alert">
            <i class="fa-solid fa-circle-check me-1"></i>
            If an account with that email address exists, a password reset link has been sent.
            Please check your inbox.
        </div>
        <a href="/login" class="btn btn-outline-secondary w-100">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Sign In
        </a>
    <?php else: ?>
        <!-- 📝 Email form -->
        <form method="post" action="/forgot-password/save" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control" id="email" name="email"
                       autocomplete="email" required autofocus
                       placeholder="you@example.com">
            </div>

            <?php echo Captcha::widget(); ?>

            <button type="submit" class="btn btn-success w-100 mb-3">
                <i class="fa-solid fa-paper-plane me-1"></i> Send Reset Link
            </button>

            <a href="/login" class="btn btn-outline-secondary w-100">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Sign In
            </a>
        </form>
    <?php endif; ?>

</div>

<!-- 📦 JavaScript (CDN with local fallback) -->
<?php echo Asset::bootstrapJs(); ?>

</body>
</html>
