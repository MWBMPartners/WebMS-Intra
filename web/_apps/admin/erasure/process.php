<?php
// Path: _apps/admin/erasure/process.php
/**
 * Admin — execute or cancel an erasure request, or put back one whose run
 * stopped part way.
 *
 * After a run this page tells the administrator what actually happened, in
 * one of three plain messages (#510). It used to say "Erasure completed"
 * whatever had happened, and a run that crashed showed a server error.
 *
 * Four actions, each only for a request of the organisation being looked at:
 *   execute  run a request that is at "pending review";
 *   cancel   cancel one that has not been run yet;
 *   putback  put one stuck at "processing" back to "pending review", when its
 *            run stopped without finishing (roll-out package A2a);
 *   confirm  confirm, on the person's behalf, one still waiting for them to
 *            click the emailed link (roll-out package A2a).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.3.0
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
    // Only a request that has NOT been run can be cancelled. The statement
    // used to have no test of the status at all, so a hand-made form post
    // could "cancel" a request that was already completed: its status then
    // said the erasure had not happened, when it had. The test is in the
    // UPDATE itself, so a request that changes state between the page being
    // read and the button being pressed is still refused.
    //
    // 🧾 AND NOT ONE WHOSE RUN HAS ALREADY CHANGED SOMETHING (roll-out
    //    package A2b, after the two independent checks of 12 October 2026).
    //    A run that stops part way puts its request back to "pending
    //    review", which the test below allows to be cancelled. But by then
    //    the run may have deleted the person's organisation membership,
    //    taken their name off gifts, or (about half way through) emptied
    //    their account. None of that can be put back: rows are gone, and
    //    which gifts lost their donor is recorded nowhere. Cancelling would
    //    leave a request that says nothing happened beside a person with
    //    part of their records gone. So the cancel is refused, with the
    //    reason, and the request has to be run again to its end.
    //
    //    The first version of this refused only when the GIVING records had
    //    been dealt with. A member with no giving records whose run had
    //    already deleted their membership could still be cancelled.
    //
    //    What this cannot do: stop somebody changing the status by hand in
    //    the database. Nothing kept for tax reasons is cleared in that case
    //    either, for as long as the account has not been emptied
    //    (GivingRetention::stateOfKeptRow()).
    //
    //    Asked only of a request the cancel below would otherwise accept. A
    //    completed request is refused further down, in its own words: "run
    //    it again to finish it" would be the wrong thing to tell somebody
    //    about a request that has finished.
    $stillWaiting = in_array((string) $row['status'], ['pending_confirmation', 'pending_review'], true);
    if ($stillWaiting === true && GdprEraser::runHasChangedAnything($requestId) === true) {
        Logger::activity('GdprErasureCancelRefused', 'Refused to cancel erasure request #' . $requestId
            . ': a run of it has already changed some of the person\'s records', $adminId);
        $_SESSION['flash_msg']  = 'This request cannot be cancelled: a run of it has already removed or changed some of '
            . 'this person\'s records and then stopped. What that run changed cannot be put back, so cancelling would '
            . 'leave them with part of their records gone and a request that says nothing happened. '
            . 'Run the request again to finish it. Nothing was changed.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: /admin/erasure-requests');
        exit();
    }
    $cancelled = false;
    $u = $db->prepare(
        'UPDATE tblErasureRequest SET status = "cancelled", processedAt = NOW(), processedByID = ? '
        . 'WHERE requestID = ? AND siteID = ? AND status IN ("pending_confirmation", "pending_review")'
    );
    if ($u !== false) {
        $u->bind_param('iii', $adminId, $requestId, $siteId);
        $u->execute();
        $cancelled = $u->affected_rows > 0;
        $u->close();
    }
    if ($cancelled === false) {
        $_SESSION['flash_msg']  = 'This request can no longer be cancelled: it is "'
            . str_replace('_', ' ', (string) $row['status']) . '". Only a request that has not been run yet can be '
            . 'cancelled. Nothing was changed.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: /admin/erasure-requests');
        exit();
    }
    Logger::activity('GdprErasureCancelled', 'Cancelled erasure request #' . $requestId, $adminId);
    $_SESSION['flash_msg']  = 'Erasure request cancelled.';
    $_SESSION['flash_type'] = 'info';
    header('Location: /admin/erasure-requests');
    exit();
}

if ($action === 'confirm') {
    // ✅ A request waiting at "pending confirmation" because the person never
    //    clicked the emailed link - usually because the email could not be
    //    sent. Nobody could move it on before this. The administrator is
    //    stating that THEY have checked the request is the person's; the
    //    prompt on the button says so, and GdprEraser writes that claim, and
    //    this administrator's account number, into the request's audit trail.
    try {
        $result = GdprEraser::confirmOnBehalf($requestId, $siteId, $adminId);
    } catch (\Throwable $e) {
        Logger::exception($e);
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' could not be confirmed, and nothing was changed. '
            . 'Try again in a minute; if it keeps happening, tell whoever looks after the portal.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/erasure-requests');
        exit();
    }
    if ($result === 'confirmed') {
        Logger::activity('GdprErasureConfirmedOnBehalf', 'Confirmed erasure request #' . $requestId . ' on the person\'s behalf', $adminId);
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is confirmed and is now at pending review. Its '
            . 'report records that you confirmed it on the person\'s behalf. Nothing has been erased: press Execute '
            . 'when you are ready to carry it out.';
        $_SESSION['flash_type'] = 'success';
    } else {
        // 'not-waiting': the person clicked their link, or the request was
        // cancelled, while this page was open. ('not-found' cannot happen
        // here: the request was read for this organisation a moment ago.)
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is no longer waiting to be confirmed (it is "'
            . str_replace('_', ' ', (string) $row['status']) . '" or has just changed), so there was nothing to do. '
            . 'Nothing was changed.';
        $_SESSION['flash_type'] = 'info';
    }
    header('Location: /admin/erasure-requests');
    exit();
}

if ($action === 'putback') {
    // ↩️ A request left at "processing" by a run that stopped part way (the
    //    web host killed the page, for example). Nothing could put one back
    //    before this, so it stayed stuck until somebody edited the database.
    //    GdprEraser decides whether it is safe, and says why when it is not.
    try {
        $result = GdprEraser::putBackStuckRequest($requestId, $siteId);
    } catch (\Throwable $e) {
        Logger::exception($e);
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' could not be put back, and nothing was changed. '
            . 'If this portal has just been updated, run the database upgrade (Admin, Upgrade) and try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/erasure-requests');
        exit();
    }
    if ($result === 'put-back') {
        Logger::activity('GdprErasurePutBack', 'Put erasure request #' . $requestId . ' back from processing to pending review', $adminId);
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is back at pending review. Its earlier run stopped '
            . 'before it finished. Every step is safe to repeat, so press Execute to run it again: it does whatever '
            . 'is left.';
        $_SESSION['flash_type'] = 'success';
    } elseif ($result === 'still-going') {
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' was not put back: a run of it showed signs of '
            . 'life in the last ' . GdprEraser::STUCK_AFTER_MINUTES . ' minutes, so it may still be going. Wait '
            . GdprEraser::STUCK_AFTER_MINUTES . ' minutes and try again. Nothing was changed.';
        $_SESSION['flash_type'] = 'warning';
    } else {
        // 'not-processing': it finished, or was put back, while this page
        // was open. ('not-found' cannot happen here: the request was read
        // for this organisation a moment ago.)
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is no longer at "processing", so there was '
            . 'nothing to put back. Nothing was changed.';
        $_SESSION['flash_type'] = 'info';
    }
    header('Location: /admin/erasure-requests');
    exit();
}

if ($action !== 'execute' || (string) $row['status'] !== 'pending_review') {
    $_SESSION['flash_msg']  = 'Request is not in a reviewable state.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/erasure-requests');
    exit();
}

// Flip to processing first so a concurrent click can't double-run, and note
// the moment (in UTC; see GdprEraser::stillGoing() for why not the server's
// clock). That moment is what later lets a request whose run was killed be
// told apart from one still running.
//
// A database that has not had migration 208 has no `claimedAt` column. The
// run is refused there with a plain message, rather than started with no way
// of telling later whether it is still going. Nothing has been changed at
// that point, so the request is still at pending review.
$claimed = false;
try {
    $lock = $db->prepare('UPDATE tblErasureRequest SET status = "processing", claimedAt = UTC_TIMESTAMP() WHERE requestID = ? AND status = "pending_review"');
    if ($lock !== false) {
        $lock->bind_param('i', $requestId);
        $lock->execute();
        $claimed = $lock->affected_rows > 0;
        $lock->close();
    }
} catch (\mysqli_sql_exception $e) {
    Logger::exception($e);
    // MySQL error 1054 is "no such column": the upgrade has not been run.
    // Anything else is said plainly as what it is, not guessed at.
    $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' was not started, and nothing was changed. '
        . ((int) $e->getCode() === 1054
            ? 'This portal\'s database needs its upgrade first (Admin, Upgrade). Run that, then press Execute again.'
            : 'The database refused to start it. Try again in a minute; if it keeps happening, tell whoever looks '
                . 'after the portal.');
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/erasure-requests');
    exit();
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
