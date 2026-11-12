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
use Portal\Core\Events;
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
//    ⚠️ IT IS STRICTER THAN WHAT IT REPLACED, in two ways, said plainly
//       rather than left to be discovered. Measured old against the code as it
//       stands:
//
//        "2026-03-29T01:30"  old 02:30:00     new 01:30:00   <- the fix
//        "2026-02-31T10:00"  old 3 March!     new REFUSED
//        "tomorrow"          old tomorrow     new REFUSED
//        "2026-03-29"        old 00:00:00     new 00:00:00   <- unchanged
//
//       Both refusals were faults: `2026-02-31` rolled forward to 3 March
//       without a word, and any English `strtotime()` happened to understand
//       was accepted.
//
//    ⚠️ AND AN EARLIER VERSION OF THIS NOTE SAID "three ways" AND LISTED A
//       BARE DATE AS REFUSED, which stopped being true a few hours later.
//       Round 7 loosened `wallClockFromInput()` to accept a date with no time,
//       because the events API needs it for an all-day event -- and all four
//       callers share that helper, so this form inherited it while the note
//       here still said `?err=date`. Round 8 of #777 found it.
//
//       It is left ACCEPTED rather than refused again. This form's input is
//       `type="datetime-local"` and `required`, so only a crafted post can
//       send a bare date; what it then stores is midnight on that day, with
//       `isAllDay` a literal `0` in the INSERT below, in a submission a
//       moderator reviews before anybody sees it. Refusing it here would need
//       a second helper or a flag, and a flag on the ONE place a rule lives is
//       how two rules start (#768's shape). **The cost of the sharing is
//       stated rather than hidden: a change made for one caller reaches all
//       four, so loosening that helper means checking all four.**
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

// 🔤 THE WEB ADDRESS: NAME, DATE, AND A FREE ONE ASKED OF THE DATABASE (#883).
//
//    WHAT WAS HERE. The name plus four random characters, never asked of the
//    database: 65,536 endings, so two suggestions with the same name met on
//    the same address sooner than anybody would guess (worked out, not
//    measured: among 100 suggestions of one name, about a 7% chance that two
//    share an ending), and the database then refused the later one, so
//    nothing of it was saved. It was also its own copy of the
//    "which address is free" question, and copies drift.
//
//    NOW the stem is the name and the start date (`Events::slugStem()`, the
//    shape the Manage Events form gives) and `Events::saveUnderFreeSlug()`
//    asks for the first free one at the moment of saving — the one place
//    every writer of an event asks. The address of a suggestion is never
//    shown to the person suggesting it (they are sent back to the form), so
//    the answer tells them nothing about other events.
$slugStem = Events::slugStem($eventName, $startUtc);
$slug     = '';

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
// 🌐 #493 P1: `showOnWebsite`, `websiteFrom` and `websiteUntil` are NOT in this
//    column list, on purpose, so a submission from the public form always takes
//    their defaults — off, no dates — whatever it posts. Only a person allowed
//    to publish (`PublicWebsite::mayPublish()`) can put an event on the website,
//    and `tools/public-website-tick-selftest.php` posts the fields here to prove
//    they are ignored.
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
// 🔗 `$slug` is bound by reference, so each try below only changes `$slug`.
$stmt->bind_param('isssssssiiss', $siteId, $eventName, $slug, $descArg, $startUtc, $endUtc, $zoneArg, $locArg, $categoryArg, $submitterId, $nameArg, $emailArg);
try {
    $slug = Events::saveUnderFreeSlug($mysqli, $siteId, $slugStem, static function (string $candidate) use ($stmt, &$slug): void {
        $slug = $candidate;
        if ($stmt->execute() !== true) {
            throw new \mysqli_sql_exception((string) $stmt->error, (int) $stmt->errno);
        }
    });
} catch (\mysqli_sql_exception $refused) {
    // 🛡️ Never the fault's own words in the record: it can quote the value
    //    it refused, which here is a stranger's typing. The code says which.
    $stmt->close();
    Logger::errorPlatform('MySQL', 'Error', 'EVENT_SUBMIT_FAIL', 'error ' . (int) $refused->getCode(), '');
    header('Location: ' . Site::url('calendar/submit') . '?err=save', true, 302);
    exit();
}
$newId = (int) $stmt->insert_id;
$stmt->close();

// 🔒 THE RECORD OF A SUGGESTION NAMES NOBODY WHO HAS NO ACCOUNT (#963).
//
//    A member who suggests an event is named the way every activity record
//    names its person: by account number, and Logger::activity() fills
//    `userID` from the session.
//
//    A visitor who is not signed in has no account, so there is nobody for
//    the record to name. This line used to write their e-mail address into
//    the description ("submitted by anonymous <address>"). D-134 says the
//    address is used for ONE thing — telling them their suggestion was
//    approved — and the activity log is kept for a year by default and for
//    ever if an administrator chooses "never" (#932). An erasure does not
//    rewrite a description either, so the address would have outlived any
//    request to remove it. The record now says what happened and which event
//    it was; who sent it is on the event's own row, which only the moderation
//    page shows. Records written before #963 still hold addresses. An erasure
//    request filed for the visitor's address (#964, D-140) takes the address
//    out of such a record at the organisation that filed it - but only the
//    exact sentence this line used to write, "Event #N submitted by anonymous
//    <address>", on a record naming no account (GdprEraser::
//    eraseVisitorActivityRecords()). Nothing removes them otherwise, and a
//    record at another organisation needs that organisation's own request.
if ($isLoggedIn === true) {
    Logger::activity('EventSubmitted', 'Event #' . $newId . ' submitted by user ' . $submitterId);
} else {
    Logger::activity('EventSubmitted', 'Event #' . $newId . ' suggested by a visitor who was not signed in');
}

// 🪝 Outbound webhook on submission (#324).
//
// 🔒 `submittedBy` is "visitor" for somebody who is not signed in, never their
//    address (#963). It used to be the address itself, so every connected
//    service listening for `calendar.event.submitted` was sent a member of
//    the public's e-mail address, and a copy of what was sent is kept on the
//    delivery row (`tblWebhookDeliveries.payload`). The receiver gains nothing
//    it could rightly use: D-134 keeps the address for the approval e-mail
//    alone, and that e-mail is sent by the portal. A member is still
//    `user-<account number>`, as before — a number, not a name or an
//    address, of the kind other webhooks already carry (`reviewerID` on a
//    registration's, admin/calendar/registrations-act.php).
//    The name is not sent either, and never was.
if (class_exists(\Portal\Core\WebhookDispatcher::class) === true) {
    \Portal\Core\WebhookDispatcher::emit('calendar.event.submitted', [
        'eventID'     => $newId,
        'eventName'   => $eventName,
        'submittedBy' => $isLoggedIn === true ? ('user-' . $submitterId) : 'visitor',
    ]);
}

header('Location: ' . Site::url('calendar/submit') . '?submitted=1', true, 302);
exit();
