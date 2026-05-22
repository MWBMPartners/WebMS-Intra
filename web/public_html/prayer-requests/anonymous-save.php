<?php
// Path: public_html/prayer-requests/anonymous-save.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Requests — Anonymous Public Save Handler 🙏
 * -----------------------------------------------------------------------------
 * POST endpoint for the public anonymous submission form.
 *
 * Hardening:
 *   • Session + CSRF (token issued by anonymous.php)
 *   • Captcha::verify() (Turnstile / reCAPTCHA)
 *   • RateLimiter::isBlocked() — same IP-based limiter used by login
 *   • Always pending + leadership + isAnonymous=1 (no congregation broadcast)
 *   • Always redirects to a generic success page (no enumeration / abuse signal)
 *
 * @package   Portal\PrayerRequests
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.10.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\RateLimiter;
use Portal\Core\Site;

// 🛡️ Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /prayer-requests/anonymous', true, 302);
    exit();
}

Auth::ensureSession();

// 🚦 Feature gates — bounce silently if disabled
$featureEnabled   = (App::settings('prayerRequests.enabled') ?? 'true') === 'true';
$anonymousEnabled = (App::settings('prayerRequests.allowAnonymous') ?? 'true') === 'true';
if ($featureEnabled === false || $anonymousEnabled === false) {
    header('Location: /prayer-requests/anonymous', true, 302);
    exit();
}

// 🔐 CSRF
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('PrayerRequestAnonRejected', 'Invalid CSRF on anonymous prayer-request save');
    header('Location: /prayer-requests/anonymous?err=' . urlencode('Security check failed. Please try again.'), true, 302);
    exit();
}

// 🤖 Captcha
if (Captcha::verify($_POST) === false) {
    Logger::activity('PrayerRequestAnonRejected', 'Captcha failed on anonymous prayer-request save');
    header('Location: /prayer-requests/anonymous?err=' . urlencode('Captcha verification failed. Please try again.'), true, 302);
    exit();
}

// 🚦 Rate-limit (per IP); behave silently like the forgot-password flow
if (RateLimiter::isBlocked() === true) {
    Logger::activity('PrayerRequestAnonBlocked', 'Rate-limited anonymous prayer-request submission');
    // Show success page anyway — don't tell automated abusers they're blocked
    header('Location: /prayer-requests/anonymous?submitted=1', true, 302);
    exit();
}

// 📥 Input
$name    = trim((string) ($_POST['name'] ?? ''));
$email   = trim((string) ($_POST['email'] ?? ''));
$subject = trim((string) ($_POST['subject'] ?? ''));
$body    = trim((string) ($_POST['body'] ?? ''));

// 🛡️ Validate
if ($subject === '' || $body === '') {
    header('Location: /prayer-requests/anonymous?err=' . urlencode('Subject and request text are required.'), true, 302);
    exit();
}
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    header('Location: /prayer-requests/anonymous?err=' . urlencode('Please enter a valid email address (or leave it blank).'), true, 302);
    exit();
}

// 🧹 Truncate to column limits
$name    = mb_substr($name, 0, 100);
$email   = mb_substr($email, 0, 255);
$subject = mb_substr($subject, 0, 255);
$body    = mb_substr($body, 0, 4000);

// 🌐 Capture context
$siteId = Site::id();
$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '';
if (str_contains((string) $ip, ',') === true) {
    $ip = trim(explode(',', (string) $ip)[0]);
}
$ip = mb_substr((string) $ip, 0, 45);

// 💾 Insert — always pending, leadership-only, anonymous
$visibility  = 'leadership';
$status      = 'pending';
$isAnonymous = 1;

$stmt = $mysqli->prepare(
    'INSERT INTO tblPrayerRequests '
    . '(siteID, submitterName, submitterEmail, submitterIP, subject, body, '
    . 'visibility, status, isAnonymous) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PR_ANON_INSERT_PREP', $mysqli->error, '');
    // Still redirect to success to avoid leaking server state
    header('Location: /prayer-requests/anonymous?submitted=1', true, 302);
    exit();
}

// 🪪 NULL-friendly binds: pass empty strings as NULL where appropriate
$nameOrNull  = ($name === '') ? null : $name;
$emailOrNull = ($email === '') ? null : $email;

$stmt->bind_param(
    'isssssssi',
    $siteId,
    $nameOrNull,
    $emailOrNull,
    $ip,
    $subject,
    $body,
    $visibility,
    $status,
    $isAnonymous
);
$ok = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PR_ANON_INSERT_FAIL', $mysqli->error, '');
} else {
    Logger::activity('PrayerRequestAnonSubmitted', 'Anonymous request #' . $newId . ' submitted from IP ' . $ip);
}

// ✅ Always show success — even on failure, prevent fingerprinting
header('Location: /prayer-requests/anonymous?submitted=1', true, 302);
exit();
