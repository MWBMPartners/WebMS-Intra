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
 * @version   0.8.2
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

// 🔑 Admin required — admin gate lives on App, not Auth (the latter has
//    no isAdmin() method, so the original call fatalled before any export
//    logic could run).
if (App::isAdmin() === false) {
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
// 🛡️ tblActivityLogs has no `action`/`detail`/`createdAt` columns — the
//    real names are `activityType`, `activityDescription`, `timestamp`
//    (see full_schema.sql). The old column names made prepare() return
//    false, so the unguarded bind_param() below fatalled every export.
$sql = "SELECT
            l.logID,
            l.activityType,
            l.activityDescription,
            l.timestamp  AS Timestamp,
            u.fullName   AS User
        FROM tblActivityLogs l
        LEFT JOIN tblUsers u ON u.userID = l.userID
        WHERE (l.siteID = ? OR l.siteID IS NULL)
        ORDER BY l.timestamp DESC
        LIMIT 5000";

// 🔌 The database connection is called $mysqli here, not $db.
//
//    This line used to say $db, and every one of these export pages crashed the
//    instant somebody pressed the button. Nothing was ever produced and no file
//    was ever downloaded.
//
//    The reason is worth knowing, because it is not obvious from reading this
//    file alone. When the portal opens a page, it hands it exactly two things:
//    $mysqli and $SETTINGS (see Router.php, the `global` line just before the
//    page is loaded). Anything else a page reaches for simply is not there. $db
//    was never one of the two, so it was empty, and asking an empty thing to
//    prepare a query stops the page dead.
//
//    It failed silently in the way that matters: the button looked fine, and
//    the fault only appeared at the moment somebody actually used it.
$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
    $_SESSION['flash_msg']  = t('error.db_export_activity');
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/activity');
    exit();
}
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
