<?php
/**
 * 📄 web/public_html/attendance/export.php
 *
 * CSV export endpoint for attendance sessions with counts. Requires Admin role.
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
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

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
    http_response_code(403);
    exit('Invalid CSRF token');
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
        LEFT JOIN tblAttendanceServiceTypes st ON st.typeID = s.serviceTypeID
        WHERE s.siteID = ?
        ORDER BY s.sessionDate DESC";

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
$filename = 'attendance-export-' . date('Y-m-d') . '.csv';
CsvExporter::download($filename, $rows);
