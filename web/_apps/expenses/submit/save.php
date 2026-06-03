<?php
// Path: public_html/expenses/submit/save.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Claim Submission Save Handler 💾
 * -----------------------------------------------------------------------------
 * Handles new expense claim submission: inserts DB rows, uploads files, creates
 * a "Pending" PDF via ExpensePdf helper, sends email notification to approvers,
 * logs activity, and redirects.
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
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\ExpensePdf;
use Portal\Core\ExpenseMailer;
use Portal\Core\Site;

// 🛡️ Session and security checks
Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid CSRF token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/submit');
    exit();
}

if (Captcha::verify($_POST) === false) {
    $_SESSION['flash_msg']  = 'Captcha verification failed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/submit');
    exit();
}

// 📝 Validate required fields
$userId     = (int) ($_SESSION['user_id'] ?? 0);
$deptID     = (int) ($_POST['deptID'] ?? 0);
$claimTitle = trim($_POST['claimTitle'] ?? '');

if ($deptID === 0 || $claimTitle === '') {
    $_SESSION['flash_msg']  = 'Missing required fields (department and title).';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/submit');
    exit();
}

// 🧮 Recalculate total server-side (do not trust client-side hidden field)
$totalAmt = 0.0;
foreach ($_POST['itemDesc'] ?? [] as $i => $desc) {
    if (trim($desc) === '') {
        continue;
    }
    $qty  = (int) ($_POST['itemQty'][$i] ?? 1);
    $unit = (float) ($_POST['itemUnit'][$i] ?? 0);
    $totalAmt += $qty * $unit;
}
if ($totalAmt <= 0) {
    $_SESSION['flash_msg']  = 'Total must be greater than zero.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/submit');
    exit();
}

$siteId = Site::id();

// 🔄 Wrap multi-table insert in a transaction for atomicity
App::beginTransaction();
try {
    // 1. 📋 Insert claim header
    $stmt = $mysqli->prepare('INSERT INTO tblExpenseClaims (userID, deptID, claimTitle, claimDate, totalAmount, siteID) VALUES (?, ?, ?, CURDATE(), ?, ?)');
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare claim insert: ' . $mysqli->error);
    }
    $stmt->bind_param('iisdi', $userId, $deptID, $claimTitle, $totalAmt, $siteId);
    $stmt->execute();
    $claimID = $stmt->insert_id;
    $stmt->close();

    // 2. 📦 Items
    $itemStmt = $mysqli->prepare('INSERT INTO tblExpenseClaimItems (claimID, itemName, quantity, unitCost, lineTotal) VALUES (?, ?, ?, ?, ?)');
    if ($itemStmt === false) {
        throw new \RuntimeException('Failed to prepare item insert: ' . $mysqli->error);
    }
    foreach ($_POST['itemDesc'] as $i => $desc) {
        $desc = trim($desc);
        if ($desc === '') {
            continue;
        }
        $qty  = (int) ($_POST['itemQty'][$i] ?? 1);
        $unit = (float) ($_POST['itemUnit'][$i] ?? 0);
        $line = $qty * $unit;
        $itemStmt->bind_param('isidd', $claimID, $desc, $qty, $unit, $line);
        $itemStmt->execute();
    }
    $itemStmt->close();

    // 3. 📎 File uploads
    $upDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'expenses';
    if (is_dir($upDir) === false) {
        mkdir($upDir, 0755, true);
    }
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv'];
    $maxFileSize = 10 * 1024 * 1024; // 10 MB
    $fileStmt = $mysqli->prepare('INSERT INTO tblExpenseClaimFiles (claimID, originalFilename, storedFilename, fileSize, fileType) VALUES (?, ?, ?, ?, ?)');
    if ($fileStmt !== false) {
        foreach ($_FILES['files']['error'] ?? [] as $i => $err) {
            if ($err !== UPLOAD_ERR_OK) {
                continue;
            }
            $orig = basename($_FILES['files']['name'][$i]);
            $tmp  = $_FILES['files']['tmp_name'][$i];
            $size = $_FILES['files']['size'][$i];

            // 🛡️ Validate file size
            if ($size > $maxFileSize) {
                continue;
            }

            // 🛡️ Validate file extension against allowlist
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts, true) === false) {
                continue;
            }

            // 🛡️ Server-side MIME detection (do not trust client-supplied type)
            $type = mime_content_type($tmp) ?: 'application/octet-stream';

            $stored = $claimID . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $orig);
            if (move_uploaded_file($tmp, $upDir . DIRECTORY_SEPARATOR . $stored) === true) {
                $fileStmt->bind_param('issis', $claimID, $orig, $stored, $size, $type);
                $fileStmt->execute();
            }
        }
        $fileStmt->close();
    }

    // 4. 📄 Pending PDF
    ExpensePdf::generate($claimID, 'Pending');

    App::commit();

    // 5. 📓 Log activity
    Logger::activity('ExpenseSubmit', 'Claim #' . $claimID . ' created (£' . number_format($totalAmt, 2) . ')', $userId);

    // 6. 📧 Send email notification to approvers
    ExpenseMailer::notify($claimID, 'submitted', [
        'comments' => '',
    ]);

    // 7. ✅ Redirect to success view
    header('Location: /expenses/submit?success=' . $claimID);
    exit();

} catch (\Throwable $ex) {
    App::rollback();
    Logger::exception($ex);
    $_SESSION['flash_msg']  = 'Error saving claim. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses/submit');
    exit();
}
