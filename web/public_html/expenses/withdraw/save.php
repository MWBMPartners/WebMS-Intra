<?php
// Path: public_html/expenses/withdraw/save.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Claim Withdrawal Save Handler 🔄
 * -----------------------------------------------------------------------------
 * Handles withdrawal of a pending expense claim by the claimant. Only the
 * original submitter can withdraw, and only while the claim is still Pending.
 * Updates status to 'Withdrawn', logs activity, sends email notification to
 * approvers, and redirects back to the claim view.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.1
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\ExpenseMailer;
use Portal\Core\Site;

// 🛡️ Session and CSRF checks
Auth::ensureSession();
Auth::requireLogin();

$claimID = (int) ($_POST['claimID'] ?? 0);

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid CSRF token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/view?id=' . $claimID);
    exit();
}

// 📝 Validate claim ID
if ($claimID <= 0) {
    $_SESSION['flash_msg']  = 'Invalid claim ID.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/submit');
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

if ($claim === null) {
    $_SESSION['flash_msg']  = 'Claim not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/submit');
    exit();
}

// 🛡️ Only the claimant can withdraw their own claim
if ((int) $claim['userID'] !== $userId) {
    $_SESSION['flash_msg']  = 'Access denied — only the claimant can withdraw a claim.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/view?id=' . $claimID);
    exit();
}

// 🛡️ Only Pending claims can be withdrawn
if ($claim['status'] !== 'Pending') {
    $_SESSION['flash_msg']  = 'This claim cannot be withdrawn — it is no longer in Pending status.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /expenses/view?id=' . $claimID);
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
        header('Location: /expenses/view?id=' . $claimID);
        exit();
    }

    // 📊 Update status to Withdrawn
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

    // 📓 Log activity
    Logger::activity(
        'ExpenseWithdraw',
        'Claim #' . $claimID . ' withdrawn by claimant (' . htmlspecialchars($claim['claimTitle'], ENT_QUOTES, 'UTF-8') . ')',
        $userId
    );

    // 📧 Send email notification to approvers
    ExpenseMailer::notify($claimID, 'withdrawn', [
        'claimantName' => $_SESSION['user_name'] ?? 'Unknown',
    ]);

    // ✅ Redirect with success flash message
    $_SESSION['flash_msg']  = 'Claim #' . $claimID . ' has been withdrawn successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /expenses/view?id=' . $claimID);
    exit();

} catch (\Throwable $ex) {
    $mysqli->rollback();
    Logger::exception($ex);
    $_SESSION['flash_msg']  = 'Error withdrawing claim. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/view?id=' . $claimID);
    exit();
}
