<?php
/**
 * 📄 web/public_html/attendance/export.php
 *
 * CSV export endpoint for attendance sessions with counts. Requires Admin role.
 *
 * 🔒 #529 (20 September 2026): this file stays ADMINISTRATORS-ONLY whatever
 *    the new `attend.reports.visibleTo` setting says — the same reason the
 *    anonymous check-in spreadsheet (`export-anonymous.php`) does. A file
 *    leaves the building, gets forwarded, and is still sitting in somebody's
 *    downloads folder after a setting has been tightened again; the owner's
 *    18 September 2026 decision that a download stays administrators-only
 *    applies here too. Nothing here reads AttendanceAccess at all — do not
 *    "fix" that omission.
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
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\App;
use Portal\Core\Site;
use Portal\Core\CsvExporter;

// 🔒 Authentication & authorisation
Auth::ensureSession();
Auth::requireLogin();

// 🔑 Admin required — admin gate lives on App, not Auth.
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ CSRF verification via GET token
if (Auth::verifyCsrf($_GET['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /attendance');
    exit();
}

// 🌐 Site context
$siteId = Site::id();

// 📊 Query attendance sessions with total counts
$sql = "SELECT
            s.sessionID,
            s.sessionDate,
            st.typeName AS ServiceType,
            (SELECT SUM(ac.headcount)
             FROM tblAttendanceCounts ac
             WHERE ac.sessionID = s.sessionID) AS TotalAttendance,
            s.notes
        FROM tblAttendanceSessions s
        LEFT JOIN tblAttendanceServiceTypes st ON st.serviceTypeID = s.serviceTypeID
        WHERE s.siteID = ?
        ORDER BY s.sessionDate DESC";

// 🔌 Use $mysqli rather than $db, because $mysqli works under BOTH routers.
//
//    This line used to say $db. That was NOT broken — a review corrected an
//    earlier claim here that it was, and the correction was right. Router's
//    method signature is `dispatch(mysqli $db)`, and the page is loaded from
//    inside that method, so a page loaded that way inherits $db along with
//    everything else local to it. Checked by experiment, not by reading.
//
//    The reason to prefer $mysqli is narrower and real. There are two routers.
//    Router::dispatch takes $db as a parameter AND imports $mysqli. But
//    ApiRouter::dispatch takes only a path, and imports $mysqli alone — so a
//    page loaded by that one has no $db at all.
//
//    So $db works today for pages reached through an ordinary address, and
//    stops working the moment a page is reached through the data interface
//    instead. $mysqli works in both places. Using it costs nothing and removes
//    a way for this page to break later for a reason nobody would connect to
//    this line.
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

// 📥 Send CSV download
$filename = 'attendance-export-' . date('Y-m-d') . '.csv';
CsvExporter::download($filename, $rows);
