<?php
// Path: apps/expenses/api/list.php
/**
 * -----------------------------------------------------------------------------
 * Expenses API – List Claims 📋
 * -----------------------------------------------------------------------------
 * Returns a paginated list of expense claims for the authenticated user.
 * Admin users can optionally view all claims across the organisation.
 *
 * Endpoint: GET /api/expenses/list
 *
 * Query parameters:
 *   page     (int)    Page number, default 1
 *   limit    (int)    Items per page, default 20, max 100
 *   status   (string) Filter by status: Pending|Approved|Rejected|Reimbursed
 *   all      (bool)   If "true" and user is admin, return all users' claims
 *
 * Response (200):
 *   { status: "ok", data: { claims: [...], pagination: {...} }, meta: {...} }
 *
 * @see       core/ApiResponse.php for the standard response envelope
 * @package   Portal\App\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   MIT
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\App;

// 🔐 Require authentication for this API endpoint
ApiResponse::requireAuth();

// 📌 Parse query parameters with defaults and bounds
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$status = $_GET['status'] ?? '';
$showAll = ($_GET['all'] ?? '') === 'true';

// 🛡️ Validate status filter if provided
$validStatuses = ['Pending', 'Approved', 'Rejected', 'Reimbursed'];
if ($status !== '' && in_array($status, $validStatuses, true) === false) {
    ApiResponse::error('Invalid status filter. Valid values: ' . implode(', ', $validStatuses), 400);
}

// 📊 Build the query dynamically based on filters
$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$offset = ($page - 1) * $limit;

// 🔍 Determine scope: own claims or all claims (admin only)
$whereClause = 'WHERE EC.userID = ?';
$params      = [$userId];
$types       = 'i';

if ($showAll === true && App::isAdmin() === true) {
    $whereClause = 'WHERE 1=1';
    $params      = [];
    $types       = '';
}

// 📌 Add status filter if specified
if ($status !== '') {
    $whereClause .= ' AND EC.status = ?';
    $params[]     = $status;
    $types       .= 's';
}

// 📊 Count total matching records for pagination
$countSql = 'SELECT COUNT(*) AS total FROM tblExpenseClaims EC ' . $whereClause;
$countStmt = $db->prepare($countSql);
if ($countStmt === false) {
    ApiResponse::error('Database error', 500, 'Prepare failed: ' . $db->error);
}

if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalCount  = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$countStmt->close();

// 📝 Fetch the claims with pagination
$dataSql = 'SELECT EC.claimID, EC.claimTitle, EC.claimDate, EC.totalAmount, EC.status, '
         . 'EC.createdAt, EC.updatedAt, '
         . 'U.fullName AS claimantName, U.emailAddress AS claimantEmail, '
         . 'D.deptName '
         . 'FROM tblExpenseClaims EC '
         . 'JOIN tblUsers U ON U.userID = EC.userID '
         . 'JOIN tblDepts D ON D.deptID = EC.deptID '
         . $whereClause
         . ' ORDER BY EC.createdAt DESC '
         . 'LIMIT ? OFFSET ?';

// 📌 Append pagination params
$params[] = $limit;
$params[] = $offset;
$types   .= 'ii';

$dataStmt = $db->prepare($dataSql);
if ($dataStmt === false) {
    ApiResponse::error('Database error', 500, 'Prepare failed: ' . $db->error);
}

$dataStmt->bind_param($types, ...$params);
$dataStmt->execute();
$result = $dataStmt->get_result();

$claims = [];
while ($row = $result->fetch_assoc()) {
    // 🛡️ Filter sensitive data and format output
    $claims[] = ApiResponse::filterSensitive([
        'claimID'       => (int) $row['claimID'],
        'title'         => $row['claimTitle'],
        'claimDate'     => $row['claimDate'],
        'totalAmount'   => (float) $row['totalAmount'],
        'status'        => $row['status'],
        'claimantName'  => $row['claimantName'],
        'claimantEmail' => $row['claimantEmail'],
        'department'    => $row['deptName'],
        'createdAt'     => $row['createdAt'],
        'updatedAt'     => $row['updatedAt'],
    ]);
}
$dataStmt->close();

// 📦 Build pagination metadata
$totalPages = (int) ceil($totalCount / $limit);

// ✅ Send successful response
ApiResponse::success([
    'claims'     => $claims,
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'totalItems' => $totalCount,
        'totalPages' => $totalPages,
        'hasNext'    => $page < $totalPages,
        'hasPrev'    => $page > 1,
    ],
]);
