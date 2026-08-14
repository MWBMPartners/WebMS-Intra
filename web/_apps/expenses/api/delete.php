<?php
// Path: _apps/expenses/api/delete.php
/**
 * -----------------------------------------------------------------------------
 * Expenses API — Delete Claim 💰
 * -----------------------------------------------------------------------------
 * Dual-mode (bearer API key OR any logged-in session) endpoint that hard-
 * deletes an expense claim. ONLY claims still in 'Pending' status may be
 * deleted — once a claim has entered the approval workflow, deletion via
 * the API is refused (409); use the withdraw / approval flows instead.
 *
 * Session mode: owner-or-admin (the claimant may delete their own Pending
 * claim; an admin may delete any Pending claim for the site). Bearer mode:
 * the `expenses:write` scope + the key's site-pin is sufficient (no separate
 * ownership check — a key already operates within a single site).
 *
 *   DELETE /api/v1/expenses/{id}
 *   (or POST /api/expenses/delete?id=N — legacy alias, {"claimID": N} in body)
 *
 * @package   Portal\API\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/323
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Site;

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('expenses:write', sessionNeedsAdmin: false);

$claimId = (int) ($_GET['id'] ?? $body['claimID'] ?? 0);
if ($claimId <= 0) {
    ApiResponse::error('claimID is required', 400);
}

$db     = App::db();
$siteId = Site::id();

// 🔍 Fetch the claim first — 404 if missing/wrong site; needed for both the
//    owner-or-admin check (session mode) and the audit trail's oldData.
$fetch = $db->prepare(
    'SELECT claimID, siteID, userID, deptID, claimTitle, claimDate, totalAmount, status '
    . 'FROM tblExpenseClaims WHERE claimID = ? AND siteID = ? LIMIT 1'
);
if ($fetch === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EXP_DELETE_FETCH_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$fetch->bind_param('ii', $claimId, $siteId);
$fetch->execute();
$old = $fetch->get_result()->fetch_assoc();
$fetch->close();
if ($old === null) {
    ApiResponse::error('Expense claim not found', 404);
}

// 🛡️ Session mode: owner-or-admin. Bearer mode: scope + site-pin suffices.
if (ApiAuth::source() === 'session') {
    $actorId  = ApiAuth::actorUserId() ?? 0;
    $isOwner  = (int) $old['userID'] === $actorId;
    $isAdmin  = App::isAdmin() === true;
    if ($isOwner === false && $isAdmin === false) {
        ApiResponse::error('Access denied — only the claimant or an admin may delete this claim', 403);
    }
}

// 🛡️ Only Pending claims can be deleted via the API
if ($old['status'] !== 'Pending') {
    ApiResponse::error('Only Pending claims can be deleted', 409);
}

// -----------------------------------------------------------------------------
// 💾 Transaction: line items first (FK), then the claim header
// -----------------------------------------------------------------------------
App::beginTransaction();
try {
    $itemStmt = $db->prepare('DELETE FROM tblExpenseClaimItems WHERE claimID = ?');
    if ($itemStmt === false) {
        throw new \RuntimeException('Failed to prepare item delete: ' . $db->error);
    }
    $itemStmt->bind_param('i', $claimId);
    if ($itemStmt->execute() === false) {
        throw new \RuntimeException('Failed to delete claim items: ' . $itemStmt->error);
    }
    $itemStmt->close();

    $stmt = $db->prepare('DELETE FROM tblExpenseClaims WHERE claimID = ? AND siteID = ? AND status = ?');
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare claim delete: ' . $db->error);
    }
    $pendingStatus = 'Pending';
    $stmt->bind_param('iis', $claimId, $siteId, $pendingStatus);
    if ($stmt->execute() === false) {
        throw new \RuntimeException('Failed to delete claim: ' . $stmt->error);
    }
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        // 🏃 Raced with a status change between the earlier fetch and here.
        throw new \RuntimeException('Claim status changed before deletion could complete');
    }

    App::commit();
} catch (\Throwable $ex) {
    App::rollback();
    Logger::errorPlatform('MySQL', 'Error', 'API_EXP_DELETE_FAIL', $ex->getMessage(), '');
    ApiResponse::error('Failed to delete expense claim', 500);
}

Logger::audit('tblExpenseClaims', $claimId, 'delete', $old, null);
Logger::activity('ApiExpenseDelete', 'API: deleted expense claim #' . $claimId);

ApiResponse::success(['claimID' => $claimId, 'deleted' => true], 200);
