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
 *   status   (string) Filter by status: Pending|Approved|Rejected|Reimbursed|Withdrawn
 *   all      (bool)   If "true" and user is admin, return all users' claims
 *
 * Response (200):
 *   { status: "ok", data: { claims: [...], pagination: {...} }, meta: {...} }
 *
 * @see       core/ApiResponse.php for the standard response envelope
 * @package   Portal\App\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Site;

// 🔐 Require authentication for this API endpoint
ApiAuth::requireRead('expenses:read');

// 📌 Parse query parameters with defaults and bounds
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$status = $_GET['status'] ?? '';
$showAll = ($_GET['all'] ?? '') === 'true';

// 🛡️ Validate status filter if provided.
//    These are the choices of the column `tblExpenseClaims.status`, in the
//    column's own order, and must stay the same list. 'Withdrawn' was added
//    with migration 211 (#587): without it, the unfiltered list returned a
//    withdrawn claim while `?status=Withdrawn` was refused with 400.
//    tools/expense-withdraw-selftest.php compares this list with the column.
$validStatuses = ['Pending', 'Approved', 'Rejected', 'Reimbursed', 'Withdrawn'];
if ($status !== '' && in_array($status, $validStatuses, true) === false) {
    ApiResponse::error('Invalid status filter. Valid values: ' . implode(', ', $validStatuses), 400);
}

// 📊 Build the query dynamically based on filters
$db     = App::db();
$siteId = Site::id();
$offset = ($page - 1) * $limit;

// 🔍 Determine scope: own claims (session) or all claims (admin ?all=true,
// or ANY bearer/API-key request). A bearer key carries no session — reading
// $_SESSION['user_id'] under a bearer request always yielded 0, matching
// zero rows. ApiAuth::actorUserId() is null in apikey mode by design (see
// ApiAuth::actorUserId()), so a key request is scoped to its pinned site
// only, exactly as delete.php reasons: "a key already operates within a
// single site" — no separate per-user ownership scope applies.
$isBearer = ApiAuth::source() === 'apikey';
$userId   = $isBearer === false ? (ApiAuth::actorUserId() ?? 0) : 0;

$whereClause = 'WHERE EC.userID = ? AND EC.siteID = ?';
$params      = [$userId, $siteId];
$types       = 'ii';
// Which of the two scopes was used, for the activity record below (D-130).
$everyClaim  = false;

if ($isBearer === true || ($showAll === true && App::isAdmin() === true)) {
    $everyClaim  = true;
    $whereClause = 'WHERE EC.siteID = ?';
    $params      = [$siteId];
    $types       = 'i';
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
$dataSql = 'SELECT EC.claimID, EC.claimTitle, EC.claimDate, EC.totalAmount, EC.`currency`, EC.status, '
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
        // 💷 The claim's OWN currency (#966, D-139): two claims may be in two
        //    currencies, so an amount is never sent without it. '' = not recorded.
        'currency'      => (string) $row['currency'],
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

// 📝 D-130 (the owner, 11 November 2026): every look at expense records is
//    recorded, and this list hands claim rows — claimants' names and e-mail
//    addresses among them — out of the portal, so it is an export of claims
//    like the spreadsheet. One `ExpenseClaimsListApi` record saying WHAT was
//    asked for (whose claims, the status filter, the page) and how many rows
//    came back; never the rows. It names a person when a signed-in session
//    asked. A request made with an API key has no person (#626 —
//    `Logger::activity()` never invents one), so the record names the KEY by
//    its number, as `AccountGuard` does ("API key #N", from
//    `ApiAuth::apiKeyId()`) — never the key's secret. Without it the most
//    sensitive look in Expenses (every claimant's name and e-mail address,
//    through a key) said nothing about who looked (round 3 of the check).
//    The same rule as every other expense look: a list that returned NO
//    claim showed nothing and records nothing; a refused request is answered
//    above and records nothing either.
if (count($claims) > 0) {
    Logger::activity(
        'ExpenseClaimsListApi',
        'Listed expense claims through the API'
            . ($isBearer === true ? ' with API key #' . (ApiAuth::apiKeyId() ?? 0) : '')
            . ': ' . ($everyClaim === true ? 'every claim of the organisation' : 'own claims')
            . ($status !== '' ? ', status ' . $status : '')
            . ', page ' . $page . ' of ' . max(1, $totalPages) . ', ' . count($claims) . ' claim(s)'
    );
}

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
