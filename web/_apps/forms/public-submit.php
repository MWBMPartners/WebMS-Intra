<?php
// Path: _apps/forms/public-submit.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Public Submission Handler (public POST) 🌐🛡️
 * -----------------------------------------------------------------------------
 * PUBLIC POST handler for public.php's form. Reachable by anonymous visitors
 * by design (the seeded `forms/public-submit` route has `isProtected = 0`)
 * — this file must assume EVERY input is hostile, exactly like
 * `prayer-requests/anonymous-save.php` and `assets/found-save.php`.
 *
 * GATE CHAIN (read in order — every step can end the request):
 *   1. Method must be POST.
 *   2. Session started (CSRF token needs one).
 *   3. Re-resolve `formToken` and re-run ALL SIX of public.php's own gates
 *      (token exists / audience / published / open / site allowPublic /
 *      site forms.enabled) — never trust that the visitor arrived from a
 *      legitimately-gated GET. Any failure = the SAME uniform 404
 *      public.php itself would show for an unknown token.
 *   4. Honeypot (`website`) — a filled value means a bot. Behave EXACTLY
 *      like a genuine success (same redirect, same flash) — no signal to
 *      an automated submitter, no row written.
 *   5. CSRF.
 *   6. Captcha (gracefully passes through when unconfigured).
 *   7. Rate limits — `RateLimiter::isBlocked()` (fake-success on trip,
 *      prayer-requests precedent — never tell an abuser), then a per-IP
 *      sliding-window bucket (5 / 15 min).
 *   8. Field validation — errors flash back to `/f/{token}` with old values.
 *   9. IP capture (CF-Connecting-IP → X-Forwarded-For first hop →
 *      REMOTE_ADDR), capped to 45 chars.
 *  10. `FormEngine::saveResponse()` — the FORM'S OWN siteID, never
 *      `Site::id()` (no ambient site context on this public route).
 *  11. Always redirect to the SAME success page, even on a DB failure — no
 *      server-state oracle (prayer-requests precedent).
 *
 * @package   Portal\Forms
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/153
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\FormEngine;
use Portal\Core\Logger;
use Portal\Core\RateLimiter;
use Portal\Core\Router;

// 🚦 1. Method gate.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /', true, 303);
    exit();
}

// 🔓 2. Public — session started so the CSRF token public.php issued can be
// verified, and so a flash message/bag can be left for the redirect back.
Auth::ensureSession();

// 🔍 3. Re-resolve the token and re-run ALL SIX of public.php's own gates.
$token = trim((string) ($_POST['formToken'] ?? ''));
if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    Router::renderError(404);
    return;
}

$form = FormEngine::getFormByToken($token);
if ($form === null) {
    Router::renderError(404);
    return;
}

$formSiteId = (int) $form['siteID'];
$audienceOk = in_array((string) $form['audience'], ['public', 'both'], true);
$isPublished = (string) $form['status'] === 'published';
$isOpen = FormEngine::isOpen($form);
$sitePublicOn = (string) (App::settingForSite('forms.allowPublic', $formSiteId) ?? 'false') === 'true';
$appEnabledOn = in_array(
    (string) (App::settingForSite('forms.enabled', $formSiteId) ?? '0'),
    ['true', '1'],
    true
);

if ($audienceOk === false || $isPublished === false || $isOpen === false || $sitePublicOn === false || $appEnabledOn === false) {
    Router::renderError(404);
    return;
}

$formId = (int) $form['formID'];
$backToForm = '/f/' . $token;

/**
 * Redirect back to the public fill page with a generic `?err=` flash, then
 * stop the request. Local closure — mirrors assets/found-save.php's
 * `$bounce` convention.
 */
$bounce = static function (string $msg) use ($backToForm): never {
    header('Location: ' . $backToForm . '?err=' . urlencode($msg), true, 303);
    exit();
};

// 🎉 The success redirect every "let the visitor believe it worked" path
// (a genuine success AND the honeypot trap below) uses.
$successRedirect = static function () use ($backToForm): never {
    header('Location: ' . $backToForm . '?submitted=1', true, 303);
    exit();
};

// 🕳️ 4. Honeypot — a filled `website` field means a bot. Behave EXACTLY
// like a genuine success. No row is written.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    Logger::activity('FormPublicHoneypot', 'Honeypot tripped on public-submit for form #' . $formId);
    $successRedirect();
}

// 🔐 5. CSRF.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('FormPublicRejected', 'Invalid CSRF on public-submit for form #' . $formId);
    $bounce('Security check failed — please reload the page and try again.');
}

// 🤖 6. Captcha — gracefully passes through when no provider is configured.
if (Captcha::verify($_POST) === false) {
    Logger::activity('FormPublicRejected', 'Captcha failed on public-submit for form #' . $formId);
    $bounce('Verification failed — please try again.');
}

// 🚦 7. Rate limits. isBlocked() trip → FAKE SUCCESS (never tell an
// abuser); then a per-IP sliding-window bucket, 5 submissions / 15 min.
if (RateLimiter::isBlocked() === true) {
    Logger::activity('FormPublicBlocked', 'Rate-limited (isBlocked) public-submit for form #' . $formId);
    $successRedirect();
}
$rateBucket = 'forms:public-submit:' . hash('sha256', RateLimiter::clientIp());
if (RateLimiter::tooMany($rateBucket, 5, 900) === true) {
    Logger::activity('FormPublicBlocked', 'Rate-limited (tooMany) public-submit for form #' . $formId);
    $bounce('Too many submissions from this connection — please try again later.');
}

// -----------------------------------------------------------------------------
// 📥 8. Field validation.
// -----------------------------------------------------------------------------
$fields = FormEngine::getFields($formId);
$result = FormEngine::validateSubmission($fields, $_POST);

if (count($result['errors']) > 0) {
    $_SESSION['forms_pub_flash_old']    = $result['old'];
    $_SESSION['forms_pub_flash_errors'] = $result['errors'];
    $_SESSION['forms_pub_flash_token']  = $token;
    $bounce('Please correct the errors below.');
}

// -----------------------------------------------------------------------------
// 🌐 9. IP capture — the SAME address the rate limit above uses, capped to 45
// characters (the longest an IPv6 address can be written).
// -----------------------------------------------------------------------------
// 🛑 This used to read the Cloudflare and X-Forwarded-For headers directly
//    and believe them. Anybody can send those headers, so the address could
//    be anything a visitor chose. RateLimiter::clientIp() is the ONE place in
//    the portal that decides which address to believe: it trusts those
//    headers only when the request really arrived through a proxy listed in
//    portal.trustedProxies, and otherwise uses the address the connection
//    actually came from. Worse, this page was inconsistent with itself: its
//    rate limit a few lines up already used RateLimiter::clientIp(), then
//    this re-read the raw header to decide what to STORE - so the limit used
//    the trustworthy address while the saved response kept the forgeable one.
$ip = RateLimiter::clientIp();
$ip = mb_substr((string) $ip, 0, 45);

RateLimiter::recordHit($rateBucket, 900);

// -----------------------------------------------------------------------------
// 💾 10. Persist — the FORM'S OWN siteID, never Site::id().
// -----------------------------------------------------------------------------
$answersJson = FormEngine::buildAnswersJson($fields, $result['values']);
$newId = FormEngine::saveResponse($formId, $formSiteId, 'public', null, $ip, $answersJson);

// -----------------------------------------------------------------------------
// 🎉 11. Always redirect to the success page, even on a DB failure — no
// server-state oracle for a public visitor (prayer-requests precedent).
// -----------------------------------------------------------------------------
if ($newId > 0) {
    Logger::activity('FormResponsePublic', 'Form #' . $formId . ' response #' . $newId . ' from IP ' . $ip);
} else {
    Logger::errorPlatform('MySQL', 'Error', 'FORM_PUBLIC_RESPONSE_FAIL', 'saveResponse() returned 0', 'formID=' . $formId);
}

$successRedirect();
