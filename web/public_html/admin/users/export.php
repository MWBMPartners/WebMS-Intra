<?php
/**
 * 📄 web/public_html/admin/users/export.php
 *
 * CSV export endpoint for the user list. Requires Admin role.
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

// 🔑 Admin required
if (Auth::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ CSRF verification via GET token
if (Auth::verifyCsrf($_GET['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/users');
    exit();
}

// 🌐 Site context
$siteId = Site::id();

// 📊 Query users for this site
$sql = "SELECT
            u.userID,
            u.fullName        AS Name,
            u.emailAddress    AS Email,
            u.phoneNumber     AS Phone,
            CASE WHEN u.isActive = 1 THEN 'Active' ELSE 'Inactive' END AS Status,
            CASE WHEN u.isAdmin = 1  THEN 'Yes'    ELSE 'No'       END AS Admin
        FROM tblUsers u
        INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ?
        ORDER BY u.fullName";

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
$filename = 'users-export-' . date('Y-m-d') . '.csv';
CsvExporter::download($filename, $rows);
