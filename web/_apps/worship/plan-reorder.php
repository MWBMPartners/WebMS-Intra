<?php
// Path: _apps/worship/plan-reorder.php
/**
 * -----------------------------------------------------------------------------
 * Worship — SortableJS drag-reorder POST (#308 Phase 3)
 * -----------------------------------------------------------------------------
 * Receives an array of itemIDs in the new desired order. Re-sequences
 * sortOrder atomically. Admin OR coordinator gated; CSRF required.
 *
 * Request body (form-encoded): csrf_token, planID, items[]=ID&items[]=ID...
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit();
}

Auth::ensureSession();
if (Auth::check() === false) {
    http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit();
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'csrf']); exit();
}

$planId = (int) ($_POST['planID'] ?? 0);
$items  = (array) ($_POST['items'] ?? []);
$siteId = Site::id();

if ($planId <= 0 || count($items) === 0) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'input']); exit();
}

$stmt = $mysqli->prepare('SELECT planID, eventID FROM tblServicePlans WHERE planID = ? AND siteID = ? AND isActive = 1');
$stmt->bind_param('ii', $planId, $siteId);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($plan === null) {
    http_response_code(404); echo json_encode(['ok' => false, 'error' => 'plan']); exit();
}

$canWrite = App::isAdmin() === true
         || ((int) ($plan['eventID'] ?? 0) > 0 && Auth::isCoordinatorOf((int) $plan['eventID']));
if ($canWrite === false) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit();
}

// 🛡️ Sanitise the itemID list — must all be integers AND must all belong to
//    this plan. Reject the whole request if any ID is foreign.
$idList = [];
foreach ($items as $raw) {
    $id = (int) $raw;
    if ($id <= 0) { continue; }
    $idList[] = $id;
}
if (count($idList) === 0) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'empty']); exit();
}

// 🔍 Verify ALL submitted IDs belong to this plan. Refuse partial matches.
$placeholders = implode(',', array_fill(0, count($idList), '?'));
$types        = str_repeat('i', count($idList));
$sql = 'SELECT COUNT(*) AS c FROM tblServicePlanItems '
     . 'WHERE planID = ? AND itemID IN (' . $placeholders . ')';
$stmt = $mysqli->prepare($sql);
$bind = array_merge([$planId], $idList);
$stmt->bind_param('i' . $types, ...$bind);
$stmt->execute();
$match = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
if ($match !== count($idList)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'mismatch']); exit();
}

// 💾 Rewrite sortOrder. Single prepared statement reused across N updates.
$update = $mysqli->prepare('UPDATE tblServicePlanItems SET sortOrder = ? WHERE itemID = ? AND planID = ?');
foreach ($idList as $pos => $id) {
    $sortPos = $pos + 1;
    $update->bind_param('iii', $sortPos, $id, $planId);
    $update->execute();
}
$update->close();

Logger::activity('ServicePlanReordered', 'Plan #' . $planId . ' new order: ' . implode(',', $idList));

echo json_encode(['ok' => true, 'count' => count($idList)]);
