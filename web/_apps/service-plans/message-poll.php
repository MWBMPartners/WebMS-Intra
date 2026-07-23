<?php
// Path: _apps/service-plans/message-poll.php
/**
 * -----------------------------------------------------------------------------
 * Service Plans — Confidence-Monitor Message Poll (#300 v2) 💬
 * -----------------------------------------------------------------------------
 * JSON GET endpoint polled by confidence.php every 4s. Returns the latest
 * active (isCleared = 0) message for a plan, short-circuiting to
 * `changed: false` when the client already has it (sinceID dedup).
 *
 * A plain `service-plans/*` page route (isProtected=1) — NOT under `api/*`,
 * so it is never touched by ApiRouter and needs no `api.*.enabled` seed
 * (Router only intercepts paths starting `api/`). Gated the same as
 * confidence.php: any logged-in user, no admin requirement, no kiosk token
 * exists in v1.
 *
 * Same-origin + authenticated — deliberately NO
 * `Access-Control-Allow-Origin` header (unlike livechat's public-CORS poll).
 *
 * @package   Portal\ServicePlans
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/300
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\Auth;
use Portal\Core\Site;

header('Cache-Control: no-store');

// 🚫 GET only.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('GET required', 405);
}

// 🔐 Belt-and-braces auth check — Router's isProtected redirect fires first
// in practice, but this guards any future include path and returns JSON,
// not an HTML redirect, if it were ever reached unauthenticated.
Auth::ensureSession();
if (Auth::check() === false) {
    ApiResponse::error('Login required', 401);
}

$siteId   = Site::id();
$planId   = (int) ($_GET['id'] ?? 0);
$sinceId  = (int) ($_GET['sinceID'] ?? 0);

if ($planId <= 0) {
    ApiResponse::error('id required', 400);
}

// 🔎 Single indexed prepared query — a plan at another site simply yields
// zero rows; nothing leaks. No separate plan-existence pre-query needed.
$row  = null;
$stmt = $mysqli->prepare(
    'SELECT messageID, body, createdAt FROM tblServicePlanMessages '
    . 'WHERE planID = ? AND siteID = ? AND isCleared = 0 '
    . 'ORDER BY messageID DESC LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $planId, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$latestId = $row !== null ? (int) $row['messageID'] : 0;

if ($latestId === $sinceId) {
    // 🟰 Client already has the latest state — skip re-sending the payload.
    ApiResponse::success([
        'changed' => false,
        'lastID'  => $latestId,
    ]);
}

ApiResponse::success([
    'changed' => true,
    'lastID'  => $latestId,
    'message' => $row === null ? null : [
        'messageID' => (int) $row['messageID'],
        'body'      => (string) $row['body'],
        'createdAt' => (string) $row['createdAt'],
    ],
]);
