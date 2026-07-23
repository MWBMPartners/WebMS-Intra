<?php
// Path: public_html/api/users/list.php
/**
 * -----------------------------------------------------------------------------
 * Users API — List Users (Admin Only)
 * -----------------------------------------------------------------------------
 * Returns a paginated JSON list of users for the current site. Admin only.
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/95
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Site;

ApiAuth::requireRead('users:read', sessionNeedsAdmin: true);

$db     = App::db();
$siteId = Site::id();
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$cntStmt = $db->prepare(
    'SELECT COUNT(*) AS total FROM tblUserSites us '
    . 'JOIN tblUsers u ON u.userID = us.userID '
    . 'WHERE us.siteID = ? AND us.isActive = 1'
);
if ($cntStmt === false) {
    ApiResponse::error('Database error', 500);
}
$cntStmt->bind_param('i', $siteId);
$cntStmt->execute();
$totalItems = (int) ($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);
$cntStmt->close();
$totalPages = max(1, (int) ceil($totalItems / $limit));

$users = [];
$stmt = $db->prepare(
    'SELECT u.userID, u.fullName, u.emailAddress, u.isActive, u.isAdmin, u.createdAt, '
    . 'us.isSiteAdmin, us.isSiteRootAdmin '
    . 'FROM tblUsers u '
    . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? '
    . 'WHERE us.isActive = 1 ORDER BY u.fullName LIMIT ? OFFSET ?'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $siteId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
}

ApiResponse::success([
    'users' => $users,
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'totalItems' => $totalItems,
        'totalPages' => $totalPages,
        'hasNext'    => $page < $totalPages,
        'hasPrev'    => $page > 1,
    ],
]);
