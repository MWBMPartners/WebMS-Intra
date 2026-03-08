<?php
/**
 * 📄 web/public_html/admin/activity/export.php
 *
 * CSV export endpoint for activity logs. Requires Admin role.
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
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

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
    header('Location: /admin/activity');
    exit();
}

// 🌐 Site context
$siteId = Site::id();

// 📊 Query activity logs (capped at 5000 rows)
$sql = "SELECT
            l.logID,
            l.action,
            l.detail,
            l.createdAt  AS Timestamp,
            u.fullName   AS User
        FROM tblActivityLogs l
        LEFT JOIN tblUsers u ON u.userID = l.userID
        WHERE (l.siteID = ? OR l.siteID IS NULL)
        ORDER BY l.createdAt DESC
        LIMIT 5000";

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
$filename = 'activity-log-export-' . date('Y-m-d') . '.csv';
CsvExporter::download($filename, $rows);
