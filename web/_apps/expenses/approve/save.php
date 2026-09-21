<?php
// Path: public_html/expenses/approve/save.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Approval Save Handler 🔄
 * -----------------------------------------------------------------------------
 * Receives decision from approver, validates authority, records in audit trail,
 * checks multi-approver requirements, updates claim status when all approvals
 * are met, generates PDF, and sends email notifications.
 *
 * Multi-approver logic:
 *   1. Check if this approver is authorised for the claim's department
 *   2. Record their individual decision
 *   3. If any approver rejects → claim is Rejected immediately
 *   4. If all mandatory approvers have approved → claim is Approved
 *   5. High-value claims may also require treasury sign-off (configurable)
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.2
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Departments;
use Portal\Core\Logger;
use Portal\Core\ExpensePdf;
use Portal\Core\ExpenseMailer;
use Portal\Core\Site;

// 🛡️ Session and CSRF checks
Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid CSRF token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/approve');
    exit();
}

// 🛡️ Who may record a decision at all: an administrator, a holder of the
//    Expense Approver role, or — since #542 — anyone holding an approval
//    flag (lead, approver or required approver) in at least one department
//    of THIS organisation, through an active membership of it.
//
//    WHAT WAS WRONG BEFORE (#542): this gate asked for the role or an
//    administrator and nothing else, before the claim was even loaded. The
//    role (#516) and the department flags (#517) are set on different
//    pages, so a lead or required approver without the role was refused
//    here, while the claim below still waited for their approval, which an
//    administrator's approval never stands in for. The claim could never be
//    approved, and so never paid. Owner's decision (21 September 2026): a
//    flag in the claim's own department is enough.
//
//    The flags are read only when the two cheaper tests both say no, so an
//    administrator or a role holder costs exactly what they did before. The
//    one read is kept for the per-department gate further down. This gate
//    asks "any department here?" rather than "this claim's department?" on
//    purpose: the claim is not loaded yet, and it must stay that way, so a
//    visitor with no authority at all is answered before any claim lookup
//    and can learn nothing about which claim numbers exist or are pending.
//    Which department they may decide is settled below, once the claim is
//    known; a flag in some OTHER department gets the same "Forbidden" answer
//    there that a role holder without a flag has always had.
//
//    The refusal is byte-for-byte what it was — the same message, the same
//    redirect — for someone with neither the role nor a flag; the message's
//    wording is kept for that reason even though a flag is now a third way in.
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$siteId  = Site::id();
$isAdmin = App::isAdmin();

/** @var array<int, array{isDeptLead: bool, isMandatoryApprover: bool, isApprover: bool}>|null */
$approverDepts = null;
if ($isAdmin === false && App::hasRole('Approver') === false) {
    $approverDepts = Departments::approverDepts($mysqli, $userId, $siteId);
    if (count($approverDepts) === 0) {
        $_SESSION['flash_msg']  = 'Access denied — Approver or Admin role required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /expenses/approve');
        exit();
    }
}

// 📝 Extract and validate input
$claimID  = (int) ($_POST['claimID'] ?? 0);
$decision = ($_POST['decision'] ?? '') === 'Rejected' ? 'Rejected' : 'Approved';
$comment  = trim($_POST['comments'] ?? '');

if ($claimID === 0) {
    $_SESSION['flash_msg']  = 'Invalid claim ID.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/approve');
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Fetch claim details to determine dept and amount
// -----------------------------------------------------------------------------
$claim = null;
$stmt = $mysqli->prepare(
    'SELECT EC.claimID, EC.deptID, EC.totalAmount, EC.status, EC.userID, U.fullName AS claimantName '
    . 'FROM tblExpenseClaims EC '
    . 'JOIN tblUsers U ON U.userID = EC.userID '
    . 'WHERE EC.claimID = ? AND EC.status = ? AND EC.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $pending = 'Pending';
    $stmt->bind_param('isi', $claimID, $pending, $siteId);
    $stmt->execute();
    $claim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($claim === null) {
    $_SESSION['flash_msg']  = 'Claim not found or not in Pending status.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /expenses/approve');
    exit();
}

// -----------------------------------------------------------------------------
// 🔍 Determine approver's role for this department
// -----------------------------------------------------------------------------
$approverRole = null;
if ($isAdmin === true) {
    $approverRole = 'admin';
} else {
    // 🛡️ Per-department authorisation gate — mirrors approve/index.php's
    // listing filter: only a lead, approver or required-approver flag in
    // THIS claim's department, held through an active membership of THIS
    // organisation, is authority to decide it; the site-wide 'Approver'
    // role on its own is not (it never was). The join lives in
    // Departments::approverDepts() since #542, so this handler and the
    // claim page test the same thing; before #517 a leftover row from an
    // organisation the person had left still let them decide, and there is
    // deliberately NO test that the department is switched on, because a
    // retired department's pending claims are finished by its own approvers
    // (owner's answer Q3). A role holder skipped the read at the top, so it
    // happens here instead — still one read per decision, as before.
    if ($approverDepts === null) {
        $approverDepts = Departments::approverDepts($mysqli, $userId, $siteId);
    }
    $deptFlags = $approverDepts[(int) $claim['deptID']] ?? null;
    if ($deptFlags === null) {
        http_response_code(403);
        exit('Forbidden');
    }
    if ($deptFlags['isDeptLead'] === true) {
        $approverRole = 'dept_lead';
    } elseif ($deptFlags['isMandatoryApprover'] === true) {
        $approverRole = 'mandatory_approver';
    } elseif ($deptFlags['isApprover'] === true) {
        $approverRole = 'dept_approver';
    }
}

// 📋 Get approver's name for email notifications
$approverName = $_SESSION['user_name'] ?? 'Unknown';

$mysqli->begin_transaction();
try {
    // 0. 🔒 Re-fetch claim with row lock to prevent concurrent approval race condition
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

    // 🛡️ Verify claim is still Pending (another approver may have changed it)
    if ($lockedClaim === null || $lockedClaim['status'] !== 'Pending') {
        $mysqli->rollback();
        $_SESSION['flash_msg']  = 'This claim has already been decided by another approver.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: /expenses/approve');
        exit();
    }

    // 1. 📋 Insert approval record with role context
    $stmt = $mysqli->prepare(
        'INSERT INTO tblExpenseClaimApprovals (claimID, userID, decision, comments, approverRole) '
        . 'VALUES (?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare approval insert: ' . $mysqli->error);
    }
    $stmt->bind_param('iisss', $claimID, $userId, $decision, $comment, $approverRole);
    $stmt->execute();
    $stmt->close();

    // 2. 🔍 Multi-approver decision logic
    $finalDecision = null;

    // 🚫 Any rejection immediately rejects the claim
    if ($decision === 'Rejected') {
        $finalDecision = 'Rejected';
    } else {
        // ✅ Check if all mandatory approvers for this dept have approved
        // Mandatory approvers: dept leads + users flagged as isMandatoryApprover
        //
        // #517: only members of this claim's organisation whose membership
        // there is still ACTIVE count. Before #517 a required approver who
        // had left the organisation stayed on this list for ever, so a claim
        // could never be fully approved by anybody. Still no test that the
        // department is switched on (owner's answer Q3; see the gate above).
        $mandatoryApprovers = [];
        $stmt = $mysqli->prepare(
            'SELECT UD.userID FROM tblUserDepts UD '
            . 'JOIN tblUserSites US ON US.userID = UD.userID AND US.siteID = UD.siteID AND US.isActive = 1 '
            . 'WHERE UD.deptID = ? AND UD.siteID = ? AND (UD.isDeptLead = 1 OR UD.isMandatoryApprover = 1)'
        );
        if ($stmt !== false) {
            $mandatoryDeptId = (int) $claim['deptID'];
            $stmt->bind_param('ii', $mandatoryDeptId, $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($r = $result->fetch_assoc()) {
                $mandatoryApprovers[] = (int) $r['userID'];
            }
            $stmt->close();
        }

        // 📋 Get all approvals that have been given for this claim
        $existingApprovals = [];
        $stmt = $mysqli->prepare(
            'SELECT userID, decision FROM tblExpenseClaimApprovals WHERE claimID = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $claimID);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($r = $result->fetch_assoc()) {
                $existingApprovals[(int) $r['userID']] = $r['decision'];
            }
            $stmt->close();
        }

        // ✅ Check all mandatory approvers have approved
        $allMandatoryMet = true;
        if (count($mandatoryApprovers) > 0) {
            foreach ($mandatoryApprovers as $mandatoryId) {
                if (isset($existingApprovals[$mandatoryId]) === false
                    || $existingApprovals[$mandatoryId] !== 'Approved') {
                    $allMandatoryMet = false;
                    break;
                }
            }
        }

        // 📊 If no mandatory approvers are configured, a single approval is enough
        if (count($mandatoryApprovers) === 0) {
            $allMandatoryMet = true;
        }

        if ($allMandatoryMet === true) {
            $finalDecision = 'Approved';
        }
    }

    // 3. 📊 Update claim status if a final decision has been reached
    if ($finalDecision !== null) {
        $stmt = $mysqli->prepare('UPDATE tblExpenseClaims SET status = ?, updatedAt = NOW() WHERE claimID = ? AND siteID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('sii', $finalDecision, $claimID, $siteId);
            $stmt->execute();
            $stmt->close();
        }

        // 4. 📄 Generate PDF with appropriate watermark
        $pdfStatus = $finalDecision === 'Rejected' ? 'Not Approved' : 'Approved';
        ExpensePdf::generate($claimID, $pdfStatus);
    }

    $mysqli->commit();

    // 5. 📓 Log activity
    Logger::activity(
        'ExpenseApprove',
        $decision . ' claim #' . $claimID . ' (role: ' . $approverRole . ', final: ' . ($finalDecision ?? 'pending') . ')',
        $userId
    );

    // 6. 📧 Send email notifications
    if ($finalDecision === 'Approved') {
        ExpenseMailer::notify($claimID, 'approved', [
            'approverName' => $approverName,
            'comments'     => $comment,
        ]);
    } elseif ($finalDecision === 'Rejected') {
        ExpenseMailer::notify($claimID, 'rejected', [
            'approverName' => $approverName,
            'comments'     => $comment,
        ]);
    }

    // 7. ✅ Redirect with flash message
    if ($finalDecision === 'Rejected') {
        $_SESSION['flash_msg']  = 'Claim #' . $claimID . ' has been rejected.';
        $_SESSION['flash_type'] = 'warning';
    } elseif ($finalDecision === 'Approved') {
        $_SESSION['flash_msg']  = 'Claim #' . $claimID . ' has been fully approved.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_msg']  = 'Your approval for claim #' . $claimID . ' has been recorded. Awaiting remaining approvers.';
        $_SESSION['flash_type'] = 'info';
    }

    header('Location: /expenses/approve');
    exit();

} catch (\Throwable $ex) {
    $mysqli->rollback();
    Logger::exception($ex);
    $_SESSION['flash_msg']  = 'Error processing decision. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/approve');
    exit();
}
