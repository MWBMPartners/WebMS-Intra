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
 * @license   All Rights Reserved
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
    $successMsg = t('auth.password_reset_success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 🚦 Rate-limit check (block brute-force attempts)
    if (RateLimiter::isBlocked() === true) {
        $remaining = RateLimiter::lockoutRemaining();
        $errorMsg  = t('auth.too_many_attempts', ['minutes' => $remaining]);
    }

    // 🔒 CSRF check
    if ($errorMsg === '') {
        if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
            $errorMsg = t('auth.invalid_session_token');
        }
    }

    // 🤖 Captcha check (if configured)
    if ($errorMsg === '') {
        if (Captcha::verify($_POST) === false) {
            $errorMsg = t('auth.captcha_failed');
        }
    }

    // 📝 Validate required fields
    if ($errorMsg === '') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            $errorMsg = t('auth.enter_credentials');
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
        $errorMsg = t('auth.invalid_credentials');
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
<html lang="<?php echo htmlspecialchars(\Portal\Core\I18n::locale(), ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo \Portal\Core\I18n::dir(); ?>" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars(t('auth.sign_in_title'), ENT_QUOTES, 'UTF-8'); ?> &bull; <?php echo htmlspecialchars($SETTINGS['site']['name'] ?? 'Portal', ENT_QUOTES, 'UTF-8'); ?></title>

    <!-- 🎨 Stylesheets (CDN with local fallback) -->
    <?php echo Asset::bootstrapCss(\Portal\Core\I18n::isRtl()); ?>
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
        <h1 class="h4 mb-0"><?php echo htmlspecialchars(t('auth.sign_in_title'), ENT_QUOTES, 'UTF-8'); ?></h1>
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
            <label for="username" class="form-label"><?php echo htmlspecialchars(t('auth.username_or_email'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="text" class="form-control" id="username" name="username"
                   value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                   autocomplete="username" required autofocus>
        </div>

        <div class="mb-2">
            <label for="password" class="form-label"><?php echo htmlspecialchars(t('auth.password'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="password" class="form-control" id="password" name="password"
                   autocomplete="current-password" required>
        </div>

        <!-- 🔗 Forgot password link -->
        <div class="text-end mb-3">
            <a href="/forgot-password" class="small text-decoration-none"><?php echo htmlspecialchars(t('auth.forgot_password'), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>

        <?php echo Captcha::widget(); ?>

        <button type="submit" class="btn btn-success w-100">
            <i class="fa-solid fa-right-to-bracket me-1"></i> <?php echo htmlspecialchars(t('auth.sign_in'), ENT_QUOTES, 'UTF-8'); ?>
        </button>
    </form>

    <!-- 🔑 SSO buttons (shown only when configured) -->
    <?php if (Auth::isMS365Configured() === true || Auth::isGoogleConfigured() === true): ?>
        <div class="d-flex align-items-center my-3">
            <hr class="flex-grow-1"><span class="px-2 text-muted small"><?php echo htmlspecialchars(t('auth.or'), ENT_QUOTES, 'UTF-8'); ?></span><hr class="flex-grow-1">
        </div>

        <?php if (Auth::isMS365Configured() === true): ?>
            <a href="/login/ms365" class="btn btn-outline-primary w-100 mb-2">
                <i class="fa-brands fa-microsoft me-1"></i> <?php echo htmlspecialchars(t('auth.sign_in_with_ms365'), ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endif; ?>

        <?php if (Auth::isGoogleConfigured() === true): ?>
            <a href="/login/google" class="btn btn-outline-danger w-100 mb-2">
                <i class="fa-brands fa-google me-1"></i> <?php echo htmlspecialchars(t('auth.sign_in_with_google'), ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endif; ?>
    <?php endif; ?>

    <!-- 🔐 PassKey / WebAuthn sign-in button -->
    <div id="webauthn-section" class="d-none">
        <div class="d-flex align-items-center my-3">
            <hr class="flex-grow-1"><span class="px-2 text-muted small"><?php echo htmlspecialchars(t('auth.or_use_passkey'), ENT_QUOTES, 'UTF-8'); ?></span><hr class="flex-grow-1">
        </div>
        <button type="button" class="btn btn-outline-secondary w-100" id="btnPasskeyLogin">
            <i class="fa-solid fa-fingerprint me-1"></i> <?php echo htmlspecialchars(t('auth.sign_in_with_passkey'), ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <div id="passkeyLoginStatus" class="small mt-2 text-center"></div>
    </div>

</div>

<!-- 📦 JavaScript (CDN with local fallback) -->
<?php echo Asset::bootstrapJs(); ?>

<!-- 🔐 WebAuthn login script -->
<script>
(function() {
    'use strict';

    // 📋 Only show passkey button if WebAuthn is supported
    if (!window.PublicKeyCredential) return;

    var section   = document.getElementById('webauthn-section');
    var btn       = document.getElementById('btnPasskeyLogin');
    var statusEl  = document.getElementById('passkeyLoginStatus');

    section.classList.remove('d-none');

    btn.addEventListener('click', async function() {
        statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Requesting...';
        btn.disabled = true;

        try {
            // 📋 Step 1: Get authentication options from server
            var optResp = await fetch('/login/webauthn', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'auth_options'})
            });
            var optData = await optResp.json();
            if (!optData.success) throw new Error(optData.error || 'Failed to get options');

            var options = optData.options;
            options.challenge = base64urlToBuffer(options.challenge);
            if (options.allowCredentials) {
                options.allowCredentials = options.allowCredentials.map(function(c) {
                    c.id = base64urlToBuffer(c.id);
                    return c;
                });
            }

            statusEl.innerHTML = '<i class="fa-solid fa-fingerprint fa-beat me-1"></i> Waiting for authenticator...';

            // 📋 Step 2: Get credential from authenticator
            var assertion = await navigator.credentials.get({publicKey: options});

            statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Verifying...';

            // 📋 Step 3: Send assertion to server
            var params = new URLSearchParams(window.location.search);
            var verifyResp = await fetch('/login/webauthn', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'auth_verify',
                    redirect: params.get('redirect') || '/',
                    credential: {
                        id:    assertion.id,
                        rawId: bufferToBase64url(assertion.rawId),
                        type:  assertion.type,
                        response: {
                            clientDataJSON:    bufferToBase64url(assertion.response.clientDataJSON),
                            authenticatorData: bufferToBase64url(assertion.response.authenticatorData),
                            signature:         bufferToBase64url(assertion.response.signature),
                            userHandle:        assertion.response.userHandle ? bufferToBase64url(assertion.response.userHandle) : null
                        }
                    }
                })
            });
            var verifyData = await verifyResp.json();

            if (verifyData.success) {
                window.location.href = verifyData.redirect || '/';
            } else {
                throw new Error(verifyData.error || 'Authentication failed');
            }
        } catch (err) {
            if (err.name === 'NotAllowedError') {
                statusEl.innerHTML = '<span class="text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i> Cancelled.</span>';
            } else {
                statusEl.innerHTML = '<span class="text-danger"><i class="fa-solid fa-circle-exclamation me-1"></i> ' + escapeHtml(err.message) + '</span>';
            }
        } finally {
            btn.disabled = false;
        }
    });

    function base64urlToBuffer(b64url) {
        var b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4 !== 0) b64 += '=';
        var bin = atob(b64);
        var buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf.buffer;
    }

    function bufferToBase64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})();
</script>

</body>
</html>
