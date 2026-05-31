<?php
// Path: public_html/rota/slot-save.php
/**
 * Rota — slot save / delete handler (POST-only).
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
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    if (isset($_POST['delete']) === true) {
        $id = (int) $_POST['delete'];
        $stmt = $db->prepare('DELETE FROM tblRotaSlot WHERE slotID = ? AND siteID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('ii', $id, $siteId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $roleTypeID  = (int) ($_POST['roleTypeID'] ?? 0);
        $slotDate    = (string) ($_POST['slotDate'] ?? '');
        $startTime   = trim((string) ($_POST['startTime'] ?? ''));
        $endTime     = trim((string) ($_POST['endTime']   ?? ''));
        $assignedToID = (int) ($_POST['assignedToID'] ?? 0);
        if ($roleTypeID <= 0 || $slotDate === '' || strtotime($slotDate) === false) {
            throw new \RuntimeException('Invalid input');
        }
        $st = $startTime !== '' ? $startTime : null;
        $en = $endTime   !== '' ? $endTime   : null;
        $a  = $assignedToID > 0 ? $assignedToID : null;
        $stmt = $db->prepare(
            'INSERT INTO tblRotaSlot (siteID, roleTypeID, slotDate, startTime, endTime, assignedToID, createdByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iisssii', $siteId, $roleTypeID, $slotDate, $st, $en, $a, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
} catch (\Throwable $e) {
    // 🛡️ Silent — admin sees the result on the redirect-target page.
    \Portal\Core\Logger::errorPlatform('Rota', 'Warning', 'SLOT_SAVE', $e->getMessage(), '');
}

header('Location: /rota/manage');
exit();
