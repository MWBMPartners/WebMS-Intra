<?php
// Path: _apps/api/worship-state.php
/**
 * -----------------------------------------------------------------------------
 * Worship — Public state poll endpoint (#308 Phase 2)
 * -----------------------------------------------------------------------------
 * Returns the current state JSON for a plan, gated by displayToken (not
 * login). Called every 500ms by /worship/display and every ~1.5s by the
 * operator console as a safety net for parallel operators.
 *
 * Response shape:
 *   { ok: true, body: "...", itemType: "song"|"text"|"verse", isBlank: bool,
 *     isBlack: bool, slideTitle: "...", currentItemID: int|null }
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Site;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$token = trim((string) ($_GET['t'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid-token']);
    exit();
}

$siteId = Site::id();

// 📋 Plan + state in a single query so the poll is cheap.
$stmt = $mysqli->prepare(
    'SELECT p.planID, s.currentItemID, COALESCE(s.isBlank, 0) AS isBlank, COALESCE(s.isBlack, 0) AS isBlack '
    . 'FROM tblServicePlans p '
    . 'LEFT JOIN tblServicePlanState s ON s.planID = p.planID '
    . 'WHERE p.displayToken = ? AND p.siteID = ? AND p.isActive = 1'
);
$stmt->bind_param('si', $token, $siteId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($row === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'plan-not-found']);
    exit();
}

$response = [
    'ok'            => true,
    'currentItemID' => $row['currentItemID'] !== null ? (int) $row['currentItemID'] : null,
    'isBlank'       => (int) $row['isBlank'] === 1,
    'isBlack'       => (int) $row['isBlack'] === 1,
    'body'          => '',
    'slideTitle'    => '',
    'itemType'      => '',
];

if ($row['currentItemID'] !== null) {
    $itemId = (int) $row['currentItemID'];
    $stmt = $mysqli->prepare(
        'SELECT i.itemID, i.itemType, i.slideTitle, i.slideBody, s.title AS songTitle, s.lyrics AS songLyrics '
        . 'FROM tblServicePlanItems i LEFT JOIN tblSongs s ON s.songID = i.songID '
        . 'WHERE i.itemID = ?'
    );
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($item !== null) {
        $response['itemType']   = (string) $item['itemType'];
        $response['slideTitle'] = (string) (
            $item['itemType'] === 'song' ? ($item['songTitle'] ?? '') : ($item['slideTitle'] ?? '')
        );
        $response['body'] = (string) (
            $item['itemType'] === 'song'
                ? ($item['songLyrics'] ?? '')
                : ($item['slideBody'] ?? '')
        );
    }
}

echo json_encode($response);
