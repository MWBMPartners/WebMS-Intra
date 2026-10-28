<?php
// Path: public_html/expenses/edit/save.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Change a Claim Handler ✏️
 * -----------------------------------------------------------------------------
 * Lets the person who made a claim correct it — the title, the department, the
 * line items and any extra receipts — but ONLY while nobody has decided on it.
 *
 * WHY THE BOUND IS SO TIGHT. Until this handler existed, nothing in the portal
 * could change a claim at all, so "can the amount change after it was
 * approved?" had a clean structural answer: no, because nothing can change.
 * This handler turns that into a promise that code has to keep. The rule is
 * therefore NOT "a Pending claim may be edited" but "a claim NOBODY HAS
 * DECIDED ON may be edited" — in a department needing several approvals a
 * claim stays Pending after the first one, and editing it then would leave an
 * approver's recorded "Approved" sitting against numbers they never saw. That
 * is the oldest fraud in expenses: get a small amount approved, then enlarge
 * it. The status by itself does not stop it.
 *
 * The rule itself is in `Portal\Core\ExpenseRules::whyNotEditable()`, which is
 * the ONE place that decides, and it is asked TWICE on purpose — see the long
 * note at the lock below. Do not write a second version of this test.
 *
 * The claimant only, never an administrator: #545 settled that somebody other
 * than the claimant must decide and must pay, and "somebody other than the
 * claimant changed the amount being claimed" belongs to that rule with more
 * force, not less.
 *
 * TWO THINGS THIS DELIBERATELY DOES NOT DO, so nobody reads them as oversights:
 *
 *  - It does not touch receipts AT ALL — it neither attaches a new one nor
 *    removes one already there. Existing attachments are left exactly as they
 *    are. That is a deliberate bound on this package, not a thing forgotten:
 *    taking evidence off a money claim is its own decision, and attaching a
 *    receipt that failed to upload is the subject of #649, where the silent
 *    dropping of a rejected file is being fixed properly. Doing it here would
 *    mean copying that fault into a second handler.
 *  - It does not e-mail the approvers again. Adding an "it changed" message
 *    needs its own template (they live in the database since #594), which is a
 *    separate piece of work, not a line here.
 *
 *    BE PRECISE ABOUT WHAT THAT COSTS, because an earlier version of this note
 *    got it wrong. It said the approvers "were told when it was submitted", and
 *    an independent check on 28 October 2026 pointed out that this handler lets
 *    the claimant change the DEPARTMENT — and the new department's approvers
 *    were never told anything. What still holds is the second half: the claim
 *    appears on their approvals list, which is read live from the database, and
 *    the claim's own page always shows its current figures. So the cost is a
 *    missing notification, not a hidden or lost claim. Nobody approves stale
 *    numbers, because nothing anywhere caches them.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/651
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\ExpensePdf;
use Portal\Core\ExpenseRules;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Signed in first, then the form token.
Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please open the claim and try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/submit'));
    exit();
}

// 🤖 No captcha here, unlike expenses/submit. That widget is there to stop a
//    stranger creating claims in bulk. This handler changes ONE existing claim
//    belonging to the signed-in person, needs a valid form token, and cannot
//    create anything — so a captcha would add friction for no threat.

$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

// 🔢 THE CLAIM NUMBER IS A SINGLE VALUE, OR IT IS NOTHING.
//    `(int) ['x']` is 1 in PHP, silently, so `claimID[]=916301` would arrive
//    as claim number ONE and this page would edit whichever claim happens to
//    be number 1. The same guard is in the other three money handlers (#642);
//    all four now agree.
$claimIdRaw = $_POST['claimID'] ?? 0;
$claimID    = is_scalar($claimIdRaw) === true ? (int) $claimIdRaw : 0;

/** 🔁 Back to the claim, or to the claim list when there is no usable number. */
$backToClaim = static function (int $claimId): string {
    if ($claimId > 0) {
        return Router::url('expenses/edit') . '?id=' . $claimId;
    }

    return Router::url('expenses/submit');
};

/** 🙅 Say no, put the reason on the next page, and stop. */
$refuse = static function (string $message, int $claimId, string $type = 'danger') use ($backToClaim): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $backToClaim($claimId));
    exit();
};

if ($claimID === 0) {
    $refuse('That is not a claim number, so nothing has been changed.', 0);
}

// 📝 The title and the department. Text is text or it is nothing: an array
//    reaching trim() is a TypeError under strict types, which would end the
//    request on the portal's 500 page and leave the person unable to tell
//    whether their change was saved (#642, #650).
$titleRaw   = $_POST['claimTitle'] ?? '';
$claimTitle = is_string($titleRaw) === true ? trim($titleRaw) : '';

$deptRaw = $_POST['deptID'] ?? 0;
$deptID  = is_scalar($deptRaw) === true ? (int) $deptRaw : 0;

if ($claimTitle === '' || $deptID === 0) {
    $refuse('A claim needs a title and a department. Nothing has been changed.', $claimID);
}

// -----------------------------------------------------------------------------
// 🧮 THE TOTAL IS WORKED OUT HERE, FROM THE LINES, AND NEVER TAKEN FROM THE FORM
// -----------------------------------------------------------------------------
// The form carries a running total for the person to see. It is not read: a
// posted total is a number the sender chose. `expenses/submit/save.php` and
// `expenses/api/create.php` both do the same, and this is the third door onto
// the same table, so it follows the same rule.
//
// Each line is checked the way `api/create.php` checks it — a unit cost of 0
// or more and a quantity of 1 or more. The web submit handler checks NEITHER
// and only tests that the TOTAL is positive, so a claim filed through that
// page can hold a line of quantity -5 (#650 item 4). A new handler is not
// shipping with a fault that is already written up, so the stricter rule is
// here, and #650 brings the submit page up to it.
$descRaw = $_POST['itemDesc'] ?? [];
$qtyRaw  = $_POST['itemQty']  ?? [];
$unitRaw = $_POST['itemUnit'] ?? [];

if (is_array($descRaw) === false || is_array($qtyRaw) === false || is_array($unitRaw) === false) {
    $refuse('The claim lines could not be read, so nothing has been changed.', $claimID);
}

$items = [];
$total = 0.0;
foreach ($descRaw as $i => $descEntry) {
    // 🚮 A line with no description is an empty row on the form, not a fault.
    if (is_string($descEntry) === false) {
        continue;
    }
    $desc = trim($descEntry);
    if ($desc === '') {
        continue;
    }

    $qtyEntry  = $qtyRaw[$i]  ?? null;
    $unitEntry = $unitRaw[$i] ?? null;

    if (is_scalar($qtyEntry) === false || is_scalar($unitEntry) === false) {
        $refuse('One of the claim lines could not be read, so nothing has been changed.', $claimID);
    }
    if (is_numeric($unitEntry) === false || (float) $unitEntry < 0) {
        $refuse('A unit price cannot be negative. Nothing has been changed.', $claimID);
    }
    $qty = (int) $qtyEntry;
    if ($qty < 1) {
        $refuse('A quantity must be at least 1. Nothing has been changed.', $claimID);
    }

    $unit = round((float) $unitEntry, 2);
    $line = round($unit * $qty, 2);
    $total += $line;

    $items[] = ['desc' => $desc, 'qty' => $qty, 'unit' => $unit, 'line' => $line];
}
$total = round($total, 2);

if (count($items) === 0 || $total <= 0) {
    $refuse('A claim needs at least one line, and a total of more than zero. Nothing has been changed.', $claimID);
}

// -----------------------------------------------------------------------------
// 🏢 The department must be a live one of THIS organisation
// -----------------------------------------------------------------------------
// Mirrors expenses/submit/save.php. `isActive = 1` matters: retiring a
// department means "no new claims through any door", and moving a claim onto a
// retired department would be a new claim there in all but name. A NULL
// isActive counts as off, as everywhere else.
$deptCheck = $mysqli->prepare(
    'SELECT deptID FROM tblDepts WHERE deptID = ? AND siteID = ? AND isActive = 1 LIMIT 1'
);
if ($deptCheck === false) {
    Logger::errorPlatform('MySQL', 'Error', 'EXP_EDIT_DEPT_PREP', $mysqli->error, '');
    $refuse('The claim could not be saved. Please try again.', $claimID);
}
$deptCheck->bind_param('ii', $deptID, $siteId);
$deptCheck->execute();
$deptOk = $deptCheck->get_result()->fetch_assoc() !== null;
$deptCheck->close();
if ($deptOk === false) {
    $refuse('That department is not one of this organisation\'s, so nothing has been changed.', $claimID);
}

// -----------------------------------------------------------------------------
// 📋 An early look at the claim, so an obvious no is answered cheaply
// -----------------------------------------------------------------------------
$lookStmt = $mysqli->prepare(
    'SELECT claimID, userID, status, deptID, claimTitle, claimDate, totalAmount '
    . 'FROM tblExpenseClaims WHERE claimID = ? AND siteID = ? LIMIT 1'
);
if ($lookStmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'EXP_EDIT_LOOK_PREP', $mysqli->error, '');
    $refuse('The claim could not be saved. Please try again.', $claimID);
}
$lookStmt->bind_param('ii', $claimID, $siteId);
$lookStmt->execute();
$claim = $lookStmt->get_result()->fetch_assoc();
$lookStmt->close();

// 🙈 A claim of another organisation's, or one that is not there, is simply
//    "not found" — never a sentence that confirms it exists.
if ($claim === null) {
    $refuse('That claim could not be found.', 0, 'warning');
}

$earlyReason = ExpenseRules::whyNotEditable(
    (string) $claim['status'],
    ExpenseRules::approvalCount($mysqli, $claimID),
    (int) $claim['userID'],
    $userId
);
if ($earlyReason !== ExpenseRules::EDIT_ALLOWED) {
    // 🙈 Somebody else's claim gets the list page, not that claim's page: the
    //    edit page would refuse them too, and bouncing them to it would be a
    //    loop that tells them nothing.
    $where = $earlyReason === ExpenseRules::EDIT_NOT_YOURS ? 0 : $claimID;
    $refuse(ExpenseRules::editRefusalWords($earlyReason), $where, 'warning');
}

// -----------------------------------------------------------------------------
// 💾 The change itself, with the claim held still
// -----------------------------------------------------------------------------
$mysqli->begin_transaction();
try {
    // 0. 🔒 HOLD THE CLAIM STILL AND ASK AGAIN.
    //
    //    THIS IS THE POINT OF THE WHOLE HANDLER, so it is worth being plain
    //    about what it stops. Between the look above and this line, an
    //    approver can record a decision. Without this, the sequence is:
    //
    //      1. the claimant opens the edit form on a £20 claim;
    //      2. an approver approves it;
    //      3. the claimant saves it as £2,000.
    //
    //    The approval row would then sit against £2,000 that nobody approved,
    //    and the claim would be fully approved and payable. That is the fraud
    //    this bound exists to prevent, and only a re-read under the lock
    //    closes it — the status is still "Pending" at step 3 in a department
    //    with more than one required approver, so checking the status alone
    //    would let it through.
    //
    //    The SAME question is asked, through the SAME method, on facts read
    //    INSIDE the lock. That is why `ExpenseRules::whyNotEditable()` takes
    //    facts rather than a connection: a method that did its own reading
    //    could not be used here.
    //
    //    Same pattern as approve/save.php, withdraw/save.php and
    //    treasury/save.php (#647). All four money writers now lock and
    //    re-check.
    $lockStmt = $mysqli->prepare(
        'SELECT claimID, userID, status FROM tblExpenseClaims '
        . 'WHERE claimID = ? AND siteID = ? FOR UPDATE'
    );
    if ($lockStmt === false) {
        throw new \RuntimeException('Failed to prepare claim lock: ' . $mysqli->error);
    }
    $lockStmt->bind_param('ii', $claimID, $siteId);
    $lockStmt->execute();
    $locked = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    if ($locked === null) {
        $mysqli->rollback();
        $refuse('That claim could not be found.', 0, 'warning');
    }

    // 🧾 Counted INSIDE the lock, so a decision recorded a moment ago is seen.
    $lockedReason = ExpenseRules::whyNotEditable(
        (string) $locked['status'],
        ExpenseRules::approvalCount($mysqli, $claimID),
        (int) $locked['userID'],
        $userId
    );
    if ($lockedReason !== ExpenseRules::EDIT_ALLOWED) {
        $mysqli->rollback();
        $where = $lockedReason === ExpenseRules::EDIT_NOT_YOURS ? 0 : $claimID;
        $refuse(ExpenseRules::editRefusalWords($lockedReason), $where, 'warning');
    }

    // 1. 🗂️ What it used to say, for the audit trail. Read before anything is
    //    written, and the lines too: an edited claim must be able to show what
    //    it held before, or the trail records that something changed without
    //    recording what.
    $oldItems = [];
    $oldStmt  = $mysqli->prepare(
        'SELECT itemName, quantity, unitCost, lineTotal FROM tblExpenseClaimItems '
        . 'WHERE claimID = ? ORDER BY itemID'
    );
    if ($oldStmt !== false) {
        $oldStmt->bind_param('i', $claimID);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();
        while ($oldRow = $oldResult->fetch_assoc()) {
            $oldItems[] = $oldRow;
        }
        $oldStmt->close();
    }
    $oldRecord          = $claim;
    $oldRecord['items'] = $oldItems;

    // 2. 📝 The header.
    //
    //    `AND status = ?` is defence in depth behind the lock. Note what is
    //    NOT done here: `affected_rows` is not required to be 1. Unlike the
    //    payment handler (#647), which always moves a claim from one status to
    //    another, this statement can legitimately set every column to the
    //    value it already holds — somebody who edits only the line items
    //    changes nothing in this row, and MySQL reports 0 rows changed for
    //    that. Asserting 1 would turn an ordinary edit into an error. The
    //    lock plus the re-check above is what guarantees the row is ours; this
    //    guard is the belt to that pair of braces.
    $updStmt = $mysqli->prepare(
        'UPDATE tblExpenseClaims SET claimTitle = ?, deptID = ?, totalAmount = ?, updatedAt = NOW() '
        . 'WHERE claimID = ? AND siteID = ? AND status = ?'
    );
    if ($updStmt === false) {
        throw new \RuntimeException('Failed to prepare claim update: ' . $mysqli->error);
    }
    $pending = 'Pending';
    $updStmt->bind_param('sidiis', $claimTitle, $deptID, $total, $claimID, $siteId, $pending);
    if ($updStmt->execute() === false) {
        $updError = $updStmt->error;
        $updStmt->close();
        throw new \RuntimeException('Failed to update claim: ' . $updError);
    }
    $updStmt->close();

    // 3. 📦 The lines, replaced as a set.
    //
    //    Replaced rather than matched up one by one, so the stored lines and
    //    the stored total can never disagree: both come from the same reading
    //    of the form, inside the same transaction. Matching rows up would mean
    //    deciding what to do about a line the person removed and a line they
    //    added, and every one of those decisions is a way for the two to drift
    //    apart.
    $delStmt = $mysqli->prepare('DELETE FROM tblExpenseClaimItems WHERE claimID = ?');
    if ($delStmt === false) {
        throw new \RuntimeException('Failed to prepare item clear: ' . $mysqli->error);
    }
    $delStmt->bind_param('i', $claimID);
    if ($delStmt->execute() === false) {
        $delError = $delStmt->error;
        $delStmt->close();
        throw new \RuntimeException('Failed to clear claim items: ' . $delError);
    }
    $delStmt->close();

    $itemStmt = $mysqli->prepare(
        'INSERT INTO tblExpenseClaimItems (claimID, itemName, quantity, unitCost, lineTotal) '
        . 'VALUES (?, ?, ?, ?, ?)'
    );
    if ($itemStmt === false) {
        throw new \RuntimeException('Failed to prepare item insert: ' . $mysqli->error);
    }
    foreach ($items as $it) {
        $itemStmt->bind_param('isidd', $claimID, $it['desc'], $it['qty'], $it['unit'], $it['line']);
        if ($itemStmt->execute() === false) {
            $itemError = $itemStmt->error;
            $itemStmt->close();
            throw new \RuntimeException('Failed to write a claim line: ' . $itemError);
        }
    }
    $itemStmt->close();

    // 4. 📄 The PDF, so the paper and the record never disagree.
    ExpensePdf::generate($claimID, 'Pending');

    $newRecord = [
        'claimID'     => $claimID,
        'siteID'      => $siteId,
        'userID'      => $userId,
        'status'      => 'Pending',
        'claimTitle'  => $claimTitle,
        'deptID'      => $deptID,
        'totalAmount' => $total,
        'items'       => $items,
    ];

    $mysqli->commit();

    // 5. 📓 The trail. After the commit, because a log entry for a change that
    //    was rolled back would be a lie.
    Logger::audit('tblExpenseClaims', $claimID, 'update', $oldRecord, $newRecord);
    Logger::activity(
        'ExpenseEdit',
        'Claim #' . $claimID . ' changed (£' . number_format((float) ($claim['totalAmount'] ?? 0), 2)
        . ' → £' . number_format($total, 2) . ')',
        $userId
    );

    $_SESSION['flash_msg']  = 'Claim #' . $claimID . ' has been updated.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Router::url('expenses/view') . '?id=' . $claimID);
    exit();

} catch (\Throwable $ex) {
    $mysqli->rollback();
    Logger::exception($ex);
    $_SESSION['flash_msg']  = 'The claim could not be saved, so nothing has been changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backToClaim($claimID));
    exit();
}
