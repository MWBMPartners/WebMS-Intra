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

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\RateLimiter;
use Portal\Core\Router;
use Portal\Core\Site;

// -----------------------------------------------------------------------------
// 1. 🔀 If already logged in, redirect to dashboard
// -----------------------------------------------------------------------------

if (Auth::check() === true) {
    $target = Auth::safeRedirectUrl($_GET['redirect'] ?? Site::url(''));
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

    // 🧾 Who refused this try. The two flags decide which record, if any, this
    //    page writes at the end (see "One record per refused try" below).
    $addressBlocked = false; // this address already has too many failed tries
    $signInRefused  = false; // Auth::loginLocal() said no, and wrote its own record

    // 🧵 A form field is text or it is nothing (#580). A browser sends text;
    //    a request written by hand can send a LIST instead ("username[]=x"),
    //    and handing a list to trim() stopped this page with an error 500.
    //    Anything that is not text is read as empty and answered like any
    //    other empty or wrong value.
    $text = static fn (mixed $value): string => is_string($value) === true ? $value : '';

    // 🚦 Rate-limit check (block brute-force attempts)
    if (RateLimiter::isBlocked() === true) {
        // At least 1: in the last second of a block the time left rounds to
        // 0, and "try again in 0 minute(s)" was shown to somebody who had
        // just been refused (#580).
        $remaining      = max(1, RateLimiter::lockoutRemaining());
        $errorMsg       = t('auth.too_many_attempts', ['minutes' => $remaining]);
        $addressBlocked = true;
    }

    // 🔒 CSRF check
    if ($errorMsg === '') {
        if (Auth::verifyCsrf($text($_POST['csrf_token'] ?? '')) === false) {
            $errorMsg = t('auth.invalid_session_token');
        }
    }

    // 🤖 Captcha check (if configured)
    if ($errorMsg === '') {
        if (Captcha::verify($_POST, Captcha::FOR_SIGN_IN) === false) {
            $errorMsg = t('auth.captcha_failed');
        }
    }

    // 📝 Validate required fields
    if ($errorMsg === '') {
        $username = trim($text($_POST['username'] ?? ''));
        $password = $text($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $errorMsg = t('auth.enter_credentials');
        }
    }

    // 🔑 Authenticate via Auth::loginLocal() — handles tblLocalAccounts lookup,
    // password verification, session creation, and lastLogin update
    if ($errorMsg === '') {
        if (Auth::loginLocal($username, $password) === true) {
            $loggedInUserId = (int) ($_SESSION['user_id'] ?? 0);

            // 🔐 2FA gate. If the user has TOTP enabled AND this browser
            // isn't already a trusted device (post-#92 / #v1.0), demote the
            // session to "pending 2FA" and route to /auth/2fa/verify. The
            // verify handler will promote 2fa_user_id back to user_id on
            // a successful challenge.
            if ($loggedInUserId > 0
                && Auth::userRequires2fa($loggedInUserId) === true
                && Auth::deviceIsTrusted($loggedInUserId) === false
            ) {
                $_SESSION['2fa_user_id']   = $loggedInUserId;
                $_SESSION['login_redirect'] = Auth::safeRedirectUrl($_GET['redirect'] ?? Site::url(''));
                unset($_SESSION['user_id'], $_SESSION['2fa_passed']);
                header('Location: ' . Site::url('auth/2fa/verify'), true, 302);
                exit();
            }

            // ✅ No 2FA required (or device already trusted) — go straight in.
            $target = Auth::safeRedirectUrl($_GET['redirect'] ?? Site::url(''));
            header('Location: ' . $target, true, 302);
            exit();
        }
        // 🚦 Refused. Say WHY truthfully (#580): a limit that was reached, or
        //    a wrong name or password.
        //
        //    WHAT WAS WRONG. When the limit for one NAME was reached (ten
        //    failures, from any number of addresses), this said "Invalid
        //    credentials." to the account's real owner typing the RIGHT
        //    password, with nothing to say that waiting would help.
        //
        //    Saying "Too many failed attempts" gives nothing away: a made-up
        //    name is counted, blocked and answered in exactly the same way.
        //    The same message covers a try that Auth::loginLocal() found the
        //    address blocked for (tries sent at the same moment get past the
        //    check at the top of this page, and are caught there).
        $waitMinutes   = Auth::localSignInWaitMinutes();
        $errorMsg      = $waitMinutes > 0
            ? t('auth.too_many_attempts', ['minutes' => $waitMinutes])
            : t('auth.invalid_credentials');
        $signInRefused = true;
    }

    // 🧾 One record per refused try (#580).
    //
    //    WHAT WAS WRONG. This page used to write a 'LoginFailed' record for
    //    EVERY refusal. Two faults came out of that one line:
    //
    //    1. A wrong password was counted twice. Auth::loginLocal() already
    //       writes a 'LoginFailed' record when it refuses (and that one names
    //       the person, which the per-name limit depends on). This page then
    //       wrote a second. The limit is 5 records in 15 minutes for one
    //       address, so THREE wrong passwords blocked an address, not five.
    //    2. A try made while the address was already blocked wrote one more
    //       'LoginFailed' record. Each one pushed the end of the block further
    //       away, so somebody who kept trying stayed blocked, and the "try
    //       again in N minutes" on screen promised less than the real wait.
    //
    //    WHAT IT DOES NOW.
    //    - Auth::loginLocal() refused: nothing is written here. It has already
    //      written the record for this try: 'LoginFailed' for an unknown name
    //      or a wrong password, 'LoginBlocked' when a limit was reached (the
    //      name's own, or this address's when tries arrive together).
    //    - The address is already blocked: 'LoginBlocked', the name
    //      Auth::loginLocal() uses for the same thing. RateLimiter counts
    //      'LoginFailed' only, so this record is kept for the audit trail and
    //      does not make the block last longer. No password was checked.
    //    - This page's own refusals (an expired form, a failed captcha, an
    //      empty field): one 'LoginFailed' record, exactly as before.
    //
    //    WHEN A BLOCK ENDS. The address is blocked while five 'LoginFailed'
    //    records are less than 15 minutes old (both numbers are settings), so
    //    it ends when the OLDEST of the five turns 15 minutes old. Five wrong
    //    passwords typed one after another therefore block for 15 minutes; if
    //    they were spread over ten minutes, the block lasts the five that are
    //    left. That is the figure RateLimiter::lockoutRemaining() shows on
    //    screen, and it is now true.
    //
    //    REJECTED: taking the record out of Auth::loginLocal() instead. That
    //    record is the one that names the person, so the per-name limit (ten
    //    wrong passwords for one name, from any number of addresses) would
    //    have stopped working.
    if ($errorMsg !== '') {
        if ($addressBlocked === true) {
            Logger::activity('LoginBlocked', 'Sign-in try refused: too many failed tries from this address. No password was checked.');
        } elseif ($signInRefused === false) {
            Logger::activity('LoginFailed', $errorMsg);
        }
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
        } else if (t === 'auto' || t === null) {
            var prefersDark = window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
        }
    })();
    </script>

    <!-- 🤖 Captcha script (if configured) -->
    <?php echo Captcha::scriptTag(); ?>

</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 py-4">
<div class="card shadow p-4" style="min-width:min(320px,100%);max-width:420px;width:100%;">

    <!-- 🏷️ Site branding -->
    <div class="text-center mb-3">
        <img src="/assets/images/logo.svg" alt="Logo" style="height:48px;" class="mb-2">
        <h1 class="h4 mb-0"><?php echo htmlspecialchars(t('auth.sign_in_title'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-muted small mb-0"><?php echo htmlspecialchars($SETTINGS['site']['name'] ?? 'Portal', ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <!-- ✅ Success message (e.g. after password reset) -->
    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success small" role="alert" aria-live="polite">
            <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
            <?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- ❌ Error message -->
    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger small" role="alert" aria-live="assertive">
            <i class="fa-solid fa-circle-exclamation me-1" aria-hidden="true"></i>
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
                   autocomplete="username" required autofocus
                   autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="email">
        </div>

        <div class="mb-2">
            <label for="password" class="form-label"><?php echo htmlspecialchars(t('auth.password'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="password" class="form-control" id="password" name="password"
                   autocomplete="current-password" required>
        </div>

        <!-- 🔗 Forgot password link -->
        <div class="text-end mb-3">
            <a href="<?php echo htmlspecialchars(Site::url('forgot-password'), ENT_QUOTES, 'UTF-8'); ?>" class="small text-decoration-none"><?php echo htmlspecialchars(t('auth.forgot_password'), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>

        <?php echo Captcha::widget(Captcha::FOR_SIGN_IN); ?>

        <button type="submit" class="btn btn-success w-100">
            <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> <?php echo htmlspecialchars(t('auth.sign_in'), ENT_QUOTES, 'UTF-8'); ?>
        </button>
    </form>

    <!-- 🔑 SSO buttons (shown only when configured) -->
    <?php if (Auth::isMS365Configured() === true || Auth::isGoogleConfigured() === true): ?>
        <div class="d-flex align-items-center my-3">
            <hr class="flex-grow-1"><span class="px-2 text-muted small"><?php echo htmlspecialchars(t('auth.or'), ENT_QUOTES, 'UTF-8'); ?></span><hr class="flex-grow-1">
        </div>

        <?php if (Auth::isMS365Configured() === true): ?>
            <a href="<?php echo htmlspecialchars(Site::url('login/ms365'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary w-100 mb-2">
                <i class="fa-brands fa-microsoft me-1" aria-hidden="true"></i> <?php echo htmlspecialchars(t('auth.sign_in_with_ms365'), ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endif; ?>

        <?php if (Auth::isGoogleConfigured() === true): ?>
            <a href="<?php echo htmlspecialchars(Site::url('login/google'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-danger w-100 mb-2">
                <i class="fa-brands fa-google me-1" aria-hidden="true"></i> <?php echo htmlspecialchars(t('auth.sign_in_with_google'), ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endif; ?>
    <?php endif; ?>

    <!-- 🔐 PassKey / WebAuthn sign-in button -->
    <div id="webauthn-section" class="d-none">
        <div class="d-flex align-items-center my-3">
            <hr class="flex-grow-1"><span class="px-2 text-muted small"><?php echo htmlspecialchars(t('auth.or_use_passkey'), ENT_QUOTES, 'UTF-8'); ?></span><hr class="flex-grow-1">
        </div>
        <button type="button" class="btn btn-outline-secondary w-100" id="btnPasskeyLogin">
            <i class="fa-solid fa-fingerprint me-1" aria-hidden="true"></i> <?php echo htmlspecialchars(t('auth.sign_in_with_passkey'), ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <div id="passkeyLoginStatus" class="small mt-2 text-center"></div>
    </div>

    <!-- 📜 Authorised-use notice (#221). Implied-consent terms required for
         all sign-in paths (local, MS365, Google, passkey) — every route
         lands on this page, so the notice is enforced for everyone. -->
    <div class="alert alert-secondary small mt-4 mb-0 py-2 px-3" role="note">
        <strong><?php echo htmlspecialchars(t('auth.terms_notice_heading'), ENT_QUOTES, 'UTF-8'); ?></strong>
        <span class="text-muted">
            <?php echo htmlspecialchars(
                t('auth.terms_notice', [
                    'site_name' => $SETTINGS['site']['name'] ?? 'This portal',
                ]),
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </span>
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
            var optResp = await fetch('<?php echo Router::urlForScript('login/webauthn'); ?>', {
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
<?php
// 🏢 The two fall-backs below (after "||") used to be a bare slash. Where organisations are told
//    apart by the first part of the address, that is the DEFAULT organisation's front page, not
//    this one's. They are now built by Router::urlForScript() (#536): this organisation's own
//    front page, which with no first part in use is still exactly the same one character.
//    WHAT THIS DID NOT CHANGE: where anybody lands today. login/webauthn.php already hands the
//    address it is sent to Auth::safeRedirectUrl(), which refuses a bare slash under a first
//    part and answers with the organisation's front page, and it always names an address when
//    the sign-in works. So neither fall-back is trusted, and the second is not reached at all;
//    they are written this way so the page never names an address outside its organisation.
//    (A PHP comment whose tags start and end a line prints nothing, so the page is unchanged.)
?>
            var params = new URLSearchParams(window.location.search);
            var verifyResp = await fetch('<?php echo Router::urlForScript('login/webauthn'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'auth_verify',
                    redirect: params.get('redirect') || '<?php echo Router::urlForScript(''); ?>',
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
                window.location.href = verifyData.redirect || '<?php echo Router::urlForScript(''); ?>';
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
