<?php
// Path: public_html/expenses/treasury/save.php
/**
 * -----------------------------------------------------------------------------
 * Treasury — Record Reimbursement Handler 💸
 * -----------------------------------------------------------------------------
 * Records reimbursement, marks claim as Reimbursed, regenerates PDF with
 * COMPLETE watermark, logs action, and sends email notification to claimant.
 *
 * Refuses anyone who is not a treasurer of the organisation whose address is
 * open, or an administrator, with the standard "Access Denied" page — before
 * the form token is even checked (#538).
 *
 * Nobody records their own claim as paid (#545): a treasurer or an
 * administrator who made the claim is refused, and somebody else must pay
 * it. The rule itself is in `Portal\Core\ExpenseRules`.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.1
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\ExpenseRules;
use Portal\Core\Logger;
use Portal\Core\ExpensePdf;
use Portal\Core\ExpenseMailer;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Session check first, then the role check, then the form token.
Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Only a treasurer of THIS organisation, or an administrator, may record a
//    payment — the same test the list page (index.php) now asks before it
//    shows anything (#538), asked here BEFORE the form-token check, in the
//    order the other management handlers use (payments/save.php, the calendar
//    manage handlers): somebody who may not act here is answered first,
//    whatever else they sent.
//
//    WHAT WAS WRONG BEFORE: this refusal set a flash message and sent the
//    visitor back to /expenses/treasury. That made sense while the list page
//    was open to everybody. Since #538 the list page refuses exactly the same
//    people, so the old redirect would have landed on an "Access Denied" page
//    that never prints the flash, leaving "Access denied — Treasurer or Admin
//    role required" in the session to pop up on whatever page the person
//    opened next (confirmed on a real database: it appeared on the approvals
//    page). A POST from somebody who cannot even see the form is a forged or
//    stale request, and now gets the same 403 page as the list.
if (App::hasRole('treasurer') === false && App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid CSRF token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/treasury'));
    exit();
}

// 📝 Extract and validate input
$userId   = (int) ($_SESSION['user_id'] ?? 0);
// 🔢 THE CLAIM NUMBER IS A SINGLE VALUE, OR IT IS NOTHING.
//
//    `(int) ['x']` is 1 in PHP, silently — no warning, no notice. So
//    `claimID[]=916301` used to arrive here as claim number ONE, and the page
//    went on to act on whichever claim happens to be number 1, while the claim
//    the person was actually looking at was left untouched.
//
//    It needs a valid anti-forgery token, so it is the person's own browser
//    doing it: a proxy that folds a repeated hidden field into a list, or a
//    script that sets the field twice.
//
//    On this page that means recording a PAYMENT against the wrong claim,
//    so a claim nobody has paid is marked as paid and the one that was
//    actually paid still looks outstanding.
//
//    Anything that is not a single value becomes 0, which the existing "that is
//    not a claim number" refusal below already turns away. The same guard is in
//    the other two money handlers; all three were changed together.
$claimIdRaw = $_POST['claimID'] ?? 0;
$claimID    = is_scalar($claimIdRaw) === true ? (int) $claimIdRaw : 0;
// 💬 The comment is text, or there is no comment.
//
//    WHAT WAS WRONG: this read `trim($_POST['comments'] ?? '')`. An array
//    reaches trim(), which refuses one under `declare(strict_types=1)`, so
//    `comments[]=x` ended the request in a TypeError — the portal's own 500
//    page and one row in tblErrors. Nothing was corrupted, because it happens
//    before the transaction, but the treasurer could not tell whether the
//    payment had been recorded, which on a money page is its own harm.
//
//    This is the SIBLING of the claim-number guard directly above, added on
//    27 October 2026 (#642). The reasoning was applied to the number and to
//    the decision field on the approvals page, and missed the field sitting
//    immediately below it here. The comment is optional, so anything that is
//    not text is simply no comment.
$commentsRaw = $_POST['comments'] ?? '';
$comments    = is_string($commentsRaw) === true ? trim($commentsRaw) : '';

// 🧾 THE PAYMENT REFERENCES, read by the ONE place that reads them.
//
//    `ExpenseRules::paymentReferences()` holds the whole rule: a list of text,
//    a plain string, or nothing, with nested arrays dropped so `payRef[][]=x`
//    can never store a payment reference of literally "Array". The long
//    explanation of each case is on the method.
//
//    WHY IT IS NOT WRITTEN OUT HERE ANY MORE. It was, and its self-test held a
//    lifted copy of the same lines. A round-2 independent check on 28 October
//    2026 dropped `array_filter` from THIS copy only and every check still
//    passed, because the test was exercising its own. The drift would have
//    written an empty payment reference against a real claim. One place, called
//    by both.
$refs = ExpenseRules::paymentReferences($_POST['payRef'] ?? []);

if ($claimID === 0) {
    $_SESSION['flash_msg']  = 'Invalid claim ID.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/treasury'));
    exit();
}

if (empty($refs) === true) {
    $_SESSION['flash_msg']  = 'At least one payment reference is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/treasury'));
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Verify claim exists and is in Approved status
// -----------------------------------------------------------------------------
$claim = null;
$siteId = Site::id();
$stmt = $mysqli->prepare(
    'SELECT EC.claimID, EC.status, EC.userID, U.fullName AS claimantName '
    . 'FROM tblExpenseClaims EC '
    . 'JOIN tblUsers U ON U.userID = EC.userID '
    . 'WHERE EC.claimID = ? AND EC.status = ? AND EC.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $approved = 'Approved';
    $stmt->bind_param('isi', $claimID, $approved, $siteId);
    $stmt->execute();
    $claim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($claim === null) {
    $_SESSION['flash_msg']  = 'Claim not found or not in Approved status.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . Router::url('expenses/treasury'));
    exit();
}

// 🙅 #545: nobody records their own claim as paid — not a treasurer, and
//    not an administrator.
//
//    WHAT WAS WRONG: the claim's owner was read above and never compared
//    with the person paying. A treasurer could mark their own claim as
//    reimbursed, with any payment reference they cared to type. The owner
//    decided on 21 September 2026 to refuse outright.
//
//    Everybody who reaches this line is a treasurer or an administrator of
//    this organisation (the test at the top), and the claim is their own,
//    so the sentence tells them nothing they did not already know. The
//    list page no longer shows a person's own claims (treasury/index.php),
//    so this is normally reached only by a request made by hand.
if (ExpenseRules::isOwnClaim((int) $claim['userID'], $userId) === true) {
    $_SESSION['flash_msg']  = ExpenseRules::MSG_OWN_PAYMENT;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/treasury'));
    exit();
}

// 📋 Get treasury user's name for email notifications
$paidByName = $_SESSION['user_name'] ?? 'Treasury';

$mysqli->begin_transaction();
try {
    // 0. 🔒 HOLD THE CLAIM STILL, SO IT CANNOT BE PAID TWICE.
    //
    //    WHAT WAS WRONG (found by a whole-application review of the money
    //    pages on 28 October 2026; #647). The status read further up happens
    //    OUTSIDE this transaction and takes no row lock, and nothing re-read
    //    it in here. So two treasurers submitting for the same claim — or one
    //    person clicking twice because the first answer was slow — BOTH saw
    //    "Approved", both inserted their own payment reference, both set the
    //    claim to "Reimbursed", and both sent the claimant a "you have been
    //    reimbursed" e-mail. If each had really sent the money, it went out
    //    twice, and the claim carried two payment records with two different
    //    references and no way to tell which was the real one.
    //
    //    FOUR separate things would each have stopped that, and not one of
    //    them was present: a lock on the read, a re-read inside the
    //    transaction, an `AND status = 'Approved'` on the UPDATE below, and a
    //    unique key on tblExpenseClaimPayments(claimID). The first three are
    //    now here.
    //
    //    The fourth is deliberately NOT added. The `payAmount` column's own
    //    comment promises partial payments ("may differ from claim total in
    //    partial payments"), and a unique key on claimID would forbid them
    //    outright — that question is #650, not this fix.
    //
    //    This is the same PATTERN approve/save.php and withdraw/save.php have
    //    always used — open the transaction, re-fetch with FOR UPDATE, refuse
    //    if the status has moved. This page was the only one of the three
    //    money writers without it, which is why it counts as an omission
    //    rather than a decision somebody made.
    //
    //    NOT identical to those two, though, and an earlier version of this
    //    comment said "comment for comment", which an independent check on
    //    28 October 2026 showed was untrue. This page is deliberately
    //    STRONGER: it throws on a failed prepare where they test
    //    `!== false`, and its status UPDATE carries `AND status = ?` where
    //    theirs carry none. Those two are worth bringing to the other
    //    handlers rather than weakening this one (#650 covers the first).
    $lockedClaim = null;
    $lockStmt = $mysqli->prepare(
        'SELECT claimID, status FROM tblExpenseClaims '
        . 'WHERE claimID = ? AND siteID = ? FOR UPDATE'
    );
    if ($lockStmt === false) {
        throw new \RuntimeException('Failed to prepare claim lock: ' . $mysqli->error);
    }
    $lockStmt->bind_param('ii', $claimID, $siteId);
    $lockStmt->execute();
    $lockedClaim = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    // 🛡️ Still awaiting payment? Between the first look above and this lock,
    //    another treasurer may have paid it. Say so plainly rather than
    //    paying it again: the person needs to know their payment was NOT
    //    recorded, because they may have already moved the money.
    if ($lockedClaim === null || $lockedClaim['status'] !== 'Approved') {
        $mysqli->rollback();
        $_SESSION['flash_msg']  = 'Claim #' . $claimID . ' is no longer waiting for payment — another treasurer '
                                . 'has already recorded it as reimbursed. Your payment reference was NOT saved. '
                                . 'Please check the claim before sending any money.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . Router::url('expenses/treasury'));
        exit();
    }

    // 1. 💳 Insert payment reference records with paidByID
    $stmt = $mysqli->prepare(
        'INSERT INTO tblExpenseClaimPayments (claimID, payReference, paidByID) VALUES (?, ?, ?)'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare payment insert: ' . $mysqli->error);
    }
    foreach ($refs as $r) {
        $stmt->bind_param('isi', $claimID, $r, $userId);
        $stmt->execute();
    }
    $stmt->close();

    // 2. 📝 Update claim status to Reimbursed.
    //
    //    `AND status = ?` is the second line of defence behind the lock above
    //    (#647): even holding the row, the statement refuses to move a claim
    //    that is not sitting at "Approved".
    //
    //    WHAT WAS WRONG: this read `if ($upd !== false)`, so a prepare that
    //    FAILED was skipped silently and the transaction committed anyway —
    //    leaving the payment rows written and the claim still "Approved", so
    //    it came straight back onto the treasury list and could be paid a
    //    second time. A money write must never commit half-done. Step 1 above
    //    already threw on a failed prepare; this step now matches it.
    $upd = $mysqli->prepare(
        'UPDATE tblExpenseClaims SET status = ?, updatedAt = NOW() '
        . 'WHERE claimID = ? AND siteID = ? AND status = ?'
    );
    if ($upd === false) {
        throw new \RuntimeException('Failed to prepare claim status update: ' . $mysqli->error);
    }
    $reimbursed = 'Reimbursed';
    $approvedStill = 'Approved';
    $upd->bind_param('siis', $reimbursed, $claimID, $siteId, $approvedStill);
    if ($upd->execute() === false) {
        $updError = $upd->error;
        $upd->close();
        throw new \RuntimeException('Failed to update claim status: ' . $updError);
    }
    $statusRowsChanged = $upd->affected_rows;
    $upd->close();

    // 🛡️ Exactly one row must have moved. Zero means the claim slipped out of
    //    "Approved" despite the lock, which should be impossible — so treat it
    //    as a fault and roll back rather than reporting a payment that the
    //    claim does not reflect.
    if ($statusRowsChanged !== 1) {
        throw new \RuntimeException(
            'Claim #' . $claimID . ' did not move to Reimbursed (' . $statusRowsChanged . ' rows changed)'
        );
    }

    // 3. 📄 Generate COMPLETE PDF
    ExpensePdf::generate($claimID, 'Complete');

    $mysqli->commit();

    // 4. 📓 Log activity
    Logger::activity(
        'ExpensePay',
        'Reimbursed claim #' . $claimID . ' (refs: ' . implode(', ', $refs) . ')',
        $userId
    );

    // 5. 📧 Send email notification to claimant
    ExpenseMailer::notify($claimID, 'reimbursed', [
        'approverName' => $paidByName,
        'comments'     => $comments,
    ]);

    // 6. ✅ Redirect with flash message
    $_SESSION['flash_msg']  = 'Claim #' . $claimID . ' has been marked as reimbursed.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Router::url('expenses/treasury'));
    exit();

} catch (\Throwable $ex) {
    $mysqli->rollback();
    Logger::exception($ex);
    $_SESSION['flash_msg']  = 'Error processing reimbursement. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/treasury'));
    exit();
}
