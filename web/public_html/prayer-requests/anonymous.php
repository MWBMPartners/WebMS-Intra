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
 * @version   0.10.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Captcha;

// 🛡️ Always start a session so CSRF tokens are issued for this anonymous form
Auth::ensureSession();

// 🚦 Feature gates
$featureEnabled   = (App::settings('prayerRequests.enabled') ?? 'true') === 'true';
$anonymousEnabled = (App::settings('prayerRequests.allowAnonymous') ?? 'true') === 'true';

$siteName  = (string) (App::settings('site.name') ?? 'Portal');
$submitted = isset($_GET['submitted']) === true && $_GET['submitted'] === '1';
$flashErr  = (string) ($_GET['err'] ?? '');
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Submit a Prayer Request &bull; <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>

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
<div class="card shadow p-4" style="min-width:320px;max-width:560px;width:100%;">

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
        <a href="/" class="btn btn-outline-secondary w-100">
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

        <form method="post" action="/prayer-requests/anonymous/save" novalidate>
            <input type="hidden" name="csrf_token"
                   value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="mb-3">
                <label for="name" class="form-label small">Your Name <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control form-control-sm" id="name" name="name"
                       maxlength="100" autocomplete="name">
            </div>

            <div class="mb-3">
                <label for="email" class="form-label small">Your Email <span class="text-muted">(optional, for follow-up)</span></label>
                <input type="email" class="form-control form-control-sm" id="email" name="email"
                       maxlength="255" autocomplete="email">
            </div>

            <div class="mb-3">
                <label for="subject" class="form-label small">
                    Subject <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control" id="subject" name="subject"
                       maxlength="255" required
                       placeholder="A short title">
            </div>

            <div class="mb-3">
                <label for="body" class="form-label small">
                    Request <span class="text-danger">*</span>
                </label>
                <textarea class="form-control" id="body" name="body" rows="5"
                          maxlength="4000" required
                          placeholder="Share what you'd like prayer for…"></textarea>
            </div>

            <?php echo Captcha::widget(); ?>

            <button type="submit" class="btn btn-success w-100 mb-2">
                <i class="fa-solid fa-paper-plane me-1"></i> Submit Request
            </button>

            <p class="small text-muted text-center mb-0">
                Have an account? <a href="/login">Sign in</a> for more options.
            </p>
        </form>

    <?php endif; ?>

</div>

<!-- 📦 JavaScript (CDN with local fallback) -->
<?php echo Asset::bootstrapJs(); ?>
</body>
</html>
