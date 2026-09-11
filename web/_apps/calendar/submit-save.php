<?php
// Path: _apps/calendar/submit-save.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Public "Submit an Event" Save Handler 📅✏️ (#326)
 * -----------------------------------------------------------------------------
 * POST endpoint. Validates input + captcha (anonymous only), inserts a row
 * into tblEvents with submissionStatus='pending'/isPublic=0/status='draft',
 * redirects back to the form with ?submitted=1.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/326
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /calendar/submit', true, 302);
    exit();
}

Auth::ensureSession();

if ((App::settings('calendar.publicSubmit.enabled') ?? 'true') !== 'true') {
    http_response_code(404);
    exit('Submissions are closed');
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('EventSubmitRejected', 'Invalid CSRF on /calendar/submit-save');
    header('Location: /calendar/submit?err=csrf', true, 302);
    exit();
}

$isLoggedIn = isset($_SESSION['user_id']) === true && (int) $_SESSION['user_id'] > 0;
$allowAnon  = (App::settings('calendar.publicSubmit.allowAnonymous') ?? 'true') === 'true';
$requireCap = (App::settings('calendar.publicSubmit.requireCaptcha')  ?? 'true') === 'true';

if ($isLoggedIn === false && $allowAnon === false) {
    header('Location: /auth/login?redirect=' . urlencode('/calendar/submit'), true, 302);
    exit();
}

// 🛡️ Anti-robot check for submissions from somebody not signed in.
//
//    This called Captcha::verifyFromPost(), which does not exist - the class
//    offers verify(). So the moment an anonymous visitor sent the form, it
//    stopped with a fatal error instead of saving anything.
//
//    The old guard tested class_exists(), which is always true. It now tests
//    that a provider has actually been SET UP, matching
//    calendar/event-register-save.
$captchaUsable = class_exists(Captcha::class) === true && Captcha::isConfigured() === true;

if ($isLoggedIn === false && $requireCap === true && $captchaUsable === true) {
    if (Captcha::verify($_POST) === false) {
        Logger::activity('EventSubmitRejected', 'Failed captcha on /calendar/submit-save');
        header('Location: /calendar/submit?err=captcha', true, 302);
        exit();
    }
}

// ⚠️ An administrator asked for an anti-robot check and there is no provider
//    set up to do it.
//
//    The submission is allowed to CONTINUE - refusing would make a public form
//    unusable because of a setting the visitor can neither see nor fix, and
//    every other public form in this portal behaves the same way (Captcha::verify
//    itself returns true when nothing is configured). What arrives is a draft
//    awaiting review, not a published event.
//
//    But it must not pass silently, because the administrator believes a door is
//    closed that is in fact wide open to automated spam.
//
//    This says SKIPPED rather than accepted, deliberately. It is written before
//    the submission has been checked or saved, so at this point nothing has been
//    accepted yet and the entry would otherwise claim something untrue about
//    submissions that go on to fail validation.
if ($isLoggedIn === false && $requireCap === true && $captchaUsable === false) {
    Logger::activity(
        'EventSubmitCaptchaNotConfigured',
        'Anti-robot check SKIPPED on a public event submission: it is switched '
        . 'on in the settings but no provider has been set up. Configure one at '
        . '/admin/captcha.'
    );
}

$eventName       = trim((string) ($_POST['eventName']      ?? ''));
$startRaw        = trim((string) ($_POST['startDateTime']  ?? ''));
$endRaw          = trim((string) ($_POST['endDateTime']    ?? ''));
$categoryId      = (int)         ($_POST['categoryID']     ?? 0);
$locationName    = trim((string) ($_POST['locationName']   ?? ''));
$description     = trim((string) ($_POST['description']    ?? ''));
$submitterName   = trim((string) ($_POST['submitterName']  ?? ''));
$submitterEmail  = trim((string) ($_POST['submitterEmail'] ?? ''));

if ($eventName === '' || $startRaw === '') {
    header('Location: /calendar/submit?err=required', true, 302);
    exit();
}

$startDt = strtotime($startRaw);
$endDt   = $endRaw !== '' ? strtotime($endRaw) : 0;
if ($startDt === false) {
    header('Location: /calendar/submit?err=date', true, 302);
    exit();
}

$startUtc = date('Y-m-d H:i:s', (int) $startDt);
$endUtc   = $endDt > 0 ? date('Y-m-d H:i:s', (int) $endDt) : null;

if ($isLoggedIn === false && ($submitterName === '' || $submitterEmail === '')) {
    header('Location: /calendar/submit?err=contact', true, 302);
    exit();
}
if ($submitterEmail !== '' && filter_var($submitterEmail, FILTER_VALIDATE_EMAIL) === false) {
    header('Location: /calendar/submit?err=email', true, 302);
    exit();
}

$siteId      = Site::id();
$submitterId = $isLoggedIn === true ? (int) $_SESSION['user_id'] : null;
$categoryArg = $categoryId > 0 ? $categoryId : null;
$locArg      = $locationName !== '' ? $locationName : null;
$descArg     = $description  !== '' ? $description  : null;
$nameArg     = $isLoggedIn === true ? null : $submitterName;
$emailArg    = $isLoggedIn === true ? null : $submitterEmail;

// 🛡️ Slug generation — strip non-alphanumerics, lowercase, plus a 4-char
//     random suffix to avoid collisions during the moderation window.
$base  = preg_replace('/[^a-z0-9]+/', '-', strtolower($eventName));
$base  = trim((string) $base, '-');
$base  = $base === '' ? 'event' : mb_substr($base, 0, 180);
$slug  = $base . '-' . substr(bin2hex(random_bytes(2)), 0, 4);

// 11 params, types = i (siteID) + s s s s s (name/slug/desc/start/end) + s (loc) + i (cat) + i (submitterId) + s s (name/email).
$stmt = $mysqli->prepare(
    'INSERT INTO tblEvents '
    . '(siteID, eventName, eventSlug, description, startDateTime, endDateTime, timezone, isAllDay, '
    . ' locationName, categoryID, status, isPublic, '
    . ' submissionStatus, submittedByID, submitterName, submitterEmail, submittedAt) '
    . 'VALUES (?, ?, ?, ?, ?, ?, "Europe/London", 0, ?, ?, "draft", 0, '
    . '        "pending", ?, ?, ?, NOW())'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'EVENT_SUBMIT_PREP', $mysqli->error, '');
    header('Location: /calendar/submit?err=db', true, 302);
    exit();
}
$stmt->bind_param('isssssiiiss', $siteId, $eventName, $slug, $descArg, $startUtc, $endUtc, $locArg, $categoryArg, $submitterId, $nameArg, $emailArg);
$ok = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'EVENT_SUBMIT_FAIL', $mysqli->error, '');
    header('Location: /calendar/submit?err=save', true, 302);
    exit();
}

Logger::activity('EventSubmitted', 'Event #' . $newId . ' submitted by ' . ($isLoggedIn ? 'user ' . $submitterId : 'anonymous ' . $submitterEmail));

// 🪝 Outbound webhook on submission (#324).
if (class_exists(\Portal\Core\WebhookDispatcher::class) === true) {
    \Portal\Core\WebhookDispatcher::emit('calendar.event.submitted', [
        'eventID'     => $newId,
        'eventName'   => $eventName,
        'submittedBy' => $isLoggedIn === true ? ('user-' . $submitterId) : $submitterEmail,
    ]);
}

header('Location: /calendar/submit?submitted=1', true, 302);
exit();
