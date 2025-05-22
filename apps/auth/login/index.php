<?php
// Path: apps/auth/login/index.php
/**
 * -----------------------------------------------------------------------------
 * Login Page 🛂
 * -----------------------------------------------------------------------------
 * Presents Microsoft 365 SSO button and a local‐account fallback form.  Uses
 * Cloudflare Turnstile or reCAPTCHA if keys are configured.  Handles POST for
 * local logins – including CSRF, captcha, and password verification.
 * -----------------------------------------------------------------------------
 * @package    Portal\Auth
 * @author     Cambridge SDA
 * @license    MIT
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Logger;

// -----------------------------------------------------------------------------
// 1. Handle local login POST
// -----------------------------------------------------------------------------

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $errorMsg = 'Invalid session token.';
    }

    // Captcha check (if configured)
    if ($errorMsg === '') {
        $captchaOk = captchaVerify($_POST);
        if ($captchaOk === false) {
            $errorMsg = 'Captcha verification failed.';
        }
    }

    if ($errorMsg === '') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            $errorMsg = 'Please enter username and password.';
        }
    }

    if ($errorMsg === '') {
        // Validate credentials
        $stmt = $mysqli->prepare('SELECT LA.userID, LA.passwordHash, U.isActive FROM tblLocalAccounts LA JOIN tblUsers U ON U.userID = LA.userID WHERE LA.username = ? LIMIT 1');
        if ($stmt === false) {
            $errorMsg = 'Database error.';
            Logger::errorPlatform('MySQL', 'Error', 'LOGIN_PREP_FAIL', $mysqli->error, '');
        } else {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                if ($row['isActive'] == '1' && password_verify($password, $row['passwordHash'])) {
                    // Success – start session
                    Auth::csrfToken(); // ensure session
                    $_SESSION['user_id']    = $row['userID'];
                    $_SESSION['user_name']  = $username;
                    $_SESSION['user_email'] = $username; // may be an email address
                    Logger::activity('LoginLocal', 'User logged in');

                    // Redirect to dashboard or original target
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
// 2. Render page (Bootstrap 5)
// -----------------------------------------------------------------------------
?>
<!doctype html>
<html lang="en" data-bs-theme="<?php echo ($SETTINGS['features']['darkModeEnabled'] ?? 'false') === 'true' ? 'dark' : 'light'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login &bull; <?php echo htmlspecialchars($SETTINGS['site']['name']); ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <script src="/assets/js/bootstrap.bundle.min.js" defer></script>
<?php if (captchaScriptTag() !== '') { echo captchaScriptTag(); } ?>
</head>
<body class="d-flex align-items-center justify-content-center vh-100" style="background:#f8f9fa;">
<div class="card shadow p-4" style="min-width:320px;max-width:400px;">
    <h1 class="h4 mb-3 text-center">Sign in</h1>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <a href="/login/ms365" class="btn btn-primary w-100 mb-3">
        <i class="fa-brands fa-microsoft me-1"></i> Sign in with Microsoft 365
    </a>

    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">

        <div class="mb-3">
            <label for="username" class="form-label">Username or Email</label>
            <input type="text" class="form-control" id="username" name="username" required>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required>
        </div>

        <?php echo captchaWidget(); ?>

        <button type="submit" class="btn btn-success w-100">Login</button>
    </form>
</div>
</body>
</html>
<?php
// -----------------------------------------------------------------------------
// 3. Captcha helper functions
// -----------------------------------------------------------------------------

function captchaScriptTag(): string
{
    global $SETTINGS;
    if (($SETTINGS['auth']['turnstile']['siteKey'] ?? '') !== '') {
        return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }
    if (($SETTINGS['auth']['recaptcha']['siteKey'] ?? '') !== '') {
        return '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
    }
    return '';
}

function captchaWidget(): string
{
    global $SETTINGS;
    if (($SETTINGS['auth']['turnstile']['siteKey'] ?? '') !== '') {
        $siteKey = $SETTINGS['auth']['turnstile']['siteKey'];
        return '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars($siteKey) . '"></div>';
    }
    if (($SETTINGS['auth']['recaptcha']['siteKey'] ?? '') !== '') {
        $siteKey = $SETTINGS['auth']['recaptcha']['siteKey'];
        return '<div class="g-recaptcha" data-sitekey="' . htmlspecialchars($siteKey) . '"></div>';
    }
    return '';
}

function captchaVerify(array $post): bool
{
    global $SETTINGS;
    // Turnstile verification
    if (($SETTINGS['auth']['turnstile']['secretKey'] ?? '') !== '') {
        $secret = $SETTINGS['auth']['turnstile']['secretKey'];
        $token  = $post['cf-turnstile-response'] ?? '';
        if ($token === '') {
            return false;
        }
        $resp = Auth::curlPost('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        $json = json_decode($resp ?? '', true);
        return isset($json['success']) && $json['success'] === true;
    }
    // reCAPTCHA verification
    if (($SETTINGS['auth']['recaptcha']['secretKey'] ?? '') !== '') {
        $secret = $SETTINGS['auth']['recaptcha']['secretKey'];
        $token  = $post['g-recaptcha-response'] ?? '';
        if ($token === '') {
            return false;
        }
        $resp = Auth::curlPost('https://www.google.com/recaptcha/api/siteverify', [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        $json = json_decode($resp ?? '', true);
        return isset($json['success']) && $json['success'] == true;
    }
    // Captcha not configured – allow.
    return true;
}
