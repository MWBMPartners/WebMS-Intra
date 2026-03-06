<?php
// Path: apps/auth/login/index.php
/**
 * -----------------------------------------------------------------------------
 * Login Page 🛂
 * -----------------------------------------------------------------------------
 * Presents a local-account login form as the primary authentication method.
 * If Microsoft 365 OAuth credentials are configured, an MS365 sign-in button
 * is shown below the local form as an alternative.  Uses Cloudflare Turnstile
 * or reCAPTCHA if API keys are configured.
 *
 * Handles POST for local logins — including CSRF, captcha, rate limiting, and
 * password verification — delegating to Auth::loginLocal().
 *
 * This page has its own centred layout (no navbar, just a card) and does NOT
 * use the header.php / footer.php templates.
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
use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\RateLimiter;

// -----------------------------------------------------------------------------
// 1. 🔀 If already logged in, redirect to dashboard
// -----------------------------------------------------------------------------

if (Auth::check() === true) {
    $target = $_GET['redirect'] ?? '/';
    if (str_starts_with($target, '/') === false || str_starts_with($target, '//') === true) {
        $target = '/';
    }
    header('Location: ' . $target, true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 2. 🛡️ Handle local login POST
// -----------------------------------------------------------------------------

$errorMsg  = '';
$successMsg = '';
$username   = '';

// 📨 Flash messages from password-reset flow
if (isset($_GET['reset']) === true && $_GET['reset'] === '1') {
    $successMsg = 'Your password has been updated. Please sign in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 🚦 Rate-limit check (block brute-force attempts)
    if (RateLimiter::isBlocked() === true) {
        $remaining = RateLimiter::lockoutRemaining();
        $errorMsg  = 'Too many failed attempts. Please try again in ' . $remaining . ' minute(s).';
    }

    // 🔒 CSRF check
    if ($errorMsg === '') {
        if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
            $errorMsg = 'Invalid session token. Please try again.';
        }
    }

    // 🤖 Captcha check (if configured)
    if ($errorMsg === '') {
        if (Captcha::verify($_POST) === false) {
            $errorMsg = 'Captcha verification failed.';
        }
    }

    // 📝 Validate required fields
    if ($errorMsg === '') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            $errorMsg = 'Please enter your username or email and password.';
        }
    }

    // 🔑 Authenticate via Auth::loginLocal() — handles tblLocalAccounts lookup,
    // password verification, session creation, and lastLogin update
    if ($errorMsg === '') {
        if (Auth::loginLocal($username, $password) === true) {
            // 🔀 Redirect to dashboard or original target
            $target = $_GET['redirect'] ?? '/';
            if (str_starts_with($target, '/') === false || str_starts_with($target, '//') === true) {
                $target = '/';
            }
            header('Location: ' . $target, true, 302);
            exit();
        }
        $errorMsg = 'Invalid credentials.';
    }

    if ($errorMsg !== '') {
        Logger::activity('LoginFailed', $errorMsg);
    }
}

// -----------------------------------------------------------------------------
// 3. 🎨 Render page (Bootstrap 5)
// -----------------------------------------------------------------------------
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In &bull; <?php echo htmlspecialchars($SETTINGS['site']['name'] ?? 'Portal', ENT_QUOTES, 'UTF-8'); ?></title>

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

    <!-- 🏷️ Site branding -->
    <div class="text-center mb-3">
        <img src="/assets/images/logo.svg" alt="Logo" style="height:48px;" class="mb-2">
        <h1 class="h4 mb-0">Sign In</h1>
        <p class="text-muted small mb-0"><?php echo htmlspecialchars($SETTINGS['site']['name'] ?? 'Portal', ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <!-- ✅ Success message (e.g. after password reset) -->
    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success small" role="alert">
            <i class="fa-solid fa-circle-check me-1"></i>
            <?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- ❌ Error message -->
    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger small" role="alert">
            <i class="fa-solid fa-circle-exclamation me-1"></i>
            <?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- 📝 Local account login form (primary) -->
    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

        <div class="mb-3">
            <label for="username" class="form-label">Username or Email</label>
            <input type="text" class="form-control" id="username" name="username"
                   value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                   autocomplete="username" required autofocus>
        </div>

        <div class="mb-2">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password"
                   autocomplete="current-password" required>
        </div>

        <!-- 🔗 Forgot password link -->
        <div class="text-end mb-3">
            <a href="/forgot-password" class="small text-decoration-none">Forgot your password?</a>
        </div>

        <?php echo Captcha::widget(); ?>

        <button type="submit" class="btn btn-success w-100">
            <i class="fa-solid fa-right-to-bracket me-1"></i> Sign In
        </button>
    </form>

    <!-- 🔑 Microsoft 365 SSO (shown only when configured) -->
    <?php if (Auth::isMS365Configured() === true): ?>
        <div class="d-flex align-items-center my-3">
            <hr class="flex-grow-1"><span class="px-2 text-muted small">or</span><hr class="flex-grow-1">
        </div>
        <a href="/login/ms365" class="btn btn-outline-primary w-100">
            <i class="fa-brands fa-microsoft me-1"></i> Sign in with Microsoft 365
        </a>
    <?php endif; ?>

</div>

<!-- 📦 JavaScript (CDN with local fallback) -->
<?php echo Asset::bootstrapJs(); ?>

</body>
</html>
