<?php
// Path: _apps/calendar/event-attendance-mark.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Attendance toggle POST handler (#345)
 * -----------------------------------------------------------------------------
 * Toggles a single cell on the multi-day attendance grid. toggle=1 inserts
 * a row; toggle=0 deletes it. Walk-in adds via the same endpoint when
 * walkinName is provided instead of userID.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/345
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('calendar'), true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); exit('Bad request');
}

$eventId    = (int) ($_POST['eventID'] ?? 0);
$userId     = (int) ($_POST['userID'] ?? 0);
$walkinName = trim((string) ($_POST['walkinName'] ?? ''));
$dayDate    = trim((string) ($_POST['dayDate'] ?? ''));
$toggle     = (int) ($_POST['toggle'] ?? 0);
$markerId   = (int) ($_SESSION['user_id'] ?? 0);
$siteId     = Site::id();

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}

// 🛡️ Validate dayDate as YYYY-MM-DD.
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayDate) !== 1) {
    http_response_code(400); exit('Invalid day');
}

// 🛡️ Confirm event belongs to active site.
// Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Event not found'); }

$redirect = Site::url('calendar/event/attendance') . '?eventID=' . $eventId;

if ($toggle === 1) {
    // 💾 Insert once. `INSERT IGNORE` throws the second copy away, and it can
    //    only do that because `uq_attendance_person` really refuses it.
    //
    //    ⚠ THIS COMMENT USED TO SAY "idempotent via unique key" AND WAS WRONG.
    //    The old key covered (eventID, userID, walkinName, dayDate), and no row
    //    ever fills in both `userID` and `walkinName` — so every row had an
    //    empty part, an empty part makes a unique key stand aside, and the key
    //    refused nothing. Pressing the button twice added a SECOND ROW instead
    //    of being ignored. Migration 224 replaced that key with one on a
    //    worked-out `attendeeRef`, which is what makes this line true.
    //
    //    ⚠ What it did NOT do, because an earlier version of this comment
    //    claimed it did: no attendance figure shown to anybody was wrong.
    //    `event-attendance.php` keys its grid by person and day and lists
    //    walk-ins with SELECT DISTINCT, so the surplus row was invisible. What
    //    it cost was a duplicated "who marked this" trail and a table that
    //    would mislead the first reader to count rows rather than people. If a future change touches that key, this
    //    `INSERT IGNORE` is relying on it — check them together.
    if ($userId > 0) {
        $stmt = $mysqli->prepare(
            'INSERT IGNORE INTO tblEventAttendance (eventID, userID, dayDate, markedByID) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('iisi', $eventId, $userId, $dayDate, $markerId);
    } elseif ($walkinName !== '') {
        $stmt = $mysqli->prepare(
            'INSERT IGNORE INTO tblEventAttendance (eventID, walkinName, dayDate, markedByID) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('issi', $eventId, $walkinName, $dayDate, $markerId);
    } else {
        header('Location: ' . $redirect, true, 302); exit();
    }
    $stmt->execute();
    $stmt->close();
    Logger::activity('EventAttendanceMarked', 'Event #' . $eventId . ' day ' . $dayDate . ' user/walkin');
} else {
    // 🗑️ Remove a cell. BOTH kinds of attendee, mirroring the two branches
    //    above — a member by number, a walk-in by name.
    //
    //    ⚠ THE WALK-IN HALF WAS MISSING UNTIL 29 OCTOBER 2026, and it was the
    //    bad kind of missing: the grid renders a walk-in's cell EXACTLY like a
    //    member's (green, with a tick), the page tells the coordinator "click
    //    a cell to toggle", and the form really does post `toggle=0` with the
    //    name. This branch simply ignored it, then redirected as though it had
    //    worked. So a walk-in added by mistake — a mistyped name, the wrong
    //    day — could never be taken off, and nothing said so. The old comment
    //    here deferred it to "a v1.1 admin UI", but the page was already
    //    sending the request; only the handler was absent.
    //
    //    Migration 224 made that worse rather than better: with one row per
    //    person per day now enforced, re-adding the corrected spelling leaves
    //    the mistyped row sitting there for ever. Finishing this is what makes
    //    that migration safe to ship.
    if ($userId > 0) {
        $stmt = $mysqli->prepare(
            'DELETE FROM tblEventAttendance WHERE eventID = ? AND userID = ? AND dayDate = ?'
        );
        $stmt->bind_param('iis', $eventId, $userId, $dayDate);
        $stmt->execute();
        $stmt->close();
        Logger::activity('EventAttendanceUnmarked', 'Event #' . $eventId . ' day ' . $dayDate . ' user ' . $userId);
    } elseif ($walkinName !== '') {
        // `userID IS NULL` is deliberate: it keeps this statement from ever
        // reaching a MEMBER's row, whatever the posted name says. The name is
        // compared the way the column is stored (utf8mb4_general_ci, so
        // capitals and trailing spaces are ignored), which is the SAME rule
        // `uq_attendance_person` applies — so this can never remove more than
        // the one row that key allows for an event, a day and a person.
        //
        // ⚠ AN EARLIER VERSION OF THIS COMMENT SAID the grid and this
        // statement "cannot disagree". THEY CAN, and it is worth knowing
        // rather than believing otherwise. The grid builds its keys in PHP by
        // exact bytes (`'w:' . $walkinName . ':' . $d`) while the database
        // compares them case-insensitively. So "Dave" on one day and "dave" on
        // another are ONE name in the grid's list (it reads
        // `SELECT DISTINCT walkinName`) but two different PHP keys, and the
        // second day draws as not attended although a row exists — which makes
        // that row invisible AND out of this branch's reach, because the cell
        // then posts `toggle=1` rather than `toggle=0`.
        //
        // That display gap is older than this branch and is NOT fixed here
        // (#655);
        // the grid should fold case the way the database does, or the enrol
        // form should settle on one spelling. What IS true of this statement is
        // the sentence above it.
        $stmt = $mysqli->prepare(
            'DELETE FROM tblEventAttendance '
            . 'WHERE eventID = ? AND userID IS NULL AND walkinName = ? AND dayDate = ?'
        );
        $stmt->bind_param('iss', $eventId, $walkinName, $dayDate);
        $stmt->execute();
        $stmt->close();
        // The NAME is left out on purpose, as the "marked" record above leaves
        // it out: a walk-in's name is somebody's personal detail and the
        // activity log is not where it belongs. Event and day are enough to
        // follow what happened.
        Logger::activity('EventAttendanceUnmarked', 'Event #' . $eventId . ' day ' . $dayDate . ' walk-in');
    }
}

header('Location: ' . $redirect, true, 302);
exit();
