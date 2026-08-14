<?php
// Path: public_html/leadership/api/list.php
/**
 * -----------------------------------------------------------------------------
 * Leadership API — List Roles + Assignments
 * -----------------------------------------------------------------------------
 * Returns active leadership roles and their current assignees for the site.
 *
 *   GET /api/leadership/list?role=<roleID>   (optional filter)
 *
 * @package   Portal\API
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Site;

ApiAuth::requireRead('leadership:read');

$db     = App::db();
$siteId = Site::id();
$roleId = isset($_GET['role']) === true ? (int) $_GET['role'] : 0;

$sql = 'SELECT r.roleID, r.roleName, r.description, '
     . 'a.assignmentID, a.userID, a.assignedAt, '
     . 'u.fullName AS assigneeName '
     . 'FROM tblLeadershipRoles r '
     . 'LEFT JOIN tblLeadershipAssignments a ON a.roleID = r.roleID AND a.isActive = 1 '
     . 'LEFT JOIN tblUsers u ON u.userID = a.userID '
     . 'WHERE r.siteID = ? AND r.isActive = 1';
$types  = 'i';
$params = [$siteId];
if ($roleId > 0) {
    $sql    .= ' AND r.roleID = ?';
    $types  .= 'i';
    $params[] = $roleId;
}
$sql .= ' ORDER BY r.sortOrder, r.roleName, u.fullName';

$stmt = $db->prepare($sql);
if ($stmt === false) {
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ApiResponse::success(['count' => count($rows), 'items' => $rows]);
