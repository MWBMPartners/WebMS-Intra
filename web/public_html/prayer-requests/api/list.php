<?php
// Path: public_html/prayer-requests/api/list.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Requests API — List
 * -----------------------------------------------------------------------------
 * Returns the user's own requests + active congregation-visible requests.
 * Admins see everything regardless of visibility.
 *
 *   GET /api/prayer-requests/list?status=pending|active|answered|archived
 *
 * @package   Portal\API
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Site;

ApiResponse::requireAuth();

$db     = App::db();
$siteId = Site::id();
$me     = App::user();
$myId   = (int) ($me['userID'] ?? 0);
$isAdm  = App::isAdmin();

$status = (string) ($_GET['status'] ?? 'active');
if (in_array($status, ['pending', 'active', 'answered', 'archived', 'all'], true) === false) {
    $status = 'active';
}

$conditions = ['siteID = ?'];
$types      = 'i';
$params     = [$siteId];

// 🛡️ Visibility: non-admins see only their own + congregation-visible
if ($isAdm === false) {
    $conditions[] = '(submitterID = ? OR visibility = \'congregation\')';
    $types       .= 'i';
    $params[]     = $myId;
}
if ($status !== 'all') {
    $conditions[] = 'status = ?';
    $types       .= 's';
    $params[]     = $status;
}

$sql = 'SELECT requestID, submitterID, subject, body, visibility, status, isAnonymous, '
     . 'answeredAt, testimony, createdAt '
     . 'FROM tblPrayerRequests WHERE ' . implode(' AND ', $conditions) . ' '
     . 'ORDER BY createdAt DESC LIMIT 200';

$stmt = $db->prepare($sql);
if ($stmt === false) {
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 🛡️ Mask submitterID for anonymous public-feed entries — caller should
// not be able to derive who submitted just from the list response.
foreach ($rows as &$r) {
    if ((int) ($r['isAnonymous'] ?? 0) === 1 && $isAdm === false) {
        $r['submitterID'] = null;
    }
}

ApiResponse::success(['count' => count($rows), 'items' => $rows]);
