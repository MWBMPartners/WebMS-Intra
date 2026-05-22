<?php
/**
 * 📄 web/public_html/leadership/export.php
 *
 * CSV export endpoint for current leadership assignments. Any authenticated user
 * may export this read-only directory data.
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
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\App;
use Portal\Core\Site;
use Portal\Core\CsvExporter;

// 🔒 Authentication (any logged-in user)
Auth::ensureSession();
Auth::requireLogin();

// 🛡️ CSRF verification via GET token
if (Auth::verifyCsrf($_GET['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /leadership');
    exit();
}

// 🌐 Site context
$siteId = Site::id();

// 📊 Query current leadership assignments
$sql = "SELECT
            r.roleName                                       AS Role,
            COALESCE(u.fullName, a.personName, 'Unknown')    AS Person,
            COALESCE(u.emailAddress, a.personEmail, '')       AS Email,
            a.startDate                                      AS 'Start Date',
            a.endDate                                        AS 'End Date'
        FROM tblLeadershipAssignments a
        INNER JOIN tblLeadershipRoles r ON r.roleID = a.roleID
        LEFT JOIN tblUsers u ON u.userID = a.userID
        WHERE a.siteID = ?
          AND a.isActive = 1
          AND (a.endDate IS NULL OR a.endDate >= CURDATE())
        ORDER BY r.sortOrder, r.roleName, a.startDate";

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
$filename = 'leadership-export-' . date('Y-m-d') . '.csv';
CsvExporter::download($filename, $rows);
