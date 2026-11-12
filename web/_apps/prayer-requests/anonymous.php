<?php
// Path: public_html/prayer-requests/anonymous.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Requests — Anonymous Public Submission Form 🙏
 * -----------------------------------------------------------------------------
 * Public route (no login) that lets visitors submit a prayer request.
 * Uses a standalone layout (similar to the login / forgot-password pages)
 * and is protected by Turnstile/reCAPTCHA + RateLimiter.
 *
 * Anonymous submissions are forced to:
 *   • visibility = leadership (members opt-in to congregation feed only when
 *     logged in — anonymous folks don't get a public broadcast right)
 *   • isAnonymous = 1
 *   • status = pending (always moderated)
 *
 * @package   Portal\PrayerRequests
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.11.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Site;

// 🛡️ Always start a session so CSRF tokens are issued for this anonymous form
Auth::ensureSession();

// 🚦 Feature gates
$featureEnabled   = (App::settings('prayer-requests.enabled') ?? 'true') === 'true';
$anonymousEnabled = (App::settings('prayer-requests.allowAnonymous') ?? 'true') === 'true';

$siteName  = (string) (App::settings('site.name') ?? 'Portal');
$submitted = isset($_GET['submitted']) === true && $_GET['submitted'] === '1';
$flashErr  = (string) ($_GET['err'] ?? '');

// ✍️ What the visitor typed, kept by the save handler when it REFUSED the
//    request because too many came from this connection (#598 item 4), so a
//    refusal does not also cost them their words. Taken once and removed. Each
//    value is text or it is nothing (a session is ours, but a value of the
//    wrong type must never reach htmlspecialchars()).
$kept = $_SESSION['pr_anon_kept'] ?? [];
unset($_SESSION['pr_anon_kept']);
$keptText = static fn (string $field): string => (is_array($kept) === true && is_string($kept[$field] ?? null) === true)
    ? $kept[$field]
    : '';

// 🕶️ #988: a member who is SIGNED IN may still use this form, and is told
//    what that means, because the page's whole promise is anonymity. Since
//    #988 the request and every activity record about it name nobody (the
//    request row never held an account; the records used to, through
//    Logger::activity()'s session fallback). What the sentence must NOT
//    promise is that nobody could ever work it out: the request keeps the
//    network address it came from (D-176), the same as on the member's own
//    sign-in records, so it says plainly that somebody with access to the
//    portal's records could match the two. It also says what follows from the
//    request NOT being linked: the member's own erasure (which finds their
//    requests by `submitterID`) cannot reach it, and only a request filed by
//    the e-mail address given with it can (D-176, #1009) - said BEFORE they
//    send, because after is too late to add an address. A visitor without an
//    account is told the same thing about erasure, in its own sentence. (A
//    visitor who is not signed in may still have an account; the request is
//    not linked to it either way.) Both are pinned as rendered by section N
//    of tools/erasure-pages-selftest.php.
$signedIn = Auth::check();
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Submit a Prayer Request &bull; <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php
    // 🔗 #569 — the description, canonical address and preview card.
    //    This page prints its own <head>, so it includes the shared
    //    file itself rather than inheriting it from header.php.
    $pageTitle       = 'Submit a prayer request';
    $pageDescription = 'Send a prayer request. You do not have to give your name.';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'head-meta.php';
    ?>


    <!-- 🚫 Public form — don't index in search engines -->
    <meta name="robots" content="noindex, nofollow">

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
<div class="card shadow p-4" style="min-width:min(320px,100%);max-width:560px;width:100%;">

    <!-- 🏷️ Header -->
    <div class="text-center mb-3">
        <i class="fa-solid fa-hands-praying fa-2x text-muted mb-2"></i>
        <h1 class="h4 mb-1">Submit a Prayer Request</h1>
        <p class="text-muted small mb-0">
            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    </div>

    <?php if ($featureEnabled === false || $anonymousEnabled === false): ?>
        <div class="alert alert-warning small mb-0">
            <i class="fa-solid fa-circle-info me-1"></i>
            Anonymous prayer-request submissions are not currently being accepted on this site.
        </div>
    <?php elseif ($submitted === true): ?>
        <div class="alert alert-success small" role="alert">
            <i class="fa-solid fa-circle-check me-1"></i>
            Thank you for sharing your request — our leadership team will be praying for you.
        </div>
        <a href="<?php echo htmlspecialchars(Site::url(''), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary w-100">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Home
        </a>
    <?php else: ?>

        <?php if ($flashErr !== ''): ?>
            <div class="alert alert-danger small">
                <i class="fa-solid fa-circle-exclamation me-1"></i>
                <?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <p class="text-muted small">
            Use this form if you don't have an account.  Requests submitted here go
            <strong>only to the leadership team</strong> for confidential prayer.
        </p>

        <?php if ($signedIn === true): ?>
            <div class="alert alert-info small" data-anonymous-signed-in>
                <i class="fa-solid fa-user-secret me-1"></i>
                You are signed in, but a request sent from this form is <strong>not linked to your account</strong>:
                the leadership team sees only the name and e-mail address you type below, if any.
                It does keep the network address it was sent from, and anyone with access to the portal's records
                could compare that address with the one stored each time you sign in, so it is not anonymous to them.
                Because it is not linked to your account, asking for your account to be deleted will not remove it:
                to have it erased, ask the organisation, which can find it only if you give an e-mail address below.
                To send a request under your own name, use
                <a href="<?php echo htmlspecialchars(Site::url('prayer-requests/submit'), ENT_QUOTES, 'UTF-8'); ?>" class="alert-link">the members' prayer request form</a>.
            </div>
        <?php else: ?>
            <p class="small text-muted" data-anonymous-erasure-note>
                This form keeps the network address your request is sent from. If you later want your request and
                your details erased, ask the organisation: it can find your request only if you give an e-mail
                address below.
            </p>
        <?php endif; ?>

        <form method="post" action="<?php echo htmlspecialchars(Site::url('prayer-requests/anonymous/save'), ENT_QUOTES, 'UTF-8'); ?>" novalidate>
            <input type="hidden" name="csrf_token"
                   value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="mb-3">
                <label for="name" class="form-label small">Your Name <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control form-control-sm" id="name" name="name"
                       maxlength="100" autocomplete="name"
                       value="<?php echo htmlspecialchars($keptText('name'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="mb-3">
                <label for="email" class="form-label small">Your Email <span class="text-muted">(optional, for follow-up)</span></label>
                <input type="email" class="form-control form-control-sm" id="email" name="email"
                       maxlength="255" autocomplete="email"
                       value="<?php echo htmlspecialchars($keptText('email'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="mb-3">
                <label for="subject" class="form-label small">
                    Subject <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control" id="subject" name="subject"
                       maxlength="255" required
                       placeholder="A short title"
                       value="<?php echo htmlspecialchars($keptText('subject'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="mb-3">
                <label for="body" class="form-label small">
                    Request <span class="text-danger">*</span>
                </label>
                <textarea class="form-control" id="body" name="body" rows="5"
                          maxlength="4000" required
                          placeholder="Share what you'd like prayer for…"><?php echo htmlspecialchars($keptText('body'), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <?php echo Captcha::widget(); ?>

            <button type="submit" class="btn btn-success w-100 mb-2">
                <i class="fa-solid fa-paper-plane me-1"></i> Submit Request
            </button>

            <p class="small text-muted text-center mb-0">
                Have an account? <a href="<?php echo htmlspecialchars(Site::url('login'), ENT_QUOTES, 'UTF-8'); ?>">Sign in</a> for more options.
            </p>
        </form>

    <?php endif; ?>

</div>

<!-- 📦 JavaScript (CDN with local fallback) -->
<?php echo Asset::bootstrapJs(); ?>
</body>
</html>
