<?php
// Path: public_html/prayer-requests/moderate.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Requests — Moderation Action Handler 🛡️
 * -----------------------------------------------------------------------------
 * POST endpoint that executes moderator actions on a single request:
 *   • approve                 — pending → active
 *   • archive                 — * → archived
 *   • answer                  — active → answered (optional testimony)
 *   • visibility-leadership   — flip visibility to leadership
 *   • visibility-congregation — flip visibility to congregation
 *   • assign                  — set/clear the prayer-chain partner (#311)
 *   • save-note               — admin edit of the private partner note (#311,
 *                               migration 148). Admin-only by construction —
 *                               this whole handler already requires
 *                               App::isAdmin() below, so no extra ACL check
 *                               is needed for this one action.
 *
 * @package   Portal\PrayerRequests
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\PrayerChain;
use Portal\Core\Site;

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /prayer-requests/manage', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🔐 CSRF
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('PrayerRequestModRejected', 'Invalid CSRF on prayer-requests/moderate');
    header('Location: /prayer-requests/manage', true, 302);
    exit();
}

$siteId    = Site::id();
$user      = App::user();
$modId     = (int) ($user['userID'] ?? 0);
$requestId = (int) ($_POST['requestID'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');
$testimony = trim((string) ($_POST['testimony'] ?? ''));

$redirect      = (string) ($_POST['redirect'] ?? 'manage');
$statusFilter  = (string) ($_POST['status_filter'] ?? '');

if ($requestId <= 0 || $action === '') {
    header('Location: /prayer-requests/manage', true, 302);
    exit();
}

// 🔍 Load the row (siteID-scoped — prevents cross-site tampering)
$stmt = $mysqli->prepare(
    'SELECT requestID, status, visibility, assignedToUserID FROM tblPrayerRequests '
    . 'WHERE siteID = ? AND requestID = ? LIMIT 1'
);
$row = null;
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($row === null) {
    header('Location: /prayer-requests/manage', true, 302);
    exit();
}

// 🙏 Previous assignee (#311) — used to (a) decide whether a reassignment
//    actually changed anything (skip duplicate notifications) and (b)
//    clear the private partner note when the assignee CHANGES, so a note
//    written for one partner never leaks to their successor.
$previousAssignee = $row['assignedToUserID'] !== null ? (int) $row['assignedToUserID'] : null;

// 🛠️ Decide the SQL update
$newStatus     = null;
$newVisibility = null;
$setAnswered   = false;
$setTestimony  = null;
// 🙏 Prayer chain partner assignment (#311). NULL = no change; 0 = unassign;
//    >0 = assign to that user.
$assignToUserId = null;
// 🔒 Private partner note (#311, migration 148). Admin-only action — this
//    whole handler already requires App::isAdmin() above, so no further
//    ACL gate is needed here. $updatePartnerNote distinguishes "no change
//    requested" from "explicitly clear the note" (both leave the *value*
//    null, so a plain null check on the value alone isn't enough).
$updatePartnerNote = false;
$partnerNoteToSet   = null;

switch ($action) {
    case 'approve':
        $newStatus = 'active';
        break;
    case 'archive':
        $newStatus = 'archived';
        break;
    case 'answer':
        $newStatus   = 'answered';
        $setAnswered = true;
        if ((App::settings('prayer-requests.allowTestimony') ?? 'true') === 'true'
            && $testimony !== ''
        ) {
            $setTestimony = mb_substr($testimony, 0, 4000);
        }
        break;
    case 'visibility-leadership':
        $newVisibility = 'leadership';
        break;
    case 'visibility-congregation':
        if ((App::settings('prayer-requests.allowCongregationFeed') ?? 'true') === 'true') {
            $newVisibility = 'congregation';
        } else {
            $newVisibility = 'leadership';
        }
        break;
    case 'assign':
        // 🙏 Assign (or unassign) to a prayer partner (#311). The partner
        //    sees the request at /account/my-prayer-list. We accept 0 to
        //    explicitly unassign; positive IDs go through a foreign-key
        //    check at DB level (fk_pr_assigned).
        $assignToUserId = (int) ($_POST['assignedToUserID'] ?? 0);
        if ($assignToUserId < 0) {
            $assignToUserId = 0;
        }
        break;
    case 'save-note':
        // 🔒 Admin edit of the private partner note (#311, migration 148).
        $updatePartnerNote = true;
        $partnerNoteToSet  = mb_substr(trim((string) ($_POST['partnerNote'] ?? '')), 0, 4000);
        if ($partnerNoteToSet === '') {
            $partnerNoteToSet = null;
        }
        break;
    default:
        header('Location: /prayer-requests/manage', true, 302);
        exit();
}

// 🛠️ Build dynamic UPDATE
$set    = ['moderatorID = ?', 'moderatedAt = NOW()'];
$types  = 'i';
$params = [$modId];

if ($newStatus !== null) {
    $set[]    = 'status = ?';
    $types   .= 's';
    $params[] = $newStatus;
}
if ($newVisibility !== null) {
    $set[]    = 'visibility = ?';
    $types   .= 's';
    $params[] = $newVisibility;
}
if ($setAnswered === true) {
    $set[] = 'answeredAt = NOW()';
}
if ($setTestimony !== null) {
    $set[]    = 'testimony = ?';
    $types   .= 's';
    $params[] = $setTestimony;
}
// 🙏 Prayer chain assignment (#311). 0 means "unassign"; >0 means set the
//    specified userID + stamp assignedAt to NOW.
$reassigned = false;
if ($assignToUserId !== null) {
    $reassigned = $assignToUserId !== ($previousAssignee ?? 0);
    if ($assignToUserId === 0) {
        $set[] = 'assignedToUserID = NULL';
        $set[] = 'assignedAt = NULL';
    } else {
        $set[]    = 'assignedToUserID = ?';
        $types   .= 'i';
        $params[] = $assignToUserId;
        $set[]    = 'assignedAt = NOW()';
    }
    // 🔒 The assignee changed (including to/from "unassigned") — clear the
    //    private note + prayed-for stamp so they never carry over to
    //    whoever holds the assignment next (#311 ACL: a note is only ever
    //    visible to the partner it was written for, or an admin).
    if ($reassigned === true) {
        $set[] = 'partnerNote = NULL';
        $set[] = 'partnerLastPrayedAt = NULL';
    }
}
// 🔒 Admin edit of the private partner note (#311, migration 148).
if ($updatePartnerNote === true) {
    $set[]    = 'partnerNote = ?';
    $types   .= 's';
    $params[] = $partnerNoteToSet;
}

$sql = 'UPDATE tblPrayerRequests SET ' . implode(', ', $set)
     . ' WHERE siteID = ? AND requestID = ? LIMIT 1';
$types   .= 'ii';
$params[] = $siteId;
$params[] = $requestId;

$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PR_MOD_UPDATE_PREP', $mysqli->error, '');
    header('Location: /prayer-requests/manage', true, 302);
    exit();
}
$stmt->bind_param($types, ...$params);
$ok = $stmt->execute();
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PR_MOD_UPDATE_FAIL', $mysqli->error, '');
} else {
    Logger::activity(
        'PrayerRequestModerated',
        'Request #' . $requestId . ' action=' . $action
    );

    // 📣 Notify the newly-assigned partner (#311). Only when the assignee
    //    actually changed (skip a no-op resubmit of the same partner) and
    //    only when we assigned TO someone (not an unassign). notifyAssignment()
    //    swallows its own failures — this call can never abort the request
    //    above, which has already committed.
    if ($action === 'assign' && $assignToUserId !== null && $assignToUserId > 0 && $reassigned === true) {
        PrayerChain::notifyAssignment($siteId, $requestId, $assignToUserId);
    }
}

// 🔀 Redirect back to the right place
if ($redirect === 'view') {
    header('Location: /prayer-requests/view?id=' . $requestId, true, 302);
} else {
    $qs = $statusFilter !== '' ? '?status=' . urlencode($statusFilter) : '';
    header('Location: /prayer-requests/manage' . $qs, true, 302);
}
exit();
