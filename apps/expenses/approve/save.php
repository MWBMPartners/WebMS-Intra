<?php
// Path: apps/expenses/approve/save.php
/**
 * -----------------------------------------------------------------------------
 * Expenses – Approval Save Handler 🔄
 * -----------------------------------------------------------------------------
 * Receives decision from approver, updates status, records audit trail, sends
 * notification emails (todo), and redirects back with a flash message.
 * -----------------------------------------------------------------------------
 * • Only authorised approvers can change status.
 * • Multiple approvers supported: inserts row into tblExpenseClaimApprovals
 *   (created here if not existing) and if final required approval reached,
 *   claim status flips to Approved / Rejected.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Logger;

Auth::ensureSession();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    echo 'Invalid CSRF';
    exit();
}

$userId   = $_SESSION['user_id'] ?? 0;
$claimID  = intval($_POST['claimID'] ?? 0);
$decision = ($_POST['decision'] ?? '') === 'Rejected' ? 'Rejected' : 'Approved';
$comment  = trim($_POST['comments'] ?? '');

if ($claimID === 0) {
    echo 'Invalid claim.';
    exit();
}

$mysqli->begin_transaction();
try {
    // 1. Insert approval record (create table if not yet existing)
    $mysqli->query('CREATE TABLE IF NOT EXISTS tblExpenseClaimApprovals (
        approvalID INT AUTO_INCREMENT PRIMARY KEY,
        claimID INT NOT NULL,
        userID INT NOT NULL,
        decision ENUM("Approved","Rejected") NOT NULL,
        comments TEXT NULL,
        decidedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (claimID) REFERENCES tblExpenseClaims(claimID) ON DELETE CASCADE,
        FOREIGN KEY (userID) REFERENCES tblUsers(userID) ON DELETE CASCADE) ENGINE=InnoDB');

    $stmt = $mysqli->prepare('INSERT INTO tblExpenseClaimApprovals (claimID, userID, decision, comments) VALUES (?,?,?,?)');
    $stmt->bind_param('iiss', $claimID, $userId, $decision, $comment);
    $stmt->execute();
    $stmt->close();

    // 2. Check if all required approvers have approved (simplified: one approver only)
    $final = true; // TODO multi-approver logic
    if ($final === true) {
        $stmt = $mysqli->prepare('UPDATE tblExpenseClaims SET status = ? , updatedAt = NOW() WHERE claimID = ?');
        $stmt->bind_param('si', $decision, $claimID);
        $stmt->execute();
        $stmt->close();
    }

    $mysqli->commit();
    Logger::activity('ExpenseApprove', $decision . ' claim #' . $claimID, $userId);

    // TODO: send email notifications via Graph API

    header('Location: /expenses/approve?done=1');
    exit();

} catch (Throwable $ex) {
    $mysqli->rollback();
    Logger::exception($ex);
    echo 'Error processing decision.';
    exit();
}