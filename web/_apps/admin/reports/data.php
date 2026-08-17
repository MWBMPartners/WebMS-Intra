<?php
// Path: public_html/admin/reports/data.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Reports JSON Data Endpoint
 * -----------------------------------------------------------------------------
 * Returns report data as JSON for dynamic chart rendering. Admin only.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.3
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/93
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
if (Auth::check() === false || App::isAdmin() !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit();
}

$siteId  = Site::id();
$report  = trim($_GET['report'] ?? '');

header('Content-Type: application/json');

switch ($report) {
    case 'monthly_logins':
        $data = [];
        // 🛡️ tblActivityLogs has no `createdAt` column — it's `timestamp`
        //    (see full_schema.sql). The old column name made prepare()
        //    fail, so this silently returned [] on every call.
        $stmt = $mysqli->prepare(
            'SELECT DATE_FORMAT(`timestamp`, \'%Y-%m\') AS month, COUNT(*) AS cnt '
            . 'FROM tblActivityLogs WHERE activityType = \'Login\' '
            . 'AND (siteID = ? OR siteID IS NULL) '
            . 'AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 12 MONTH) '
            . 'GROUP BY month ORDER BY month'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
        }
        echo json_encode(['status' => 'ok', 'data' => $data]);
        break;

    case 'expense_monthly':
        $data = [];
        $stmt = $mysqli->prepare(
            'SELECT DATE_FORMAT(claimDate, \'%Y-%m\') AS month, '
            . 'COUNT(*) AS claims, COALESCE(SUM(totalAmount), 0) AS total '
            . 'FROM tblExpenseClaims WHERE siteID = ? '
            . 'AND claimDate >= DATE_SUB(NOW(), INTERVAL 12 MONTH) '
            . 'GROUP BY month ORDER BY month'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
        }
        echo json_encode(['status' => 'ok', 'data' => $data]);
        break;

    case 'attendance_monthly':
        $data = [];
        $stmt = $mysqli->prepare(
            'SELECT DATE_FORMAT(sessionDate, \'%Y-%m\') AS month, '
            . 'COUNT(*) AS sessions, COALESCE(SUM(headcount), 0) AS attendance '
            . 'FROM tblAttendanceSessions WHERE siteID = ? '
            . 'AND sessionDate >= DATE_SUB(NOW(), INTERVAL 12 MONTH) '
            . 'GROUP BY month ORDER BY month'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
        }
        echo json_encode(['status' => 'ok', 'data' => $data]);
        break;

    default:
        echo json_encode(['status' => 'error', 'error' => 'Unknown report type']);
        break;
}

exit();
