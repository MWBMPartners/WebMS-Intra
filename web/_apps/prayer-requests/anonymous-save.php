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
 *   • Its own per-address limit (5 in 15 minutes), refused with a true
 *     message and the typing kept (#598; it used to borrow the SIGN-IN
 *     limiter and answer "success" while dropping the request)
 *   • Always pending + leadership + isAnonymous=1 (no congregation broadcast)
 *   • Always redirects to a generic success page (no enumeration / abuse signal)
 *   • Opt-in round-robin auto-assign to a prayer partner (#311, migration 148)
 *   • Every activity record it writes NAMES NOBODY, even for a member who is
 *     signed in (#988, Logger::activityNamingNobody())
 *
 * @package   Portal\PrayerRequests
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.5.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\AddressFingerprint;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\PrayerChain;
use Portal\Core\RateLimiter;
use Portal\Core\Site;

// 🛡️ Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('prayer-requests/anonymous'), true, 302);
    exit();
}

Auth::ensureSession();

// 🚦 Feature gates — bounce silently if disabled
$featureEnabled   = (App::settings('prayer-requests.enabled') ?? 'true') === 'true';
$anonymousEnabled = (App::settings('prayer-requests.allowAnonymous') ?? 'true') === 'true';
if ($featureEnabled === false || $anonymousEnabled === false) {
    header('Location: ' . Site::url('prayer-requests/anonymous'), true, 302);
    exit();
}

// 🔐 CSRF
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activityNamingNobody('PrayerRequestAnonRejected', 'Invalid CSRF on anonymous prayer-request save');
    header('Location: ' . Site::url('prayer-requests/anonymous') . '?err=' . urlencode('Security check failed. Please try again.'), true, 302);
    exit();
}

// 🤖 Captcha
if (Captcha::verify($_POST, 'prayer-request') === false) {
    Logger::activityNamingNobody('PrayerRequestAnonRejected', 'Captcha failed on anonymous prayer-request save');
    header('Location: ' . Site::url('prayer-requests/anonymous') . '?err=' . urlencode('Captcha verification failed. Please try again.'), true, 302);
    exit();
}

// 📥 Input. A field is text or it is nothing (#580): a hand-written request
//    can send a LIST, and `(string)` of an array is the word "Array" and a
//    warning.
$text    = static fn (mixed $value): string => is_string($value) === true ? $value : '';
$name    = trim($text($_POST['name'] ?? ''));
$email   = trim($text($_POST['email'] ?? ''));
$subject = trim($text($_POST['subject'] ?? ''));
$body    = trim($text($_POST['body'] ?? ''));

// 🚦 This form's OWN limit: five requests from one address in 15 minutes,
//    refused with a TRUE message (#598 item 4).
//
//    WHAT WAS WRONG. This asked RateLimiter::isBlocked() — the SIGN-IN tally,
//    five failed sign-ins from this address — and on a trip answered "thank
//    you, our leadership team will be praying for you" and threw the request
//    away. Behind a shared address (a church hall, a school, a mobile network)
//    one person mistyping a password made every visitor's prayer request
//    vanish, with no sign to them or to anybody. A failed sign-in says nothing
//    about a prayer request, and the form had no limit of its own at all.
//
//    NOW: its own count, of requests actually saved, by a salted fingerprint
//    of the address (#712). Over it, the visitor is told the request has NOT
//    been sent and how long to wait, and what they typed is kept for the form
//    (anonymous.php puts it back) so a refusal does not also cost their words.
//    Telling a flood-sender it is limited costs nothing: the limit stops them
//    whether they know it or not.
$anonFamily = 'prayer:anon:';
$anonBucket = $anonFamily . AddressFingerprint::of(RateLimiter::clientIp());
$anonMax    = 5;
$anonWindow = 900; // 15 minutes, in seconds
if (RateLimiter::tooMany($anonBucket, $anonMax, $anonWindow) === true) {
    Logger::activityNamingNobody('PrayerRequestAnonBlocked', 'Too many anonymous prayer requests from this connection; this one was NOT saved, and the visitor was told so');
    $_SESSION['pr_anon_kept'] = ['name' => $name, 'email' => $email, 'subject' => $subject, 'body' => $body];
    $waitMinutes = max(1, (int) ceil(RateLimiter::retryAfter($anonBucket, $anonMax, $anonWindow) / 60));
    header(
        'Location: ' . Site::url('prayer-requests/anonymous') . '?err=' . urlencode(
            'Your prayer request has NOT been sent: too many have been sent from this connection in the last 15 minutes. '
            . 'What you typed is still below. Please try again in ' . $waitMinutes . ' minute' . ($waitMinutes === 1 ? '' : 's') . '.'
        ),
        true,
        302
    );
    exit();
}

// 🛡️ Validate
if ($subject === '' || $body === '') {
    header('Location: ' . Site::url('prayer-requests/anonymous') . '?err=' . urlencode('Subject and request text are required.'), true, 302);
    exit();
}
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    header('Location: ' . Site::url('prayer-requests/anonymous') . '?err=' . urlencode('Please enter a valid email address (or leave it blank).'), true, 302);
    exit();
}

// 🧹 Truncate to column limits
$name    = mb_substr($name, 0, 100);
$email   = mb_substr($email, 0, 255);
$subject = mb_substr($subject, 0, 255);
$body    = mb_substr($body, 0, 4000);

// 🌐 Capture context
$siteId = Site::id();
// 🛑 This used to read the Cloudflare and X-Forwarded-For headers directly
//    and believe them. Anybody can send those headers, so the address could
//    be anything a visitor chose. RateLimiter::clientIp() is the ONE place in
//    the portal that decides which address to believe: it trusts those
//    headers only when the request really arrived through a proxy listed in
//    portal.trustedProxies, and otherwise uses the address the connection
//    actually came from. Here the address is stored on an anonymous prayer
//    request, which is only worth keeping if it is true - it is what
//    moderators rely on to spot one person flooding the form.
$ip = RateLimiter::clientIp();
$ip = mb_substr((string) $ip, 0, 45);

// 🚦 Counted only now, once the request has passed every check above and is
//    about to be saved, so a typing mistake does not use up the allowance.
//    The family prefix lets a later hit tidy stale buckets (#712).
RateLimiter::recordHit($anonBucket, $anonWindow, $anonFamily);

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
    header('Location: ' . Site::url('prayer-requests/anonymous') . '?submitted=1', true, 302);
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
    // 🕶️ #988: this record NAMES NOBODY, whoever sent the request. The form
    //    is the anonymous one, so the record of it must not be the thing that
    //    says who sent it. Logger::activity() would put the session's account
    //    in `userID` when none is passed (#626), and also the session's
    //    fingerprint and facts, the network address and the browser name -
    //    so a member who used this form while signed in got a record tying
    //    the "anonymous" request to their account, readable by the
    //    organisation's administrators. Logger::activityNamingNobody() writes
    //    the type and these words and nothing else.
    //
    //    The same goes for the three refusals above (form token, captcha, too
    //    many): each says somebody tried to send an anonymous request, and
    //    must not say who.
    //
    //    #933: never the visitor's network address in the words. It is kept
    //    in ONE place, the request's own row (`submitterIP`, D-176), and an
    //    erasure request filed by the e-mail address given with it empties it
    //    there (GdprEraser::executeVisitorAddress(), #1009).
    Logger::activityNamingNobody('PrayerRequestAnonSubmitted', 'Anonymous request #' . $newId . ' submitted');

    // 🙏 Opt-in round-robin auto-assign (#311, migration 148) — no-op unless
    // prayer-requests.autoAssign = 'true'; swallows its own failures.
    // #988: true, so the record of the assignment names nobody either.
    PrayerChain::maybeAutoAssign($siteId, $newId, true);
}

// ✅ Always show success — even on failure, prevent fingerprinting
header('Location: ' . Site::url('prayer-requests/anonymous') . '?submitted=1', true, 302);
exit();
