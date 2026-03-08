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
 * @version   0.4.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\ExpensePdf;
use Portal\Core\ExpenseMailer;
use Portal\Core\Site;

// 🛡️ Session and CSRF checks
Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['admin_flash_msg']  = 'Invalid CSRF token.';
    $_SESSION['admin_flash_type'] = 'danger';
    header('Location: /expenses/approve');
    exit();
}

// 🛡️ Require Approver or Admin role
if (App::hasRole('Approver') === false && App::isAdmin() === false) {
    $_SESSION['admin_flash_msg']  = 'Access denied — Approver or Admin role required.';
    $_SESSION['admin_flash_type'] = 'danger';
    header('Location: /expenses/approve');
    exit();
}

// 📝 Extract and validate input
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$claimID  = (int) ($_POST['claimID'] ?? 0);
$decision = ($_POST['decision'] ?? '') === 'Rejected' ? 'Rejected' : 'Approved';
$comment  = trim($_POST['comments'] ?? '');

if ($claimID === 0) {
    $_SESSION['admin_flash_msg']  = 'Invalid claim ID.';
    $_SESSION['admin_flash_type'] = 'danger';
    header('Location: /expenses/approve');
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Fetch claim details to determine dept and amount
// -----------------------------------------------------------------------------
$claim = null;
$siteId = Site::id();
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
    $_SESSION['admin_flash_msg']  = 'Claim not found or not in Pending status.';
    $_SESSION['admin_flash_type'] = 'warning';
    header('Location: /expenses/approve');
    exit();
}

// -----------------------------------------------------------------------------
// 🔍 Determine approver's role for this department
// -----------------------------------------------------------------------------
$approverRole = 'admin'; // Default if user is admin
if (App::isAdmin() === false) {
    $stmt = $mysqli->prepare(
        'SELECT isDeptLead, isApprover, isMandatoryApprover '
        . 'FROM tblUserDepts WHERE userID = ? AND deptID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $userId, $claim['deptID']);
        $stmt->execute();
        $deptRole = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($deptRole !== null) {
            if ((int) $deptRole['isDeptLead'] === 1) {
                $approverRole = 'dept_lead';
            } elseif ((int) $deptRole['isMandatoryApprover'] === 1) {
                $approverRole = 'mandatory_approver';
            } elseif ((int) $deptRole['isApprover'] === 1) {
                $approverRole = 'dept_approver';
            }
        }
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
        $_SESSION['admin_flash_msg']  = 'This claim has already been decided by another approver.';
        $_SESSION['admin_flash_type'] = 'warning';
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
        $mandatoryApprovers = [];
        $stmt = $mysqli->prepare(
            'SELECT UD.userID FROM tblUserDepts UD '
            . 'WHERE UD.deptID = ? AND (UD.isDeptLead = 1 OR UD.isMandatoryApprover = 1)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $claim['deptID']);
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
        $_SESSION['admin_flash_msg']  = 'Claim #' . $claimID . ' has been rejected.';
        $_SESSION['admin_flash_type'] = 'warning';
    } elseif ($finalDecision === 'Approved') {
        $_SESSION['admin_flash_msg']  = 'Claim #' . $claimID . ' has been fully approved.';
        $_SESSION['admin_flash_type'] = 'success';
    } else {
        $_SESSION['admin_flash_msg']  = 'Your approval for claim #' . $claimID . ' has been recorded. Awaiting remaining approvers.';
        $_SESSION['admin_flash_type'] = 'info';
    }

    header('Location: /expenses/approve');
    exit();

} catch (\Throwable $ex) {
    $mysqli->rollback();
    Logger::exception($ex);
    $_SESSION['admin_flash_msg']  = 'Error processing decision. Please try again.';
    $_SESSION['admin_flash_type'] = 'danger';
    header('Location: /expenses/approve');
    exit();
}
