<?php
/**
 * 📄 web/public_html/expenses/api/export.php
 *
 * CSV export endpoint for expense claims. Requires Admin or Approver role.
 *
 * @package   WebMS Intra
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present All Rights Reserved
 * @license   All Rights Reserved
 * @version   0.8.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/77
 */

declare(strict_types=1);

// 🔧 Bootstrap
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\App;
use Portal\Core\Site;
use Portal\Core\CsvExporter;

// 🔒 Authentication & authorisation
Auth::ensureSession();
Auth::requireLogin();

// 🔑 Only Admin or Approver may export expenses — these gates live on App,
//    not Auth. Auth::isAdmin() / Auth::isApprover() don't exist so the
//    original code fatalled before any export logic could run.
$isAdmin    = App::isAdmin();
$isApprover = App::hasRole('Approver');

if ($isAdmin === false && $isApprover === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ CSRF verification via GET token
if (Auth::verifyCsrf($_GET['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /expenses');
    exit();
}

// 🌐 Site context
$siteId = Site::id();

// 📊 Query expense claims
$sql = "SELECT
            EC.claimID,
            U.fullName       AS Claimant,
            D.deptName       AS Department,
            EC.claimTitle    AS Title,
            EC.claimDate     AS Date,
            EC.totalAmount   AS Amount,
            EC.status        AS Status
        FROM tblExpenseClaims EC
        JOIN tblUsers U ON U.userID = EC.userID
        LEFT JOIN tblDepts D ON D.deptID = EC.deptID
        WHERE EC.siteID = ?
        ORDER BY EC.claimDate DESC";

$stmt = $db->prepare($sql);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

// 📥 Send CSV download
$filename = 'expenses-export-' . date('Y-m-d') . '.csv';
CsvExporter::download($filename, $rows);
