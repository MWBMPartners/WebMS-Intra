<?php
// Path: _apps/calendar/event-register-save.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Public registration POST handler (#347)
 * -----------------------------------------------------------------------------
 * Validates, length-clamps, INSERTs a pending tblEventRegistrations row,
 * fires the calendar.event.registration.received webhook, shows a
 * thank-you page.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/347
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\EventVisibility;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\Router;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . Site::url('calendar'), true, 302); exit(); }

Auth::ensureSession();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); exit('Bad request.');
}

// 🤖 Captcha gate (#348). Skip if no provider configured.
if (class_exists(Captcha::class) === true && Captcha::isConfigured() === true) {
    if (Captcha::verify($_POST, 'event-registration') === false) {
        Logger::activity('EventRegistrationCaptchaFailed', 'Slug=' . ($_POST['slug'] ?? ''));
        http_response_code(400); exit('Captcha failed — please go back and try again.');
    }
}

$slug = trim((string) ($_POST['slug'] ?? ''));
if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9\-]{0,79}$/i', $slug) !== 1) {
    http_response_code(400); exit('Invalid event.');
}

// 🛡️ The SAME visibility rule as calendar/event-register.php, word for
//    word — see that file's header for the full account of what was wrong
//    (a real internal event's registration was silently ACCEPTED from
//    anybody at all, whatever this page's sign-in check said, because this
//    handler can be posted to directly without ever opening the form) and
//    why the rule is shaped the way it is. The viewer test sits INSIDE the
//    WHERE clause so a refused row and a missing one cost the database the
//    same work. Changed 20 September 2026: a refused submission and a
//    missing event now answer with the SAME page
//    (Router::renderEventUnavailable()) rather than a sign-in redirect, so
//    a dead link is not met with "please sign in" for something that no
//    longer exists — see event-register.php's header for the full reason.
//
//    #514 part P2 (21 September 2026): the hand-written block that used to sit
//    in this WHERE clause is replaced by the shared rule,
//    EventVisibility::where() in "session" mode — see event-register.php's
//    header for exactly what that changes (nothing for the portal's own
//    events, in meaning; events copied in from outside calendars now follow
//    their own level). Both files must keep using the SAME rule, which is now
//    guaranteed by both calling the same method.
$viewerId   = EventVisibility::sessionViewerId();
$siteId     = Site::id();
$visibility = EventVisibility::where('e', EventVisibility::MODE_SESSION, $viewerId, date('Y-m-d'));
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.eventName, e.registrationEnabled, e.registrationOpensAt, e.registrationClosesAt, e.isPublic '
    . 'FROM tblEvents e '
    . 'WHERE e.eventSlug = ? AND e.siteID = ? AND e.isDeleted = 0 '
    . "  AND e.status = 'published'"
    . $visibility['sql'] . ' LIMIT 1'
);
$stmt->bind_param('si' . $visibility['types'], $slug, $siteId, ...$visibility['params']);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if ($event === null) {
    Router::renderEventUnavailable();
    exit();
}

if ((int) $event['registrationEnabled'] !== 1) {
    http_response_code(404); exit('Registration not available.');
}
$now = time();
if (!empty($event['registrationOpensAt']) && strtotime((string) $event['registrationOpensAt']) > $now) {
    http_response_code(403); exit('Registration not yet open.');
}
if (!empty($event['registrationClosesAt']) && strtotime((string) $event['registrationClosesAt']) < $now) {
    http_response_code(403); exit('Registration has closed.');
}

// 🛡️ Clamp + sanitise every field.
$fullName     = mb_substr(trim((string) ($_POST['fullName'] ?? '')), 0, 120);
$dob          = trim((string) ($_POST['dateOfBirth'] ?? ''));
$grade        = mb_substr(trim((string) ($_POST['grade'] ?? '')), 0, 10);
$gender       = (string) ($_POST['gender'] ?? '');
$shirtSize    = (string) ($_POST['shirtSize'] ?? '');
$allergies    = mb_substr(trim((string) ($_POST['allergies'] ?? '')), 0, 500);
$medical      = mb_substr(trim((string) ($_POST['medicalNotes'] ?? '')), 0, 1000);
$parentName   = mb_substr(trim((string) ($_POST['parentName'] ?? '')), 0, 120);
$parentPhone  = mb_substr(trim((string) ($_POST['parentPhone'] ?? '')), 0, 40);
$parentEmail  = trim((string) ($_POST['parentEmail'] ?? ''));
$photoConsent = (int) ($_POST['photoConsent'] ?? 0) === 1 ? 1 : 0;
$emergName    = mb_substr(trim((string) ($_POST['emergencyContactName'] ?? '')), 0, 120);
$emergPhone   = mb_substr(trim((string) ($_POST['emergencyContactPhone'] ?? '')), 0, 40);

if ($fullName === '') {
    $_SESSION['flash_msg']  = 'Full name is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar/event/register') . '?slug=' . urlencode($slug), true, 302); exit();
}

// 🛡️ Validate constrained values; null on miss.
$genderArg    = in_array($gender, ['male', 'female', 'other'], true) === true ? $gender : null;
$shirtArg     = in_array($shirtSize, ['YS','YM','YL','XS','S','M','L','XL','XXL'], true) === true ? $shirtSize : null;
$dobArg       = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) === 1) ? $dob : null;
$parentEmailArg = ($parentEmail !== '' && filter_var($parentEmail, FILTER_VALIDATE_EMAIL) !== false) ? $parentEmail : null;
$eventIdInt   = (int) $event['eventID'];

// 🔗 Who filled this in, IF the portal knows them.
//
//    This form is public and must stay public for an event marked public - a
//    parent should not have to create an account to bring their child to a
//    holiday club. (An event not marked public needs sign-in; see above.) But when the
//    person IS signed in, recording their account matters a great deal.
//
//    Without it, this table could not be reached by a "delete everything you
//    hold about me" request at all. Such a request works by looking for rows
//    belonging to a person's account, and there was nothing here to match on.
//    So the single most sensitive table in the portal - a child's name, date of
//    birth, allergies and medical notes, with a parent's telephone number and
//    email beside them - was the one table an erasure could never find.
//
//    Left empty for anybody not signed in, which is normal and expected. Those
//    registrations are covered by the time limit instead: they are deleted a
//    set number of days after the event, whether anybody asks or not. See
//    migration 191 and the clear-out on the Retention page.
$submittedByArg = null;
if (isset($_SESSION['user_id']) === true && (int) $_SESSION['user_id'] > 0) {
    $submittedByArg = (int) $_SESSION['user_id'];
}

// 👤 AND WHO THE REGISTRATION IS FOR, which is a different question (#492
//    part 3, the owner, 3 November 2026; migration 246).
//
//    Recording the submitter is not the same as establishing a relationship,
//    and that mattered because an erasure DELETES a registration it finds for
//    somebody rather than merely unnaming it — the row holds the registered
//    person's name, date of birth, allergies and medical notes, so deleting it
//    is the purpose. With only the submitter recorded, erasing a volunteer who
//    had registered somebody else's child deleted that child's registration,
//    and that child's family had asked for nothing.
//
//    So the form asks, and only of a signed-in person, because nobody else has
//    an account to record. The answer is taken as "me" ONLY when it says so
//    exactly; everything else — the other option, a blank, an array, a request
//    that carries no such field at all — means this is not their own record.
//
//    THAT IS THE SAFE DIRECTION, and it is chosen deliberately. An empty value
//    here means an erasure leaves the row alone and detaches the submitter, so
//    nobody else's record is destroyed on a request that was never theirs.
//    What it costs is that a member's OWN registration is beyond the reach of
//    their own request if they did not answer: the time limit on the event
//    (`registrationRetentionDays`, migration 191) still deletes it, and an
//    administrator can deal with it directly. Destroying a third party's
//    record cannot be undone; leaving one to a timer can.
//
//    ASK WHETHER THE FIELD SAYS "me", NEVER WHAT IT CONTAINS OTHERWISE. A
//    request chooses the TYPE of what it sends as well as the value, so
//    `?registeredFor[]=me` would arrive as an array; `=== 'me'` on a value
//    cast to string refuses it.
$registeredForArg = null;
if ($submittedByArg !== null
    && is_string($_POST['registeredFor'] ?? null) === true
    && (string) $_POST['registeredFor'] === 'me'
) {
    $registeredForArg = $submittedByArg;
}

$stmt = $mysqli->prepare(
    'INSERT INTO `tblEventRegistrations` '
    . '(`eventID`, `fullName`, `dateOfBirth`, `grade`, `gender`, `shirtSize`, `allergies`, `medicalNotes`, '
    . ' `parentName`, `parentPhone`, `parentEmail`, `photoConsent`, `emergencyContactName`, '
    . ' `emergencyContactPhone`, `submittedByUserID`, `registeredForUserID`, `status`, `source`) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", "public-form")'
);
// ⚠️ The letters below must match the values after them, one for one and in
//    order: i = a whole number, s = text. Adding a value without adding its
//    letter is a fault this project has an automatic check for
//    (check_bind_param_arity.py), because it fails at the moment somebody uses
//    the form rather than when the code is written.
$stmt->bind_param(
    'issssssssssissii',
    $eventIdInt, $fullName, $dobArg, $grade, $genderArg, $shirtArg,
    $allergies, $medical, $parentName, $parentPhone, $parentEmailArg,
    $photoConsent, $emergName, $emergPhone, $submittedByArg, $registeredForArg
);
$stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

// #933: the registration's NUMBER, never the registrant's name. The person
//    registered is often a child, or somebody with no account, and the log's
//    words are the one place an erasure cannot reach; the name is on the
//    registration row, which the registrations clear-out and an erasure do.
Logger::activity('EventRegistrationReceived', 'Event #' . $eventIdInt . ' registration #' . $newId);

// 📧 Email confirmation to registrant (#348).
if ($parentEmailArg !== null && class_exists(Mailer::class) === true && method_exists(Mailer::class, 'send') === true) {
    $confirmSubject = 'Registration received — ' . (string) $event['eventName'];
    $confirmBody    = '<p>Hi' . ($parentName !== '' ? ' ' . htmlspecialchars($parentName, ENT_QUOTES, 'UTF-8') : '') . ',</p>'
        . '<p>We\'ve received the registration for <strong>'
        . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8')
        . '</strong> for <strong>' . htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
        . '<p>Status: <strong>Pending review</strong>. We will be in touch once it has been confirmed.</p>'
        . '<p>If you have questions, just reply to this email.</p>';
    @Mailer::send($parentEmailArg, $confirmSubject, $confirmBody);
}

if (class_exists('\\Portal\\Core\\WebhookDispatcher') === true) {
    $payload = ['eventID' => $eventIdInt, 'registrationID' => $newId, 'fullName' => $fullName, 'grade' => $grade];
    \Portal\Core\WebhookDispatcher::emit('calendar.event.registration.received', $payload);
}

// 🎨 Thank-you page.
$pageTitle = 'Registration received';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
echo '<div class="container py-5 text-center" style="max-width:560px;">';
echo '<i class="fa-solid fa-circle-check fa-3x text-success mb-3"></i>';
echo '<h1 class="h3 mb-2">Thank you!</h1>';
echo '<p class="text-muted">We\'ve received the registration for <strong>' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
echo '<p class="text-muted small">Status: <strong>Pending review</strong>. The event coordinator will be in touch.</p>';
echo '<a href="' . htmlspecialchars(Site::url('calendar/event'), ENT_QUOTES, 'UTF-8') . '?slug=' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" class="btn btn-outline-primary mt-3">Back to event</a>';
echo '</div>';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
exit();
