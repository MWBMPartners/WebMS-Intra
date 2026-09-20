<?php
// Path: _apps/calendar/event-attendance-anon-add.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Add an event's anonymous check-ins to an attendance session ➕
 * -----------------------------------------------------------------------------
 * The form target behind the "Add to session" button on an event's attendance
 * page. POST only, form token first, administrators only.
 *
 * WHY THIS IS A DELIBERATE ACT AND NOT AUTOMATIC
 * ----------------------------------------------
 * Anonymous check-ins and named attendance are counted on completely different
 * bases. A check-in is an anonymous press of a button that may cover a whole
 * family; named attendance is a person ticked off a list. Folding one into the
 * other automatically would quietly change an organisation's own headcount
 * figures, with nothing to show where the change came from. So it only ever
 * happens because an administrator asked for it, and what it writes is labelled
 * with the event's name so anybody reading the session can see exactly what it
 * is.
 *
 * RE-RUNNABLE ON PURPOSE. Pressing the button again replaces the same labelled
 * row with the current number. It never adds a second one, and it never doubles
 * a count — see `AnonymousCheckins::addToAttendanceSession()` for how that is
 * made true even when two administrators press it at the same moment.
 *
 * REFUSALS GIVE NOTHING AWAY. An event or a session belonging to another
 * organisation is refused in exactly the same way as one that does not exist:
 * the same message, the same redirect. Otherwise the difference between the two
 * answers would be a way of finding out which numbers are real.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/525
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AnonymousCheckins;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🔑 Administrators only. Seeing the figures can be widened by a setting;
//    writing them into the organisation's own attendance record cannot.
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /attendance');
    exit();
}

// 🔐 Form token FIRST, before anything with a side effect, including the
//    activity-log entry below.
$eventId = (int) ($_POST['eventID'] ?? 0);

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Nothing was changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /calendar/event/attendance?eventID=' . $eventId);
    exit();
}

$siteId    = Site::id();
$sessionId = (int) ($_POST['sessionID'] ?? 0);
$userId    = (int) ($_SESSION['user_id'] ?? 0);

// A day is either a plain YYYY-MM-DD or nothing at all. It is compared as text
// against DATE(checkedInAt) and is never turned into a timestamp, because
// stepping through days as timestamps repeats or skips a day on the two days a
// year the clocks change (#528).
$rawDay = trim((string) ($_POST['day'] ?? ''));
$day    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDay) === 1 ? $rawDay : null;

$result = AnonymousCheckins::addToAttendanceSession($mysqli, $siteId, $eventId, $sessionId, $day);

if ($result['ok'] !== true) {
    // Identical wording for "does not exist", "belongs to somebody else" and
    // "the write failed". The activity log records which it really was, for an
    // administrator looking into it afterwards; the person at the screen is not
    // told, because the difference is exactly the thing worth hiding.
    Logger::activity(
        'AnonymousCheckinsAddRefused',
        'Refused (' . (string) ($result['reason'] ?? 'unknown') . '): event ' . $eventId
        . ', session ' . $sessionId . ', organisation ' . $siteId,
        $userId > 0 ? $userId : null
    );

    $_SESSION['flash_msg']  = 'That event or attendance session could not be used. Nothing was changed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /calendar/event/attendance?eventID=' . $eventId);
    exit();
}

$was   = $result['was'] ?? null;
$now   = (int) ($result['now'] ?? 0);
$label = (string) ($result['label'] ?? '');

Logger::activity(
    'AnonymousCheckinsAddedToSession',
    'Event ' . $eventId . ', session ' . $sessionId . ', day ' . ($day ?? 'all')
    . ': wrote ' . $now . ' under "' . $label . '" (was '
    . ($was === null ? 'nothing' : (string) $was) . ')',
    $userId > 0 ? $userId : null
);

$_SESSION['flash_msg'] = $was === null
    ? 'Added ' . $now . ' to that attendance session, under "' . $label . '".'
    : 'Updated "' . $label . '" in that attendance session from ' . $was . ' to ' . $now . '.';
$_SESSION['flash_type'] = 'success';

header('Location: /calendar/event/attendance?eventID=' . $eventId);
exit();
