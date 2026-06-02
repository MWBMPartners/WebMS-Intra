<?php
// Path: public_html/service-plans/item-save.php
/**
 * Service Plans — POST handler for item create / update / delete / reorder.
 *
 * @package   Portal\ServicePlans
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/262
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
$planId = (int) ($_POST['planID'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

// Confirm plan ownership / site scope.
$stmt = $db->prepare('SELECT 1 FROM tblServicePlan WHERE planID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $planId, $siteId);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if ($ok === false) {
        http_response_code(404);
        exit('Plan not found');
    }
}

$validSections = ['greeting','song','prayer','scripture','sermon','offering','communion','special_music','announcement','reading','other'];

try {
    if ($action === 'create') {
        $sectionType = (string) ($_POST['sectionType'] ?? 'other');
        $title       = trim((string) ($_POST['title'] ?? ''));
        if (in_array($sectionType, $validSections, true) === false) {
            $sectionType = 'other';
        }
        // Find next position.
        $next = 1;
        $rs = $db->query('SELECT COALESCE(MAX(position), 0) + 1 AS next FROM tblServicePlanItem WHERE planID = ' . $planId);
        if ($rs !== false) {
            $next = (int) $rs->fetch_assoc()['next'];
            $rs->free();
        }
        $stmt = $db->prepare(
            'INSERT INTO tblServicePlanItem (planID, sectionType, position, title) VALUES (?, ?, ?, ?)'
        );
        if ($stmt !== false) {
            $titleVal = $title !== '' ? $title : null;
            $stmt->bind_param('isis', $planId, $sectionType, $next, $titleVal);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($action === 'update') {
        $itemId       = (int) ($_POST['itemID'] ?? 0);
        $sectionType  = (string) ($_POST['sectionType'] ?? 'other');
        $title        = trim((string) ($_POST['title'] ?? ''));
        $presenterID  = (int) ($_POST['presenterID'] ?? 0);
        $presenterTxt = trim((string) ($_POST['presenterText'] ?? ''));
        $duration     = (int) ($_POST['durationMin'] ?? 0);
        $notes        = trim((string) ($_POST['notes'] ?? ''));
        if (in_array($sectionType, $validSections, true) === false) {
            $sectionType = 'other';
        }
        $pID = $presenterID > 0 ? $presenterID : null;
        $pT  = $presenterTxt !== '' ? $presenterTxt : null;
        $tt  = $title !== '' ? $title : null;
        $dur = $duration > 0 ? $duration : null;
        $nt  = $notes !== '' ? $notes : null;
        $stmt = $db->prepare(
            'UPDATE tblServicePlanItem SET sectionType = ?, title = ?, presenterID = ?, '
            . 'presenterText = ?, durationMin = ?, notes = ? WHERE itemID = ? AND planID = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ssisisii', $sectionType, $tt, $pID, $pT, $dur, $nt, $itemId, $planId);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $itemId = (int) ($_POST['itemID'] ?? 0);
        $stmt = $db->prepare('DELETE FROM tblServicePlanItem WHERE itemID = ? AND planID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('ii', $itemId, $planId);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($action === 'move-up' || $action === 'move-down') {
        // Swap positions with the neighbour.
        $itemId = (int) ($_POST['itemID'] ?? 0);
        $stmt = $db->prepare('SELECT itemID, position FROM tblServicePlanItem WHERE planID = ? ORDER BY position, itemID');
        if ($stmt !== false) {
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $rs = $stmt->get_result();
            $rows = [];
            while ($r = $rs->fetch_assoc()) {
                $rows[] = $r;
            }
            $stmt->close();
            $idx = -1;
            foreach ($rows as $i => $r) {
                if ((int) $r['itemID'] === $itemId) {
                    $idx = $i;
                    break;
                }
            }
            $swapWith = $action === 'move-up' ? $idx - 1 : $idx + 1;
            if ($idx >= 0 && isset($rows[$swapWith])) {
                $a = $rows[$idx];
                $b = $rows[$swapWith];
                $stmt = $db->prepare('UPDATE tblServicePlanItem SET position = ? WHERE itemID = ?');
                if ($stmt !== false) {
                    $stmt->bind_param('ii', $b['position'], $a['itemID']);
                    $stmt->execute();
                    $stmt->bind_param('ii', $a['position'], $b['itemID']);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('ServicePlans', 'Warning', 'ITEM_SAVE', $e->getMessage(), '');
}

header('Location: /service-plans/edit?id=' . $planId);
exit();
