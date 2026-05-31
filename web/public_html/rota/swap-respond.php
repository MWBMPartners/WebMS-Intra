<?php
// Path: public_html/rota/swap-respond.php
/**
 * Rota — Accept or decline a swap request.
 *
 * @package   Portal\Rota
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/256
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$swapId = (int) ($_POST['swapID'] ?? 0);
$action = (string) ($_POST['action'] ?? 'decline');

// Load swap — confirm it's targeted at me OR open
$swap = null;
$stmt = $db->prepare(
    'SELECT w.swapID, w.slotID, w.targetUserID, w.status, s.assignedToID '
    . 'FROM tblRotaSwapRequest w JOIN tblRotaSlot s ON s.slotID = w.slotID '
    . 'WHERE w.swapID = ? AND s.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $swapId, $siteId);
    $stmt->execute();
    $swap = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($swap === null || $swap['status'] !== 'pending') {
    header('Location: /rota');
    exit();
}
$isTarget = (int) ($swap['targetUserID'] ?? 0) === $userId
         || (int) ($swap['targetUserID'] ?? 0) === 0; // open swap
if ($isTarget === false) {
    http_response_code(403);
    exit('Not authorised.');
}

$response = trim((string) ($_POST['responseMessage'] ?? ''));

try {
    $db->begin_transaction();
    if ($action === 'accept') {
        // Reassign the slot to me and mark swap accepted.
        $stmt = $db->prepare('UPDATE tblRotaSlot SET assignedToID = ? WHERE slotID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('ii', $userId, $swap['slotID']);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $db->prepare(
            "UPDATE tblRotaSwapRequest SET status = 'accepted', responseMessage = ?, respondedAt = NOW() WHERE swapID = ?"
        );
        if ($stmt !== false) {
            $stmt->bind_param('si', $response, $swapId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $db->prepare(
            "UPDATE tblRotaSwapRequest SET status = 'declined', responseMessage = ?, respondedAt = NOW() WHERE swapID = ?"
        );
        if ($stmt !== false) {
            $stmt->bind_param('si', $response, $swapId);
            $stmt->execute();
            $stmt->close();
        }
    }
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    \Portal\Core\Logger::errorPlatform('Rota', 'Warning', 'SWAP_RESPOND', $e->getMessage(), '');
}

header('Location: /rota');
exit();
