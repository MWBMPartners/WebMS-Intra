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
 * SIX actions. The first files a NEW request, so it names none:
 *   file_visitor
 *            file a request for a VISITOR's e-mail address - somebody who
 *            suggested an event without signing in and has no account (#964,
 *            D-140). Only after the administrator ticks that they checked,
 *            outside the portal, that it is really that person, and writes a
 *            short note on how (D-148). Filed at
 *            "pending review"; nothing is erased until Execute is pressed.
 * The other five are each only for a request of the organisation being looked at:
 *   execute  run a request that is at "pending review";
 *   finish_by_hand
 *            run it once more and, ONLY IF every failure in that run is one no
 *            further run can clear, close it with a record that somebody dealt
 *            with those rows outside the portal (#710). It shares every step
 *            below with execute; only the ending differs;
 *   cancel   cancel one that has not been run yet;
 *   putback  put one stuck at "processing" back to "pending review", when its
 *            run stopped without finishing (roll-out package A2a);
 *   confirm  confirm, on the person's behalf, one still waiting for them to
 *            click the emailed link (roll-out package A2a).
 *
 * ADDRESSES: every redirect, link and form target here is built by
 * Router::url(), so it stays inside the organisation's own address. They
 * used to be written out ("/account/my-data"), which lands on the DEFAULT
 * organisation when organisations are told apart by the first part of the
 * address (roll-out package B2b, 12 October 2026; part of #536).
 * tools/audit-checks/check_site_aware_addresses.py holds this file to it.
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
use Portal\Core\ErasureReview;
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

if ($action === 'file_visitor') {
    // 🧾 #964, D-140. GdprEraser decides everything - whether the box was
    //    ticked, whether it is an address, whether one is already open - so
    //    this page keeps no copy of any of it. `is_string()` first: a request
    //    chooses the TYPE of what it sends, and `visitorEmail[]=x` must be "not
    //    an address", never "Array" or a warning.
    //
    //    The activity record names the request by number and NEVER the
    //    address: every administrator reads that log, and the address is the
    //    very thing being erased.
    $typed   = $_POST['visitorEmail'] ?? '';
    $address = is_string($typed) === true ? $typed : '';
    $checked = ($_POST['checkedOffPortal'] ?? '') === '1';
    // D-148: how the administrator checked, kept for ever with their number.
    $how     = $_POST['howChecked'] ?? '';
    $note    = is_string($how) === true ? $how : '';
    try {
        $filed = GdprEraser::fileVisitorAddressRequest($siteId, $address, $checked, $adminId, $note);
    } catch (\Throwable $e) {
        Logger::exception($e);
        $_SESSION['flash_msg']  = 'The request could not be filed, and nothing was saved. Try again in a minute; if it '
            . 'keeps happening, tell whoever looks after the portal.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Router::url('admin/erasure-requests'));
        exit();
    }
    $filedId = (int) $filed['requestID'];
    if ($filed['outcome'] === 'filed') {
        Logger::activity('GdprErasureVisitorFiled', 'Filed erasure request #' . $filedId
            . ' for the e-mail address of somebody who suggested an event without an account', $adminId);
        $_SESSION['flash_msg']  = 'Erasure request #' . $filedId . ' is filed for that address and is at pending review. '
            . 'Nothing has been erased yet: press Execute when you are ready. It reaches only the name and address '
            . 'given with event suggestions at this organisation, and the e-mails telling that address a suggestion '
            . 'was approved. It does not touch any account, even a member\'s using the same address: a member asks '
            . 'for their own erasure from their account.';
        $_SESSION['flash_type'] = 'success';
    } elseif ($filed['outcome'] === 'already-open') {
        $_SESSION['flash_msg']  = 'Nothing new was filed: erasure request #' . $filedId . ' for that address is already '
            . 'waiting or being carried out.';
        $_SESSION['flash_type'] = 'info';
    } elseif ($filed['outcome'] === 'no-note') {
        $_SESSION['flash_msg']  = 'Nothing was filed. Write a short note on how you checked that the request comes from '
            . 'the person who uses this address (for example "replied from that address"). It is kept with the '
            . 'request for ever, so do not put the person\'s details in it.';
        $_SESSION['flash_type'] = 'warning';
    } elseif ($filed['outcome'] === 'note-too-long') {
        $_SESSION['flash_msg']  = 'Nothing was filed: the note on how you checked is longer than '
            . GdprEraser::VISITOR_NOTE_MAX . ' characters. Keep it short - it is kept with the request for ever.';
        $_SESSION['flash_type'] = 'warning';
    } elseif ($filed['outcome'] === 'not-checked') {
        $_SESSION['flash_msg']  = 'Nothing was filed. Tick the box to say you have checked, outside the portal, that '
            . 'the request really comes from the person who uses this address. The portal cannot check that for you: '
            . 'whoever has the address in front of them could type it here.';
        $_SESSION['flash_type'] = 'warning';
    } else {
        $_SESSION['flash_msg']  = 'Nothing was filed: that does not look like an e-mail address.';
        $_SESSION['flash_type'] = 'warning';
    }
    header('Location: ' . Router::url('admin/erasure-requests'));
    exit();
}

$row = null;
$stmt = $db->prepare('SELECT requestID, userID, status, subjectEmail, subjectKind FROM tblErasureRequest WHERE requestID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $requestId, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($row === null) {
    $_SESSION['flash_msg']  = 'Erasure request not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('admin/erasure-requests'));
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
        header('Location: ' . Router::url('admin/erasure-requests'));
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
        header('Location: ' . Router::url('admin/erasure-requests'));
        exit();
    }
    Logger::activity('GdprErasureCancelled', 'Cancelled erasure request #' . $requestId, $adminId);
    $_SESSION['flash_msg']  = 'Erasure request cancelled.';
    $_SESSION['flash_type'] = 'info';
    header('Location: ' . Router::url('admin/erasure-requests'));
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
        header('Location: ' . Router::url('admin/erasure-requests'));
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
    header('Location: ' . Router::url('admin/erasure-requests'));
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
        header('Location: ' . Router::url('admin/erasure-requests'));
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
    header('Location: ' . Router::url('admin/erasure-requests'));
    exit();
}

// 🖐️ "Dealt with by hand" (#710) shares everything below with Execute: the
//    same claim, the same run, the same put-back on a stop. Only the ending
//    differs, which is why it is one branch here and not a second copy of
//    ninety lines.
$byHand = $action === 'finish_by_hand';

// 🧾 #964: a request for a visitor's e-mail address. Decided by the request's
//    own stated kind, never by an empty account number: every COMPLETED
//    request has one, and an old request whose account had gone would
//    otherwise be run against an address that may be a member's.
$isVisitor = (string) ($row['subjectKind'] ?? '') === GdprEraser::KIND_VISITOR_ADDRESS;
if ($isVisitor === true && $byHand === true) {
    // Nothing in a visitor request can need doing by hand: it has three steps,
    // none of which can refuse for ever, so "run it again" is always the
    // answer. Refused before the claim, so nothing is changed.
    $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is for a visitor\'s e-mail address, and nothing in '
        . 'such a request is ever left to be done by hand. Press Execute instead. Nothing was changed.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . Router::url('admin/erasure-requests'));
    exit();
}

if (($action !== 'execute' && $byHand === false) || (string) $row['status'] !== 'pending_review') {
    $_SESSION['flash_msg']  = 'Request is not in a reviewable state.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('admin/erasure-requests'));
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
    header('Location: ' . Router::url('admin/erasure-requests'));
    exit();
}
if ($claimed === false) {
    $_SESSION['flash_msg']  = 'Another admin is processing this request.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . Router::url('admin/erasure-requests'));
    exit();
}

if ($isVisitor === true) {
    // 🗑️ The three visitor steps (GdprEraser::executeVisitorAddress()), and
    //    the same three honest endings as an account's run below. The
    //    activity record never names the address.
    try {
        $outcome = GdprEraser::executeVisitorAddress($requestId, $adminId);
    } catch (\Throwable $e) {
        Logger::exception($e);
        Logger::activity('GdprErasureStopped', 'Erasure request #' . $requestId . ' for a visitor\'s e-mail address stopped before it finished', $adminId);
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' stopped before it finished. The steps already done '
            . 'are safe to repeat, and its report lists them. If the request shows as "pending review" below, run it '
            . 'again. If it shows as "processing", it could not be put back and needs setting back by whoever looks '
            . 'after the portal.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Router::url('admin/erasure-requests'));
        exit();
    }
    if ($outcome['failed'] > 0) {
        Logger::activity('GdprErasureIncomplete', 'Erasure request #' . $requestId . ' for a visitor\'s e-mail address: '
            . $outcome['failed'] . ' of ' . $outcome['steps'] . ' steps failed; request back at pending review', $adminId);
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is not complete: ' . $outcome['failed'] . ' of '
            . $outcome['steps'] . ' steps could not be done. The request is back at pending review, so run it again; '
            . 'its report shows each failed step and its reason.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . Router::url('admin/erasure-requests'));
        exit();
    }
    Logger::activity('GdprErasureExecuted', 'Executed erasure request #' . $requestId . ' for a visitor\'s e-mail address', $adminId);
    $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is completed: all ' . $outcome['steps'] . ' steps ran '
        . 'and none failed. Its report lists each step, and the audit chain can be verified there.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Router::url('admin/erasure-requests'));
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
    header('Location: ' . Router::url('admin/erasure-requests'));
    exit();
}

// 🗑️ Run it, and report what REALLY happened. Three outcomes and three
//    messages for Execute, and three more for "dealt with by hand" (#710),
//    which are handled in their own block further down.
//
//    All three go back to the LIST of requests, not to the report page. This
//    page used to send the administrator to the report, and the report page
//    never shows a message - so "Erasure completed" appeared later, on the
//    next visit to the list, about whichever request had been run last. The
//    list shows the message straight away, shows the request's real status
//    beside it, and has the Report button and (when the request is back at
//    pending review) the Execute button the message tells them to press.
try {
    $outcome = $byHand === true
        ? GdprEraser::finishByHand($requestId, $userId, $adminId)
        : GdprEraser::execute($requestId, $userId, $adminId);
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
    header('Location: ' . Router::url('admin/erasure-requests'));
    exit();
}

// 🖐️ The by-hand ending (#710). `GdprEraser::finishByHand()` has already run
//    the request and decided: there is deliberately no way to close one from
//    here. The audit line and the close are written in ONE TRANSACTION inside
//    that method, and nothing else may reach `closeRequest()`.
if ($byHand === true) {
    $hand = (array) ($outcome['handWorkOnly'] ?? []);
    if ((string) ($outcome['outcome'] ?? '') === 'closed-by-hand') {
        Logger::activity(
            'GdprErasureClosedByHand',
            'Erasure request #' . $requestId . ' for user #' . $userId . ' was closed after work done '
            . 'outside the portal; ' . (int) $outcome['failed'] . ' of ' . (int) $outcome['steps']
            . ' steps cannot be completed by any number of further runs',
            $adminId
        );
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is closed, and its report records '
            . 'that you dealt with the remaining rows yourself. ' . (int) $outcome['failed'] . ' of '
            . (int) $outcome['steps'] . ' step(s) could not be completed by any number of further runs; '
            . 'every other step ran and did not fail.';
        $_SESSION['flash_type'] = 'success';
        header('Location: ' . Router::url('admin/erasure-requests'));
        exit();
    }
    if ((string) ($outcome['outcome'] ?? '') === 'finished-normally') {
        Logger::activity('GdprErasureExecuted', 'Erasure request #' . $requestId . ' for user #' . $userId
            . ' finished normally on a "dealt with by hand" press; nothing needed doing by hand', $adminId);
        $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is completed: this time every one of '
            . 'its ' . (int) $outcome['steps'] . ' steps ran and none failed, so there was nothing left for '
            . 'you to deal with by hand. Nothing was recorded as done outside the portal.';
        $_SESSION['flash_type'] = 'success';
        header('Location: ' . Router::url('admin/erasure-requests'));
        exit();
    }

    // ⛔ Something recoverable also failed, so the request is NOT closed.
    //    Saying how many of each is the point: "run it again" is still the
    //    right advice for the rest.
    Logger::activity(
        'GdprErasureByHandRefused',
        'Erasure request #' . $requestId . ': ' . (int) $outcome['failed'] . ' step(s) failed and only '
        . count($hand) . ' of them permanently, so it was not closed',
        $adminId
    );
    $_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' was NOT closed, and nothing was recorded '
        . 'as done by hand. ' . (int) $outcome['failed'] . ' step(s) failed in that run and only '
        . count($hand) . ' of them for a reason that running the request again cannot clear. The rest may '
        . 'well work next time, so run it again; its report shows each failed step and its reason. This '
        . 'button only closes a request when EVERY remaining failure is one no further run can fix.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . Router::url('admin/erasure-requests'));
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
        . 'not help until that is done. '
        // 🧷 The one reason that NO number of further runs can clear (#709). Without
        //    this, the sentence above sends somebody round a loop that cannot end:
        //    a parent-matched step whose link an earlier run already cut refuses on
        //    every run, so the request can never reach "completed".
        . 'A reason saying the rows must be CHECKED BY HAND will not be helped by running it again at all: '
        . 'those rows can no longer be found automatically, so they have to be dealt with directly. Once you '
        . 'have, press "Dealt with by hand" on the request — it closes the request and records that you did '
        . 'that work, but only if every remaining failure is one no further run can fix (#710). '
        // 🧑‍⚖️ #724 — THE ONE FAILURE "RUN IT AGAIN" CANNOT CLEAR EITHER, and
        //    naming it here is the fix of 3 November 2026. A run refused
        //    because lines of free text are undecided fails identically on
        //    every retry until somebody DECIDES them, and this message said
        //    only "run it again". The honest sentence existed — the one
        //    `GdprEraser::refuseUntilReviewed()` writes — but only on the
        //    request's own report, which this message points at for a
        //    different case. It also cannot be closed with "Dealt with by
        //    hand": that button needs every remaining failure to be one no
        //    further run can fix, and this one clears the moment somebody
        //    decides.
        . 'And a reason saying LINES OF FREE TEXT have not been decided about is not cleared by running it '
        . 'again either, nor by "Dealt with by hand": somebody holding the Data Controller role has to open '
        . ErasureReview::PAGE_ROUTE . ', look at each line and choose — empty it, or keep it. Running the '
        . 'request again works once they have. If nobody holds that role, a global administrator grants it on '
        . 'the roles page (Admin, Roles).';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . Router::url('admin/erasure-requests'));
    exit();
}

Logger::activity('GdprErasureExecuted', 'Executed erasure request #' . $requestId . ' for user #' . $userId, $adminId);

$_SESSION['flash_msg']  = 'Erasure request #' . $requestId . ' is completed: all ' . $outcome['steps'] . ' steps ran '
    . 'and none failed. Its report lists each step, and the audit chain can be verified there.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . Router::url('admin/erasure-requests'));
exit();
