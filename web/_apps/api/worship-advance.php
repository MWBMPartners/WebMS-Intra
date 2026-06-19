<?php
// Path: _apps/api/worship-advance.php
/**
 * -----------------------------------------------------------------------------
 * Worship — Operator command POST handler (#308 Phase 2)
 * -----------------------------------------------------------------------------
 * Login-required. Admin OR coordinator of the plan's event. Actions:
 *   next   — advance to next item by sortOrder
 *   prev   — back one item
 *   goto   — jump to a specific itemID
 *   blank  — toggle isBlank ON, isBlack OFF
 *   black  — toggle isBlack ON, isBlank OFF
 *   show   — clear both isBlank and isBlack
 *
 * Returns JSON identical in shape to /api/worship/state so the operator
 * console can refresh immediately without a follow-up poll.
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
$action = (string) ($_POST['action'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

if (in_array($action, ['next', 'prev', 'goto', 'blank', 'black', 'show'], true) === false) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'action']); exit();
}

$stmt = $mysqli->prepare('SELECT planID, eventID FROM tblServicePlans WHERE planID = ? AND siteID = ? AND isActive = 1');
$stmt->bind_param('ii', $planId, $siteId);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($plan === null) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'plan']); exit(); }

$canOperate = App::isAdmin() === true
           || ((int) ($plan['eventID'] ?? 0) > 0 && Auth::isCoordinatorOf((int) $plan['eventID']));
if ($canOperate === false) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit();
}

// 📋 Current state (NULL row OK — defaults).
$stmt = $mysqli->prepare('SELECT currentItemID, isBlank, isBlack FROM tblServicePlanState WHERE planID = ?');
$stmt->bind_param('i', $planId);
$stmt->execute();
$state = $stmt->get_result()->fetch_assoc() ?: ['currentItemID' => null, 'isBlank' => 0, 'isBlack' => 0];
$stmt->close();

$newItemId = $state['currentItemID'] !== null ? (int) $state['currentItemID'] : null;
$newBlank  = (int) $state['isBlank'];
$newBlack  = (int) $state['isBlack'];

if ($action === 'next' || $action === 'prev') {
    // 🧮 Find target by sortOrder relative to current item.
    if ($newItemId === null) {
        // No state yet — pick first or last depending on direction.
        $sql = 'SELECT itemID FROM tblServicePlanItems WHERE planID = ? ORDER BY sortOrder, itemID '
             . ($action === 'next' ? 'ASC' : 'DESC') . ' LIMIT 1';
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $planId);
    } else {
        $stmt = $mysqli->prepare('SELECT sortOrder FROM tblServicePlanItems WHERE itemID = ? AND planID = ?');
        $stmt->bind_param('ii', $newItemId, $planId);
        $stmt->execute();
        $cur = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $currentOrder = (int) ($cur['sortOrder'] ?? 0);
        $cmp = $action === 'next' ? '>' : '<';
        $dir = $action === 'next' ? 'ASC' : 'DESC';
        $stmt = $mysqli->prepare(
            "SELECT itemID FROM tblServicePlanItems WHERE planID = ? AND sortOrder $cmp ? ORDER BY sortOrder $dir LIMIT 1"
        );
        $stmt->bind_param('ii', $planId, $currentOrder);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row !== null) {
        $newItemId = (int) $row['itemID'];
    }
    // Always clear blank/black on advance/back.
    $newBlank = 0; $newBlack = 0;
} elseif ($action === 'goto') {
    $target = (int) ($_POST['itemID'] ?? 0);
    if ($target <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'itemID']); exit(); }
    // 🛡️ Confirm item belongs to this plan.
    $stmt = $mysqli->prepare('SELECT itemID FROM tblServicePlanItems WHERE itemID = ? AND planID = ?');
    $stmt->bind_param('ii', $target, $planId);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($ok === false) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'item']); exit(); }
    $newItemId = $target;
    $newBlank = 0; $newBlack = 0;
} elseif ($action === 'blank') {
    $newBlank = 1; $newBlack = 0;
} elseif ($action === 'black') {
    $newBlack = 1; $newBlank = 0;
} elseif ($action === 'show') {
    $newBlank = 0; $newBlack = 0;
}

// 💾 Upsert state.
$stmt = $mysqli->prepare(
    'INSERT INTO tblServicePlanState (planID, currentItemID, isBlank, isBlack, updatedByID) '
    . 'VALUES (?, ?, ?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE currentItemID = VALUES(currentItemID), isBlank = VALUES(isBlank), '
    . '                       isBlack = VALUES(isBlack), updatedByID = VALUES(updatedByID)'
);
$stmt->bind_param('iiiii', $planId, $newItemId, $newBlank, $newBlack, $userId);
$stmt->execute();
$stmt->close();

Logger::activity('ServicePlanAdvanced', 'Plan #' . $planId . ' ' . $action . ' → item=' . ($newItemId ?? 'null') . ' blank=' . $newBlank . ' black=' . $newBlack);

// 📤 Return current state in the same shape as /api/worship/state so the
//    operator console can refresh without a follow-up poll.
$response = [
    'ok'            => true,
    'currentItemID' => $newItemId,
    'isBlank'       => (bool) $newBlank,
    'isBlack'       => (bool) $newBlack,
    'body'          => '',
    'slideTitle'    => '',
    'itemType'      => '',
];

if ($newItemId !== null) {
    $stmt = $mysqli->prepare(
        'SELECT i.itemType, i.slideTitle, i.slideBody, s.title AS songTitle, s.lyrics AS songLyrics '
        . 'FROM tblServicePlanItems i LEFT JOIN tblSongs s ON s.songID = i.songID '
        . 'WHERE i.itemID = ?'
    );
    $stmt->bind_param('i', $newItemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($item !== null) {
        $response['itemType']   = (string) $item['itemType'];
        $response['slideTitle'] = (string) ($item['itemType'] === 'song' ? ($item['songTitle'] ?? '') : ($item['slideTitle'] ?? ''));
        $response['body']       = (string) ($item['itemType'] === 'song' ? ($item['songLyrics'] ?? '') : ($item['slideBody'] ?? ''));
    }
}

echo json_encode($response);
