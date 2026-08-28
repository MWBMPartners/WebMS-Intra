<?php
// Path: _apps/small-groups/meeting-save.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Meeting Save Handler 📆💾
 * -----------------------------------------------------------------------------
 * POST-only + CSRF + `SmallGroups::canManage()` on the site-fetched group.
 * Upserts the meeting on `UNIQUE (groupID, meetingDate)`, then syncs the
 * per-person roll inside a transaction, validating every posted userID is
 * an ACTIVE member of THIS group before writing (never trust the checkbox
 * list). The optional Attendance push runs AFTER commit — best-effort, a
 * push failure flashes a warning but never rolls back the roll save.
 *
 * bind_param arity (literal type-strings, check_bind_param_arity.py is the
 * source of truth):
 *   meeting upsert  — 8  ('iissssii': siteID, groupID, meetingDate,
 *                     meetingTime, topic, notes, visitorCount, recordedByID)
 *   mark insert     — 3  ('iii': meetingID, userID, markedByID)
 *   unmark delete   — 2  ('ii': meetingID, userID)
 *
 * @package   Portal\SmallGroups
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/150
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\SmallGroups;

Auth::ensureSession();
Auth::requireLogin();

if (SmallGroups::isEnabled() === false) {
    Router::renderError(404);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /small-groups');
    exit();
}

$siteId  = Site::id();
$user    = App::user();
$userId  = (int) ($user['userID'] ?? 0);
$groupId = (int) ($_POST['groupID'] ?? 0);
$backUrl = '/small-groups/group?id=' . $groupId;

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backUrl);
    exit();
}

$group = SmallGroups::getGroup($siteId, $groupId);
if ($group === null) {
    $_SESSION['flash_msg']  = 'Group not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /small-groups');
    exit();
}
if (SmallGroups::canManage($siteId, $groupId) === false) {
    Router::renderError(403);
    return;
}

$meetingDate = trim((string) ($_POST['meetingDate'] ?? ''));
if ($meetingDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate) !== 1) {
    $_SESSION['flash_msg']  = 'A valid meeting date is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backUrl);
    exit();
}
$meetingTime = trim((string) ($_POST['meetingTime'] ?? ''));
$meetingTime = $meetingTime !== '' ? $meetingTime : null;
$topic = trim((string) ($_POST['topic'] ?? ''));
$topic = $topic !== '' ? mb_substr($topic, 0, 255) : null;
$notes = trim((string) ($_POST['notes'] ?? ''));
$notes = $notes !== '' ? $notes : null;
$visitorCount = max(0, (int) ($_POST['visitorCount'] ?? 0));
$recordedByID = $userId > 0 ? $userId : null;
$wantsPush = isset($_POST['pushAttendance']);

// 📋 Whitelist of posted present-userIDs restricted to ACTIVE members of
// THIS group — never trust the raw checkbox list from the client.
$activeMemberIds = [];
foreach (SmallGroups::membersOf($siteId, $groupId, 'active') as $m) {
    $activeMemberIds[(int) $m['userID']] = true;
}
$postedPresent = array_map('intval', (array) ($_POST['present'] ?? []));
$presentUserIds = [];
foreach ($postedPresent as $pid) {
    if (isset($activeMemberIds[$pid]) === true) {
        $presentUserIds[$pid] = true;
    }
}

$db = App::db();
$meetingId = 0;

try {
    App::beginTransaction();

    // -------------------------------------------------------------------
    // 📆 Upsert the meeting row — UNIQUE (groupID, meetingDate).
    // -------------------------------------------------------------------
    $stmt = $db->prepare(
        'INSERT INTO tblSmallGroupMeetings '
        . '(siteID, groupID, meetingDate, meetingTime, topic, notes, visitorCount, recordedByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE '
        . 'meetingTime = VALUES(meetingTime), topic = VALUES(topic), notes = VALUES(notes), '
        . 'visitorCount = VALUES(visitorCount), recordedByID = VALUES(recordedByID)'
    );
    if ($stmt === false) {
        throw new \RuntimeException('prepare failed');
    }
    // 8 placeholders: i i s s s s i i
    $stmt->bind_param(
        'iissssii',
        $siteId, $groupId, $meetingDate, $meetingTime, $topic, $notes, $visitorCount, $recordedByID
    );
    $stmt->execute();
    $stmt->close();

    // 🔎 Resolve meetingID — insert_id is only reliable on the INSERT
    // branch; a duplicate-key UPDATE leaves insert_id at 0, so look the
    // row up directly either way.
    $find = $db->prepare('SELECT meetingID FROM tblSmallGroupMeetings WHERE groupID = ? AND meetingDate = ? LIMIT 1');
    if ($find === false) {
        throw new \RuntimeException('prepare failed');
    }
    $find->bind_param('is', $groupId, $meetingDate);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();
    if ($row === null) {
        throw new \RuntimeException('meeting row missing after upsert');
    }
    $meetingId = (int) $row['meetingID'];

    // -------------------------------------------------------------------
    // ✅ Roll sync — insert missing marks, delete unticked.
    // -------------------------------------------------------------------
    $existingMarked = [];
    $exStmt = $db->prepare('SELECT userID FROM tblSmallGroupMeetingAttendance WHERE meetingID = ? AND userID IS NOT NULL');
    if ($exStmt === false) {
        throw new \RuntimeException('prepare failed');
    }
    $exStmt->bind_param('i', $meetingId);
    $exStmt->execute();
    $exResult = $exStmt->get_result();
    while ($r = $exResult->fetch_assoc()) {
        $existingMarked[(int) $r['userID']] = true;
    }
    $exStmt->close();

    $toInsert = array_diff_key($presentUserIds, $existingMarked);
    $toDelete = array_diff_key($existingMarked, $presentUserIds);

    if (count($toInsert) > 0) {
        $insStmt = $db->prepare('INSERT INTO tblSmallGroupMeetingAttendance (meetingID, userID, markedByID) VALUES (?, ?, ?)');
        if ($insStmt === false) {
            throw new \RuntimeException('prepare failed');
        }
        foreach (array_keys($toInsert) as $uid) {
            // 3 placeholders: i i i
            $insStmt->bind_param('iii', $meetingId, $uid, $recordedByID);
            $insStmt->execute();
        }
        $insStmt->close();
    }

    if (count($toDelete) > 0) {
        $delStmt = $db->prepare('DELETE FROM tblSmallGroupMeetingAttendance WHERE meetingID = ? AND userID = ?');
        if ($delStmt === false) {
            throw new \RuntimeException('prepare failed');
        }
        foreach (array_keys($toDelete) as $uid) {
            // 2 placeholders: i i
            $delStmt->bind_param('ii', $meetingId, $uid);
            $delStmt->execute();
        }
        $delStmt->close();
    }

    App::commit();
} catch (\Throwable $e) {
    App::rollback();
    $_SESSION['flash_msg']  = 'Could not save the meeting roll.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backUrl);
    exit();
}

Logger::activity('SmallGroupMeetingRecorded', 'Recorded meeting for group ' . $group['groupName'] . ' on ' . $meetingDate, $userId > 0 ? $userId : null);

// -----------------------------------------------------------------------------
// 📊 Optional additive Attendance push — AFTER commit, best-effort. Never
// rolls back the roll save on failure (venue-overlay resilience precedent —
// pushHeadcountToAttendance() itself is also wrapped try/catch).
// -----------------------------------------------------------------------------
if ($wantsPush === true) {
    $sessionId = SmallGroups::pushHeadcountToAttendance($siteId, $meetingId);
    if ($sessionId === null) {
        $_SESSION['flash_msg']  = 'Meeting roll saved, but the Attendance headcount could not be pushed.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . $backUrl);
        exit();
    }
}

$_SESSION['flash_msg']  = 'Meeting roll saved.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . $backUrl);
exit();
