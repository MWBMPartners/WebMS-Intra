<?php
// Path: apps/expenses/submit/save.php  (v2 with PDF generation)
/**
 * -----------------------------------------------------------------------------
 * Expenses -- Claim Submission Save Handler 💾
 * -----------------------------------------------------------------------------
 * Handles new expense claim submission: inserts DB rows, uploads files, creates
 * a "Pending" PDF via ExpensePdf helper, logs activity, and redirects.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\ExpensePdf;

// 🛡️ Session and security checks
Auth::ensureSession();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    exit('Invalid CSRF');
}
if (Captcha::verify($_POST) === false) {
    exit('Captcha failed');
}

// 📝 Validate required fields
$userId     = $_SESSION['user_id'];
$deptID     = intval($_POST['deptID'] ?? 0);
$claimTitle = trim($_POST['claimTitle'] ?? '');
if ($deptID === 0 || $claimTitle === '') {
    exit('Missing fields');
}

// 🧮 Recalculate total server-side (do not trust client-side hidden field)
$totalAmt = 0.0;
foreach ($_POST['itemDesc'] ?? [] as $i => $desc) {
    if (trim($desc) === '') {
        continue;
    }
    $qty  = intval($_POST['itemQty'][$i] ?? 1);
    $unit = floatval($_POST['itemUnit'][$i] ?? 0);
    $totalAmt += $qty * $unit;
}
if ($totalAmt <= 0) {
    exit('Total must be greater than zero');
}

$mysqli->begin_transaction();
try {
    // 1. 📋 Insert claim header
    $stmt = $mysqli->prepare('INSERT INTO tblExpenseClaims (userID, deptID, claimTitle, claimDate, totalAmount) VALUES (?,?,?,CURDATE(),?)');
    $stmt->bind_param('iisd', $userId, $deptID, $claimTitle, $totalAmt);
    $stmt->execute();
    $claimID = $stmt->insert_id;
    $stmt->close();

    // 2. 📦 Items
    $itemStmt = $mysqli->prepare('INSERT INTO tblExpenseClaimItems (claimID, itemName, quantity, unitCost, lineTotal) VALUES (?,?,?,?,?)');
    foreach ($_POST['itemDesc'] as $i => $desc) {
        $desc = trim($desc);
        if ($desc === '') {
            continue;
        }
        $qty  = intval($_POST['itemQty'][$i] ?? 1);
        $unit = floatval($_POST['itemUnit'][$i] ?? 0);
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
    $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv'];
    $maxFileSize  = 10 * 1024 * 1024; // 10 MB
    $fileStmt = $mysqli->prepare('INSERT INTO tblExpenseClaimFiles (claimID, originalFilename, storedFilename, fileSize, fileType) VALUES (?,?,?,?,?)');
    foreach ($_FILES['files']['error'] ?? [] as $i => $err) {
        if ($err !== UPLOAD_ERR_OK) {
            continue;
        }
        $orig   = basename($_FILES['files']['name'][$i]);
        $tmp    = $_FILES['files']['tmp_name'][$i];
        $size   = $_FILES['files']['size'][$i];

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

    // 4. 📄 Pending PDF
    ExpensePdf::generate($claimID, 'Pending');

    $mysqli->commit();
    Logger::activity('ExpenseSubmit', 'Claim #' . $claimID . ' created', $userId);

    // ✅ Redirect to success view
    header('Location: /expenses/submit?success=' . $claimID);
    exit();

} catch (Throwable $ex) {
    $mysqli->rollback();
    Logger::exception($ex);
    exit('Error saving claim');
}
