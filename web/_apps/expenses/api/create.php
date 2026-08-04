<?php
// Path: _apps/expenses/api/create.php
/**
 * -----------------------------------------------------------------------------
 * Expenses API — Submit Claim 💰
 * -----------------------------------------------------------------------------
 * Dual-mode (bearer API key OR any logged-in session — submitting is a member
 * action, not an admin one) endpoint that submits a new expense claim with
 * one or more line items. Line totals and the claim total are computed
 * SERVER-SIDE and are never trusted from the client. New claims always land
 * in 'Pending' status — the approver workflow is entirely out of v1 scope
 * (see `_apps/expenses/approve/save.php` for the multi-approver transition
 * path, and #323 Phase 3 for a shared status-transition endpoint).
 *
 *   POST /api/v1/expenses
 *   Content-Type: application/json
 *   {
 *     "deptID":     3,                              (required, FK tblDepts)
 *     "claimTitle": "Youth camp supplies",           (required, ≤255)
 *     "claimDate":  "2026-07-18",                    (required, Y-m-d)
 *     "userID":     42,                               (bearer mode ONLY — required)
 *     "items": [                                       (required, non-empty)
 *       { "itemName": "Snacks", "unitCost": 12.50, "quantity": 3,
 *         "description": "…", "purchaseDate": "2026-07-17", "supplier": "Tesco" }
 *     ]
 *   }
 *
 * Owner resolution: session mode uses the authenticated user's own ID
 * (`ApiAuth::actorUserId()`); bearer mode has no session, so the caller MUST
 * supply `userID` in the body, and that user must exist and belong to the
 * key's own (pinned) site — this supports integration/import use-cases
 * without letting a key attribute claims to an arbitrary user elsewhere.
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

$db     = App::db();
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 👤 Owner resolution — session actor OR (bearer) explicit userID
// -----------------------------------------------------------------------------
if (ApiAuth::source() === 'apikey') {
    $ownerUserId = (int) ($body['userID'] ?? 0);
    if ($ownerUserId <= 0) {
        ApiResponse::error('userID is required for API-key submissions', 400);
    }
    $ownerCheck = $db->prepare(
        'SELECT us.userID FROM tblUserSites us '
        . 'WHERE us.userID = ? AND us.siteID = ? AND us.isActive = 1 LIMIT 1'
    );
    if ($ownerCheck === false) {
        Logger::errorPlatform('MySQL', 'Error', 'API_EXP_CREATE_OWNER_PREP', $db->error, '');
        ApiResponse::error('Database error', 500);
    }
    $ownerCheck->bind_param('ii', $ownerUserId, $siteId);
    $ownerCheck->execute();
    $ownerExists = $ownerCheck->get_result()->fetch_assoc() !== null;
    $ownerCheck->close();
    if ($ownerExists === false) {
        ApiResponse::error('userID does not exist or does not belong to this site', 400);
    }
} else {
    $ownerUserId = ApiAuth::actorUserId() ?? 0;
    if ($ownerUserId <= 0) {
        ApiResponse::error('Authentication required', 401);
    }
}

// -----------------------------------------------------------------------------
// 📥 Required claim fields
// -----------------------------------------------------------------------------
$deptId = (int) ($body['deptID'] ?? 0);
if ($deptId <= 0) {
    ApiResponse::error('deptID is required', 400);
}
$deptCheck = $db->prepare('SELECT deptID FROM tblDepts WHERE deptID = ? AND siteID = ? LIMIT 1');
if ($deptCheck === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_EXP_CREATE_DEPT_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$deptCheck->bind_param('ii', $deptId, $siteId);
$deptCheck->execute();
$deptExists = $deptCheck->get_result()->fetch_assoc() !== null;
$deptCheck->close();
if ($deptExists === false) {
    ApiResponse::error('deptID does not exist for this site', 400);
}

$claimTitle = trim((string) ($body['claimTitle'] ?? ''));
if ($claimTitle === '' || mb_strlen($claimTitle) > 255) {
    ApiResponse::error('claimTitle is required and must be ≤255 characters', 400);
}

$claimDateRaw = trim((string) ($body['claimDate'] ?? ''));
if ($claimDateRaw === '') {
    ApiResponse::error('claimDate is required', 400);
}
$claimDateObj = \DateTimeImmutable::createFromFormat('Y-m-d', $claimDateRaw);
if ($claimDateObj === false || $claimDateObj->format('Y-m-d') !== $claimDateRaw) {
    ApiResponse::error('claimDate must be a valid Y-m-d date', 400);
}
$claimDate = $claimDateObj->format('Y-m-d');

// -----------------------------------------------------------------------------
// 📥 Line items — REQUIRED, non-empty. lineTotal + totalAmount computed here.
// -----------------------------------------------------------------------------
$itemsRaw = $body['items'] ?? null;
if (is_array($itemsRaw) === false || count($itemsRaw) === 0) {
    ApiResponse::error('items is required and must be a non-empty array', 400);
}

$items      = [];
$totalAmount = 0.0;
foreach ($itemsRaw as $idx => $itemRaw) {
    if (is_array($itemRaw) === false) {
        ApiResponse::error('items[' . $idx . '] must be an object', 400);
    }

    $itemName = trim((string) ($itemRaw['itemName'] ?? ''));
    if ($itemName === '' || mb_strlen($itemName) > 255) {
        ApiResponse::error('items[' . $idx . '].itemName is required and must be ≤255 characters', 400);
    }

    $description = isset($itemRaw['description']) === true ? trim((string) $itemRaw['description']) : null;
    if ($description === '') {
        $description = null;
    }

    if (is_numeric($itemRaw['unitCost'] ?? null) === false || (float) $itemRaw['unitCost'] < 0) {
        ApiResponse::error('items[' . $idx . '].unitCost must be a decimal ≥ 0', 400);
    }
    $unitCost = round((float) $itemRaw['unitCost'], 2);

    $quantity = (int) ($itemRaw['quantity'] ?? 0);
    if ($quantity < 1) {
        ApiResponse::error('items[' . $idx . '].quantity must be an integer ≥ 1', 400);
    }

    $purchaseDate = null;
    if (isset($itemRaw['purchaseDate']) === true && trim((string) $itemRaw['purchaseDate']) !== '') {
        $pdRaw = trim((string) $itemRaw['purchaseDate']);
        $pdObj = \DateTimeImmutable::createFromFormat('Y-m-d', $pdRaw);
        if ($pdObj === false || $pdObj->format('Y-m-d') !== $pdRaw) {
            ApiResponse::error('items[' . $idx . '].purchaseDate must be a valid Y-m-d date', 400);
        }
        $purchaseDate = $pdObj->format('Y-m-d');
    }

    $supplier = isset($itemRaw['supplier']) === true ? trim((string) $itemRaw['supplier']) : null;
    if ($supplier !== null && mb_strlen($supplier) > 255) {
        ApiResponse::error('items[' . $idx . '].supplier must be ≤255 characters', 400);
    }
    if ($supplier === '') {
        $supplier = null;
    }

    // 🧮 Server-side line total — NEVER trust a client-supplied lineTotal.
    $lineTotal = round($unitCost * $quantity, 2);
    $totalAmount += $lineTotal;

    $items[] = [
        'itemName'     => $itemName,
        'description'  => $description,
        'unitCost'     => $unitCost,
        'quantity'     => $quantity,
        'lineTotal'    => $lineTotal,
        'purchaseDate' => $purchaseDate,
        'supplier'     => $supplier,
    ];
}
$totalAmount = round($totalAmount, 2);

$status = 'Pending';

// -----------------------------------------------------------------------------
// 💾 Transaction: claim header + line items
// -----------------------------------------------------------------------------
App::beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT INTO tblExpenseClaims (siteID, userID, deptID, claimTitle, claimDate, totalAmount, status) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare claim insert: ' . $db->error);
    }
    $stmt->bind_param(
        'iiissds',
        $siteId, $ownerUserId, $deptId, $claimTitle, $claimDate, $totalAmount, $status
    );
    if ($stmt->execute() === false) {
        throw new \RuntimeException('Failed to insert claim: ' . $stmt->error);
    }
    $claimId = (int) $stmt->insert_id;
    $stmt->close();

    $itemStmt = $db->prepare(
        'INSERT INTO tblExpenseClaimItems '
        . '(claimID, itemName, description, unitCost, quantity, lineTotal, purchaseDate, supplier) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($itemStmt === false) {
        throw new \RuntimeException('Failed to prepare item insert: ' . $db->error);
    }
    foreach ($items as $it) {
        $itemStmt->bind_param(
            'issdidss',
            $claimId, $it['itemName'], $it['description'], $it['unitCost'],
            $it['quantity'], $it['lineTotal'], $it['purchaseDate'], $it['supplier']
        );
        if ($itemStmt->execute() === false) {
            throw new \RuntimeException('Failed to insert item: ' . $itemStmt->error);
        }
    }
    $itemStmt->close();

    App::commit();
} catch (\Throwable $ex) {
    App::rollback();
    Logger::errorPlatform('MySQL', 'Error', 'API_EXP_CREATE_FAIL', $ex->getMessage(), '');
    ApiResponse::error('Database error', 500);
}

Logger::audit('tblExpenseClaims', $claimId, 'create', null, [
    'siteID'      => $siteId,
    'userID'      => $ownerUserId,
    'deptID'      => $deptId,
    'claimTitle'  => $claimTitle,
    'claimDate'   => $claimDate,
    'totalAmount' => $totalAmount,
    'status'      => $status,
    'itemCount'   => count($items),
]);
Logger::activity('ApiExpenseCreate', 'API: submitted expense claim #' . $claimId . ' (£' . number_format($totalAmount, 2) . ')');

ApiResponse::success(['claimID' => $claimId], 201);
