<?php
/**
 * 📄 web/public_html/attendance/export.php
 *
 * CSV export endpoint for attendance sessions with counts.
 *
 * 🔒 #529 / D-106 (the owner, 10 November 2026): this spreadsheet is the
 *    attendance totals in a file, so it follows the SAME rule as the reports
 *    page, asked of the ONE place that decides it,
 *    `AttendanceAccess::mayViewTotals()`: the organisation's administrators,
 *    plus whoever the organisation points the permission "See the attendance
 *    totals" at on Admin, Roles. D-106 names "its CSV export" in so many words.
 *
 *    UNTIL THEN it was administrators-only, borrowing the owner's
 *    18 September 2026 reasoning for the ANONYMOUS check-in spreadsheet ("a
 *    file leaves the building"). That decision was about
 *    `export-anonymous.php`, which names events, and it still stands there;
 *    this file follows D-106 instead. Out of the box nothing widens: the role
 *    the permission ships pointing at is held by nobody.
 *
 *    ⚠️ The `notes` column is free text somebody typed when recording a
 *    session, and it goes into this file. Anybody given the permission reads
 *    it here.
 *
 *    Everybody else gets the ordinary "page not found", the same as an address
 *    that does not exist — and it is asked BEFORE the form token, so a refused
 *    member is never sent back to the attendance page with a message that
 *    tells them the download exists.
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
use Portal\Core\AttendanceAccess;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\CsvExporter;

// 🔒 Authentication & authorisation
Auth::ensureSession();
Auth::requireLogin();

// 🔑 #529 / D-106: administrators and holders of "See the attendance totals".
//    Everybody else: the ordinary "page not found", identical to an address
//    that does not exist.
if (AttendanceAccess::mayViewTotals($mysqli, (int) ($_SESSION['user_id'] ?? 0), Site::id(), App::isAdmin(), App::isRootAdmin()) === false) {
    Router::renderError(404);
    exit();
}

// 🛡️ CSRF verification via GET token
if (Auth::verifyCsrf($_GET['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('attendance'));
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
