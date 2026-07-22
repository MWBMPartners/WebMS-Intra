<?php
// Path: public_html/documents/api/list.php
/**
 * -----------------------------------------------------------------------------
 * Documents API — List
 * -----------------------------------------------------------------------------
 * Returns documents visible to the current user, optionally filtered by
 * category.
 *
 *   GET /api/documents/list?categoryID=N&page=N&limit=N
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

ApiAuth::requireRead('documents:read');

$db     = App::db();
$siteId = Site::id();
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;
$catId  = isset($_GET['categoryID']) === true ? (int) $_GET['categoryID'] : 0;

$conditions = ['d.siteID = ?', 'd.isDeleted = 0'];
$types      = 'i';
$params     = [$siteId];
if ($catId > 0) {
    $conditions[] = 'd.categoryID = ?';
    $types       .= 'i';
    $params[]     = $catId;
}

$sql = 'SELECT d.documentID, d.title, d.description, d.filename, d.fileSize, '
     . 'd.mimeType, d.createdAt, d.uploadedByID, '
     . 'c.categoryName, u.fullName AS uploaderName '
     . 'FROM tblDocuments d '
     . 'LEFT JOIN tblDocCategories c ON c.categoryID = d.categoryID '
     . 'LEFT JOIN tblUsers u ON u.userID = d.uploadedByID '
     . 'WHERE ' . implode(' AND ', $conditions) . ' '
     . 'ORDER BY d.createdAt DESC LIMIT ? OFFSET ?';
$types   .= 'ii';
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
if ($stmt === false) {
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ApiResponse::success([
    'count' => count($rows),
    'page'  => $page,
    'limit' => $limit,
    'items' => $rows,
]);
