<?php
// Path: public_html/expenses/treasury/save.php
/**
 * -----------------------------------------------------------------------------
 * Treasury — Record Reimbursement Handler 💸
 * -----------------------------------------------------------------------------
 * Records reimbursement, marks claim as Reimbursed, regenerates PDF with
 * COMPLETE watermark, logs action, and sends email notification to claimant.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

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
    $_SESSION['flash_msg']  = 'Invalid CSRF token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/treasury');
    exit();
}

// 🛡️ Require Treasurer or Admin role
if (App::hasRole('Treasurer') === false && App::isAdmin() === false) {
    $_SESSION['flash_msg']  = 'Access denied — Treasurer or Admin role required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/treasury');
    exit();
}

// 📝 Extract and validate input
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$claimID  = (int) ($_POST['claimID'] ?? 0);
$comments = trim($_POST['comments'] ?? '');
$refs     = array_filter(array_map('trim', explode(',', implode(',', $_POST['payRef'] ?? []))));

if ($claimID === 0) {
    $_SESSION['flash_msg']  = 'Invalid claim ID.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/treasury');
    exit();
}

if (empty($refs) === true) {
    $_SESSION['flash_msg']  = 'At least one payment reference is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/treasury');
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
    header('Location: /expenses/treasury');
    exit();
}

// 📋 Get treasury user's name for email notifications
$paidByName = $_SESSION['user_name'] ?? 'Treasury';

$mysqli->begin_transaction();
try {
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

    // 2. 📝 Update claim status to Reimbursed
    $upd = $mysqli->prepare('UPDATE tblExpenseClaims SET status = ?, updatedAt = NOW() WHERE claimID = ? AND siteID = ?');
    if ($upd !== false) {
        $reimbursed = 'Reimbursed';
        $upd->bind_param('sii', $reimbursed, $claimID, $siteId);
        $upd->execute();
        $upd->close();
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
    header('Location: /expenses/treasury');
    exit();

} catch (\Throwable $ex) {
    $mysqli->rollback();
    Logger::exception($ex);
    $_SESSION['flash_msg']  = 'Error processing reimbursement. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/treasury');
    exit();
}
