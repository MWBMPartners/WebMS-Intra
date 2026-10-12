<?php
// Path: web/_apps/expenses/withdraw/save.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Claim Withdrawal Save Handler 🔄
 * -----------------------------------------------------------------------------
 * Handles withdrawal of a pending expense claim by the claimant. Only the
 * original submitter can withdraw, and only while the claim is still Pending.
 * Updates status to 'Withdrawn', logs activity, sends email notification to
 * approvers, and redirects back to the claim view.
 *
 * THE RULES (owner's decision, Salem874, 12 October 2026)
 * - Only the person who made the claim may withdraw it. An approver or an
 *   administrator who wants a claim gone rejects it instead.
 * - Only while it is still Pending. A claim that is approved, paid, rejected
 *   or already withdrawn is refused. A claim that one of two approvers has
 *   approved is still Pending, so it may be withdrawn.
 * - Withdrawn is final. There is no undo: the person makes a new claim.
 * - Nothing is deleted. The claim, its receipts, its line items and any
 *   approvals already given are kept.
 *
 * THIS PAGE NEVER WORKED BEFORE MIGRATION 211 (#587)
 * The column `tblExpenseClaims.status` is a fixed list of choices, and
 * 'Withdrawn' was not on it. On a database server with strict checking the
 * UPDATE below was refused and the person saw "Error withdrawing claim". On a
 * server without strict checking it was worse: the server stored an EMPTY
 * status and reported success, so this page said "withdrawn" and the claim
 * then dropped off the approver's list and the dashboard, and showed
 * elsewhere with a blank status. Nothing caught it because the only test
 * looked at where the person was sent, not at what was stored. Migration
 * 211 adds the choice, and turns any empty status left behind into
 * 'Withdrawn'.
 *
 * ONCE THE CLAIM IS STORED AS WITHDRAWN, THE PERSON IS TOLD SO
 * The activity line and the e-mail to approvers are written AFTER the
 * withdrawal is stored, and outside the block that stores it. If either
 * fails, the failure is recorded for the administrator (Admin, Errors) and
 * the person still reads "withdrawn", because that is what is true. They
 * used to sit inside that block: a failed e-mail then showed "Error
 * withdrawing claim. Please try again." about a claim that WAS withdrawn,
 * and trying again answered "cannot be withdrawn".
 *
 * WHAT THIS PAGE DOES NOT DO
 * - It does not tell the person when the e-mail to approvers could not be
 *   sent. The claim leaves the approvers' list either way.
 * - It does not read back what the database stored. Until migration 211 has
 *   run, a press on a server without strict checking still stores an empty
 *   status. That migration repairs such a row when it does run.
 * - It does not change the claim's stored document, which keeps the word it
 *   was last made with.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.3
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/587
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\ExpenseMailer;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Session and CSRF checks
Auth::ensureSession();
Auth::requireLogin();

$claimID = (int) ($_POST['claimID'] ?? 0);

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid CSRF token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/view') . '?id=' . $claimID);
    exit();
}

// 📝 Validate claim ID
if ($claimID <= 0) {
    $_SESSION['flash_msg']  = 'Invalid claim ID.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/submit'));
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 📋 Fetch claim and verify ownership + status
// -----------------------------------------------------------------------------
$claim = null;
$stmt = $mysqli->prepare(
    'SELECT EC.claimID, EC.userID, EC.status, EC.claimTitle, EC.deptID '
    . 'FROM tblExpenseClaims EC '
    . 'WHERE EC.claimID = ? AND EC.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $claimID, $siteId);
    $stmt->execute();
    $claim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// 🛡️ Only the claimant can withdraw their own claim — and somebody else's
//    claim is answered EXACTLY as a claim that does not exist (#587).
//
//    This used to be two answers. A claim that was not the person's own got
//    "Access denied — only the claimant can withdraw a claim" and a redirect
//    to that claim's page, while a number matching no claim got "Claim not
//    found" and a redirect to the submit page. So anybody signed in could
//    learn which claim numbers were in use in their organisation by trying
//    them. Another organisation's claim was already "not found" (the query
//    above asks for this organisation's claims only), and now a colleague's
//    claim is too: one message, one address, whichever of the three it is.
if ($claim === null || (int) $claim['userID'] !== $userId) {
    $_SESSION['flash_msg']  = 'Claim not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/submit'));
    exit();
}

// 🛡️ Only Pending claims can be withdrawn
if ($claim['status'] !== 'Pending') {
    $_SESSION['flash_msg']  = 'This claim cannot be withdrawn — it is no longer in Pending status.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . Router::url('expenses/view') . '?id=' . $claimID);
    exit();
}

// -----------------------------------------------------------------------------
// 💾 Update claim status to Withdrawn
// -----------------------------------------------------------------------------
$mysqli->begin_transaction();
try {
    // 🔒 Re-fetch with row lock to prevent race condition
    $lockedClaim = null;
    $lockStmt = $mysqli->prepare(
        'SELECT claimID, status FROM tblExpenseClaims '
        . 'WHERE claimID = ? AND siteID = ? FOR UPDATE'
    );
    if ($lockStmt !== false) {
        $lockStmt->bind_param('ii', $claimID, $siteId);
        $lockStmt->execute();
        $lockedClaim = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();
    }

    // 🛡️ Verify claim is still Pending under lock
    if ($lockedClaim === null || $lockedClaim['status'] !== 'Pending') {
        $mysqli->rollback();
        $_SESSION['flash_msg']  = 'This claim has already been processed and cannot be withdrawn.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . Router::url('expenses/view') . '?id=' . $claimID);
        exit();
    }

    // 📊 Update status to Withdrawn.
    //    'Withdrawn' must be one of the choices of `tblExpenseClaims.status`
    //    (migration 211 and web/_sql/full_schema.sql). See the header for
    //    what happened while it was not.
    $withdrawn = 'Withdrawn';
    $stmt = $mysqli->prepare(
        'UPDATE tblExpenseClaims SET status = ?, updatedAt = NOW() WHERE claimID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare withdrawal update: ' . $mysqli->error);
    }
    $stmt->bind_param('sii', $withdrawn, $claimID, $siteId);
    $stmt->execute();
    $stmt->close();

    $mysqli->commit();

} catch (\Throwable $ex) {
    $mysqli->rollback();
    Logger::exception($ex);
    $_SESSION['flash_msg']  = 'Error withdrawing claim. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/view') . '?id=' . $claimID);
    exit();
}

// -----------------------------------------------------------------------------
// 📓📧 After the withdrawal is stored: the activity line, then the e-mail
// -----------------------------------------------------------------------------
// From here on the claim IS withdrawn, so nothing below may tell the person
// otherwise. These two steps used to be inside the `try` above, after the
// commit: when the e-mail threw, the `catch` rolled back a transaction that
// had already been committed (which undoes nothing) and showed "Error
// withdrawing claim. Please try again." about a withdrawn claim.
//
// Each has a `try` of its own, so that a failed activity line does not also
// stop the e-mail. A failure is handed to Logger::exception(), which files it
// under Admin, Errors. What this does NOT do: send the e-mail again later.
try {
    Logger::activity(
        'ExpenseWithdraw',
        'Claim #' . $claimID . ' withdrawn by claimant (' . htmlspecialchars($claim['claimTitle'], ENT_QUOTES, 'UTF-8') . ')',
        $userId
    );
} catch (\Throwable $ex) {
    Logger::exception($ex);
}

try {
    ExpenseMailer::notify($claimID, 'withdrawn', [
        'claimantName' => $_SESSION['user_name'] ?? 'Unknown',
    ]);
} catch (\Throwable $ex) {
    Logger::exception($ex);
}

// ✅ Redirect with success flash message
$_SESSION['flash_msg']  = 'Claim #' . $claimID . ' has been withdrawn successfully.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . Router::url('expenses/view') . '?id=' . $claimID);
exit();
