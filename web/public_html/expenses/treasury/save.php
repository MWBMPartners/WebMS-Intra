<?php
// Path: apps/expenses/treasury/save.php  (v2 PDF COMPLETE + email stub)
/**
 * -----------------------------------------------------------------------------
 * Treasury -- Record Reimbursement Handler 💸
 * -----------------------------------------------------------------------------
 * Records reimbursement, marks claim complete, regenerates PDF with COMPLETE
 * watermark, logs action, and (stub) emails claimant.
 * Table DDL is now handled by migration 002 (removed inline CREATE TABLE).
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\ExpensePdf;

// 🛡️ Session and CSRF checks
Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    exit('CSRF');
}

// 🛡️ Require Treasurer or Admin role
if (App::hasRole('Treasurer') === false && App::isAdmin() === false) {
    exit('Access denied');
}

// 📝 Extract and validate input
$claimID  = intval($_POST['claimID'] ?? 0);
$comments = trim($_POST['comments'] ?? '');
$refs     = array_filter(array_map('trim', explode(',', implode(',', $_POST['payRef'] ?? []))));
if ($claimID === 0 || empty($refs) === true) {
    exit('Missing');
}

$mysqli->begin_transaction();
try {
    // 1. 💳 Insert payment reference records
    $stmt = $mysqli->prepare('INSERT INTO tblExpenseClaimPayments (claimID, payReference) VALUES (?,?)');
    foreach ($refs as $r) {
        $stmt->bind_param('is', $claimID, $r);
        $stmt->execute();
    }
    $stmt->close();

    // 2. 📝 Update claim status to Reimbursed
    $upd = $mysqli->prepare('UPDATE tblExpenseClaims SET status = "Reimbursed", updatedAt = NOW() WHERE claimID = ?');
    $upd->bind_param('i', $claimID);
    $upd->execute();
    $upd->close();

    // 3. 📄 Generate COMPLETE PDF
    ExpensePdf::generate($claimID, 'Complete');

    $mysqli->commit();
    Logger::activity('ExpensePay', 'Reimbursed claim #' . $claimID, $_SESSION['user_id']);

    // TODO: send email with PDF attachment

    header('Location: /expenses/treasury?done=1');
    exit();

} catch (Throwable $ex) {
    $mysqli->rollback();
    Logger::exception($ex);
    exit('Error');
}
