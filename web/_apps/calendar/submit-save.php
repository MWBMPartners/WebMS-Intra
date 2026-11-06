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
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\I18n;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('calendar/submit'), true, 302);
    exit();
}

Auth::ensureSession();

if ((App::settings('calendar.publicSubmit.enabled') ?? 'true') !== 'true') {
    http_response_code(404);
    exit('Submissions are closed');
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('EventSubmitRejected', 'Invalid CSRF on /calendar/submit-save');
    header('Location: ' . Site::url('calendar/submit') . '?err=csrf', true, 302);
    exit();
}

$isLoggedIn = isset($_SESSION['user_id']) === true && (int) $_SESSION['user_id'] > 0;
$allowAnon  = (App::settings('calendar.publicSubmit.allowAnonymous') ?? 'true') === 'true';
$requireCap = (App::settings('calendar.publicSubmit.requireCaptcha')  ?? 'true') === 'true';

if ($isLoggedIn === false && $allowAnon === false) {
    header('Location: ' . Site::url('login') . '?redirect=' . urlencode(Site::url('calendar/submit')), true, 302);
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
    if (Captcha::verify($_POST, 'event-submission') === false) {
        Logger::activity('EventSubmitRejected', 'Failed captcha on /calendar/submit-save');
        header('Location: ' . Site::url('calendar/submit') . '?err=captcha', true, 302);
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
    header('Location: ' . Site::url('calendar/submit') . '?err=required', true, 302);
    exit();
}

// 🕐 A TIME ON A CLOCK IS NOT A MOMENT, AND THIS FORM USED TO STORE THE
//    MOVED HOUR PERMANENTLY (#777).
//
//    `tblEvents.startDateTime` says so in its own column comment: "Event
//    start - wall-clock local (venue/site local); NOT UTC". The form sends a
//    `datetime-local` value, which is a reading from a clock with no zone in
//    it. The old code was:
//
//        $startDt  = strtotime($startRaw);
//        $startUtc = date('Y-m-d H:i:s', (int) $startDt);
//
//    -- a round trip through a MOMENT. On the morning the clocks go forward
//    the hour the reading names does not exist, so PHP moves it, and the moved
//    value went into the INSERT. Measured in Europe/London:
//
//        posted 2026-03-29T01:30  ->  STORED 2026-03-29 02:30:00
//        posted 2026-03-29T01:00  ->  STORED 2026-03-29 02:00:00
//        posted 2026-03-28T01:30  ->  STORED 2026-03-28 01:30:00  (right)
//
//    ⚠️ THIS IS WORSE THAN THE READER FAULTS #777 FOUND FIRST, because nothing
//       cancels it out. A member submits an event for half past one, the diary
//       holds half past two FOR EVER, and every later reader shows half past
//       two perfectly faithfully. The variable was even called `$startUtc`,
//       which was wrong twice over: not UTC, and not a moment.
//
//    The cure is the one `_apps/resources/book.php` already uses: keep the
//    value on the WALL CLOCK, which is what the column holds. Validate the
//    TEXT -- shape AND range, because `\d{2}` accepts `25` -- and store the
//    text. No moment is built here at all, so there is no zone to get wrong.
//
//    `datetime-local` sends `Y-m-d\TH:i` and may add `:ss`, so the `T`
//    becomes a space and the seconds are supplied when the browser left them
//    out.
//
//    ⚠️ IT IS STRICTER THAN WHAT IT REPLACED, in three ways, said plainly
//       rather than left to be discovered. Measured old against new:
//
//        "2026-03-29T01:30"  old 02:30:00     new 01:30:00   <- the fix
//        "2026-03-29"        old 00:00:00     new REFUSED
//        "2026-02-31T10:00"  old 3 March!     new REFUSED
//        "tomorrow"          old tomorrow     new REFUSED
//
//       Only the bare date is a real loss, and this form cannot produce one:
//       the input is `type="datetime-local"` and `required`, and `isAllDay` is
//       a literal `0` in the INSERT below, so there is no all-day path that
//       would legitimately send a date with no time. A crafted post that sends
//       one now gets `?err=date` instead of a silent midnight. The other two
//       were faults: `2026-02-31` rolled forward to 3 March without a word,
//       and any English `strtotime()` happened to understand was accepted.
// 📌 `I18n::wallClockFromInput()` is the ONE place this rule lives (#768's
//    shape caught early): this handler, the two events API handlers and the
//    CSV import all had their own copy for about an hour.
$startUtc = I18n::wallClockFromInput($startRaw);
if ($startUtc === null) {
    header('Location: ' . Site::url('calendar/submit') . '?err=date', true, 302);
    exit();
}
$endUtc = null;
if ($endRaw !== '') {
    $endUtc = I18n::wallClockFromInput($endRaw);
    if ($endUtc === null) {
        header('Location: ' . Site::url('calendar/submit') . '?err=date', true, 302);
        exit();
    }
    // ⏱️ Two wall-clock readings COMPARE CORRECTLY AS TEXT, which is #435's
    //    own fix -- `Y-m-d H:i:s` sorts the same way as the clock does. Making
    //    moments of them to compare would put the fault straight back.
    //
    // ⚠️ "BEFORE", NOT "BEFORE OR EQUAL", and the first version of this line
    //    had it the other way round. `calendar/manage/save.php` -- the admin
    //    handler for the SAME column -- says so in as many words: "An equal
    //    end and start is allowed: a moment in a diary is a reasonable thing
    //    to record, and the comparison below is deliberately 'before', not
    //    'before or equal'." Two handlers writing one column must not disagree
    //    about what it may hold, and the decision was already taken and
    //    written down.
    if ($endUtc < $startUtc) {
        header('Location: ' . Site::url('calendar/submit') . '?err=date', true, 302);
        exit();
    }
}

if ($isLoggedIn === false && ($submitterName === '' || $submitterEmail === '')) {
    header('Location: ' . Site::url('calendar/submit') . '?err=contact', true, 302);
    exit();
}
if ($submitterEmail !== '' && filter_var($submitterEmail, FILTER_VALIDATE_EMAIL) === false) {
    header('Location: ' . Site::url('calendar/submit') . '?err=email', true, 302);
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

// 🕰️ THE ZONE (#604, reworded by #777 round 7). `Site::timezone()` is the zone
//    recorded beside the start and end because **this form belongs to this
//    organisation** — the visitor typed those times on its clock.
//
// ⚠️ THAT REASON USED TO BE "they were read just above on PHP's clock, which
//    start-up sets to this organisation's zone". Round 5 DELETED that read:
//    the values are now stored exactly as sent and no zone is applied to them
//    at all. The conclusion is unchanged and still right; the mechanism it
//    cited is gone, and in this repository the reason is what the next reader
//    checks the design against.
//
//    The old wording, for anyone tracing it: the start and end were read on
//    PHP's clock,
//    which start-up sets to this organisation's zone, so that is the zone
//    stored beside them: Site::timezone(), the same answer start-up used.
//    WHAT WAS WRONG: this statement had "Europe/London" written into it, so a
//    suggestion made in any other organisation was stored as a London time
//    and shown hours out once approved.
$zoneArg = Site::timezone();

// 12 params, types = i (siteID) + s s s s s (name/slug/desc/start/end) + s (zone) + s (loc) + i (cat) + i (submitterId) + s s (name/email).
//
// ⚠️ THE 8th CHARACTER WAS `i` AND IT DESTROYED EVERY SUBMITTED LOCATION
//    (#777 round 6). `locationName` is `VARCHAR(255)`; bound as an integer,
//    mysqli casts the string, so "Main Hall" was stored as `'0'` and the
//    moderator saw `0`. Measured on a real MySQL 8.0.36:
//
//        bound 'i': locationName => '0'
//        bound 's': locationName => 'Main Hall'
//
//    The comment on the line above has always described the RIGHT string
//    (`isssssssiiss`) while the code had the wrong one -- a comment stating
//    the thing the code had forgotten to do, which is this repository's named
//    worst fault, and it survived because `php -l` cannot see a type string
//    and nothing posts to this handler with a location.
//
//    It came in with #604 (`d8177e4`), NOT with #777 -- established with
//    `git log -S"issssssiiiss" -- web/_apps/calendar/submit-save.php`. It is
//    fixed here because round 5 rewrote 87 lines of this file and read this
//    very INSERT to establish that `isAllDay` is a literal `0`.
$stmt = $mysqli->prepare(
    'INSERT INTO tblEvents '
    . '(siteID, eventName, eventSlug, description, startDateTime, endDateTime, timezone, isAllDay, '
    . ' locationName, categoryID, status, isPublic, '
    . ' submissionStatus, submittedByID, submitterName, submitterEmail, submittedAt) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, "draft", 0, '
    . '        "pending", ?, ?, ?, NOW())'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'EVENT_SUBMIT_PREP', $mysqli->error, '');
    header('Location: ' . Site::url('calendar/submit') . '?err=db', true, 302);
    exit();
}
$stmt->bind_param('isssssssiiss', $siteId, $eventName, $slug, $descArg, $startUtc, $endUtc, $zoneArg, $locArg, $categoryArg, $submitterId, $nameArg, $emailArg);
$ok = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'EVENT_SUBMIT_FAIL', $mysqli->error, '');
    header('Location: ' . Site::url('calendar/submit') . '?err=save', true, 302);
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

header('Location: ' . Site::url('calendar/submit') . '?submitted=1', true, 302);
exit();
