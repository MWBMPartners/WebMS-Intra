<?php
// Path: _apps/admin/erasure/process.php
/**
 * Admin — execute or cancel an erasure request.
 *
 * After a run this page tells the administrator what actually happened, in
 * one of three plain messages (#510). It used to say "Erasure completed"
 * whatever had happened, and a run that crashed showed a server error.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/510
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GdprEraser;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db        = App::db();
$siteId    = Site::id();
$adminId   = (int) ($_SESSION['user_id'] ?? 0);
$requestId = (int) ($_POST['requestID'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');

$row = null;
$stmt = $db->prepare('SELECT requestID, userID, status, subjectEmail FROM tblErasureRequest WHERE requestID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $requestId, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($row === null) {
    $_SESSION['flash_msg']  = 'Erasure request not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/erasure-requests');
    exit();
}

if ($action === 'cancel') {
    $u = $db->prepare('UPDATE tblErasureRequest SET status = "cancelled", processedAt = NOW(), processedByID = ? WHERE requestID = ?');
    if ($u !== false) {
        $u->bind_param('ii', $adminId, $requestId);
        $u->execute();
        $u->close();
    }
    Logger::activity('GdprErasureCancelled', 'Cancelled erasure request #' . $requestId, $adminId);
    $_SESSION['flash_msg']  = 'Erasure request cancelled.';
    $_SESSION['flash_type'] = 'info';
    header('Location: /admin/erasure-requests');
    exit();
}

if ($action !== 'execute' || (string) $row['status'] !== 'pending_review') {
    $_SESSION['flash_msg']  = 'Request is not in a reviewable state.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/erasure-requests');
    exit();
}

// Flip to processing first so a concurrent click can't double-run.
$lock = $db->prepare('UPDATE tblErasureRequest SET status = "processing" WHERE requestID = ? AND status = "pending_review"');
$claimed = false;
if ($lock !== false) {
    $lock->bind_param('i', $requestId);
    $lock->execute();
    $claimed = $lock->affected_rows > 0;
    $lock->close();
}
if ($claimed === false) {
    $_SESSION['flash_msg']  = 'Another admin is processing this request.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/erasure-requests');
    exit();
}

$userId = (int) $row['userID'];
if ($userId === 0) {
    $u = $db->prepare('UPDATE tblErasureRequest SET status = "failed" WHERE requestID = ?');
    if ($u !== false) {
        $u->bind_param('i', $requestId);
        $u->execute();
        $u->close();
    }
    $_SESSION['flash_msg']  = 'No user attached to this request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/erasure-requests');
    exit();
}

// 🗑️ Run it, and report what REALLY happened. Three outcomes, three messages.
//
//    All three go back to the LIST of requests, not to the report page. This
//    page used to send the administrator to the report, and the report page
//    never shows a message - so "Erasure completed" appeared later, on the
//    next visit to the list, about whichever request had been run last. The
//    list shows the message straight away, shows the request's real status
//    beside it, and has the Report button and (when the request is back at
//    pending review) the Execute button the message tells them to press.
try {
    $outcome = GdprEraser::execute($requestId, $userId, $adminId);
} catch (\Throwable $e) {
    // The run had to stop before the end - in practice because its audit
    // trail could not be written. GdprEraser has already tried to put the
    // request back to pending review; the full reason, including whether
    // that worked, goes to the error log for whoever looks after the portal.
    // The administrator gets the short version, and the list shows where
    // the request really is.
    Logger::exception($e);
    Logger::activity('GdprErasureStopped', 'Erasure request #' . $requestId . ' for user #' . $userId . ' stopped before it finished', $adminId);
    // Worded with care. It used to begin "Nothing was lost: every step
    // already done is recorded", which claims more than is true: statement
    // files deleted just before the stop may have no line in the report.
    $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' stopped before it finished. The steps already done '
        . 'are safe to repeat, and its report lists them (giving-statement files deleted just before the stop may be '
        . 'missing from it). If the request shows as "pending review" below, run it again. If it shows as '
        . '"processing", it could not be put back and needs setting back by whoever looks after the portal.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/erasure-requests');
    exit();
}

if ($outcome['failed'] > 0) {
    Logger::activity(
        'GdprErasureIncomplete',
        'Erasure request #' . $requestId . ' for user #' . $userId . ': ' . $outcome['failed'] . ' of '
        . $outcome['steps'] . ' steps failed; request back at pending review',
        $adminId
    );
    // Says what to do when "run it again" cannot work. A step that fails
    // because the database is missing a column fails every time, and the
    // first version of this message only ever said to run it again.
    $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is not complete: ' . $outcome['failed'] . ' of '
        . $outcome['steps'] . ' steps could not be done. The request is back at pending review, so run it again. '
        . 'If the same step fails again, open its report: each step marked "failed" shows its reason. A reason that '
        . 'names a missing column means the database needs its upgrade first, and running the request again will '
        . 'not help until that is done.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/erasure-requests');
    exit();
}

Logger::activity('GdprErasureExecuted', 'Executed erasure request #' . $requestId . ' for user #' . $userId, $adminId);

$_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is completed: all ' . $outcome['steps'] . ' steps ran '
    . 'and none failed. Its report lists each step, and the audit chain can be verified there.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/erasure-requests');
exit();
