<?php
// Path: apps/auth/login/index.php
/**
 * -----------------------------------------------------------------------------
 * Login Page 🛂
 * -----------------------------------------------------------------------------
 * Presents Microsoft 365 SSO button and a local-account fallback form.  Uses
 * Cloudflare Turnstile or reCAPTCHA if keys are configured.  Handles POST for
 * local logins -- including CSRF, captcha, rate limiting, and password
 * verification.
 *
 * This page has its own centred layout (no navbar, just a card) and does NOT
 * use the header.php / footer.php templates.  It keeps its own require of
 * bootstrap.php because login is NOT routed through the Router (it is handled
 * as a tblRoutes entry with isProtected=0).
 * -----------------------------------------------------------------------------
 * @package    Portal\Auth
 * @author     Cambridge SDA
 * @license    MIT
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\RateLimiter;

// -----------------------------------------------------------------------------
// 1. 🛡️ Handle local login POST
// -----------------------------------------------------------------------------

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 🚦 Rate-limit check (block brute-force attempts)
    if (RateLimiter::isBlocked() === true) {
        $remaining = RateLimiter::lockoutRemaining();
        $errorMsg  = 'Too many failed attempts. Please try again in ' . $remaining . ' minute(s).';
    }

    // 🔒 CSRF check
    if ($errorMsg === '') {
        if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
            $errorMsg = 'Invalid session token.';
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
            $errorMsg = 'Please enter username and password.';
        }
    }

    // 🔑 Validate credentials against database
    if ($errorMsg === '') {
        $stmt = $mysqli->prepare(
            'SELECT LA.userID, LA.passwordHash, U.isActive '
            . 'FROM tblLocalAccounts LA '
            . 'JOIN tblUsers U ON U.userID = LA.userID '
            . 'WHERE LA.username = ? LIMIT 1'
        );
        if ($stmt === false) {
            $errorMsg = 'Database error.';
            Logger::errorPlatform('MySQL', 'Error', 'LOGIN_PREP_FAIL', $mysqli->error, '');
        } else {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                if ($row['isActive'] == '1' && password_verify($password, $row['passwordHash']) === true) {
                    // ✅ Success -- start session
                    Auth::csrfToken(); // ensure session
                    $_SESSION['user_id']    = $row['userID'];
                    $_SESSION['user_name']  = $username;
                    $_SESSION['user_email'] = $username; // may be an email address
                    Logger::activity('LoginLocal', 'User logged in');

                    // 🔀 Redirect to dashboard or original target
                    $target = $_GET['redirect'] ?? '/';
                    header('Location: ' . $target, true, 302);
                    exit();
                }
            }
            $stmt->close();
            $errorMsg = 'Invalid credentials.';
        }
    }

    if ($errorMsg !== '') {
        Logger::activity('LoginFailed', $errorMsg);
    }
}

// -----------------------------------------------------------------------------
// 2. 🎨 Render page (Bootstrap 5)
// -----------------------------------------------------------------------------
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login &bull; <?php echo htmlspecialchars($SETTINGS['site']['name'] ?? 'Portal', ENT_QUOTES, 'UTF-8'); ?></title>

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
<body class="d-flex align-items-center justify-content-center vh-100" style="background:#f8f9fa;">
<div class="card shadow p-4" style="min-width:320px;max-width:400px;">
    <h1 class="h4 mb-3 text-center">Sign in</h1>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <!-- 🔑 Microsoft 365 SSO button -->
    <a href="/login/ms365" class="btn btn-primary w-100 mb-3">
        <i class="fa-brands fa-microsoft me-1"></i> Sign in with Microsoft 365
    </a>

    <!-- 📝 Local account login form -->
    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

        <div class="mb-3">
            <label for="username" class="form-label">Username or Email</label>
            <input type="text" class="form-control" id="username" name="username" required>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required>
        </div>

        <?php echo Captcha::widget(); ?>

        <button type="submit" class="btn btn-success w-100">Login</button>
    </form>
</div>

<!-- 📦 JavaScript (CDN with local fallback) -->
<?php echo Asset::bootstrapJs(); ?>

</body>
</html>
