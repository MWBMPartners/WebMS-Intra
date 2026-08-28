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
use Portal\Core\Hymnal;
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
$userId = (int) ($_SESSION['user_id'] ?? 0);

// -----------------------------------------------------------------------
// 🎵 Gap #128 residual — resolve an optional canonical song link for this
// item. Two POST shapes, mutually exclusive:
//   pickTitle (+ pickAuthor/pickCcli/pickCopyright/pickHymnalCode/
//   pickHymnalNumber/pickTune) — the hymn picker's JS filled these when the
//   operator chose a hymnal-index or remote-provider result that ISN'T yet
//   a canonical tblSongs row. Auto-promotes it (default: yes, per plan)
//   via Hymnal::promoteToSong() and links the returned songID.
//   songID — the operator chose an ALREADY-canonical song-library result
//   (or this is re-saving an item that already carries a link). Re-
//   validated against THIS site's tblSongs before use — a crafted
//   cross-site songID is silently dropped to NULL, never an error, mirror
//   of the presenterID site-scope validation just below.
// Both paths degrade to NULL on any problem — free-text `title` remains
// the universal, always-working fallback (unchanged from before #128).
// -----------------------------------------------------------------------
$resolveSongId = static function () use ($db, $siteId, $userId): ?int {
    $pickTitle = trim((string) ($_POST['pickTitle'] ?? ''));
    if ($pickTitle !== '') {
        $entry = [
            'title'         => $pickTitle,
            'author'        => (string) ($_POST['pickAuthor'] ?? ''),
            'ccliNumber'    => (string) ($_POST['pickCcli'] ?? ''),
            'copyrightLine' => (string) ($_POST['pickCopyright'] ?? ''),
            'hymnalCode'    => (string) ($_POST['pickHymnalCode'] ?? ''),
            'hymnNumber'    => (string) ($_POST['pickHymnalNumber'] ?? ''),
            'tuneName'      => (string) ($_POST['pickTune'] ?? ''),
        ];
        $newId = Hymnal::promoteToSong($siteId, $entry, $userId > 0 ? $userId : null);
        return $newId > 0 ? $newId : null;
    }

    $songId = (int) ($_POST['songID'] ?? 0);
    if ($songId <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT 1 FROM tblSongs WHERE songID = ? AND siteID = ? LIMIT 1');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('ii', $songId, $siteId);
    $stmt->execute();
    $valid = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $valid === true ? $songId : null;
};

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
        $nextStmt = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 AS next FROM tblServicePlanItem WHERE planID = ?');
        if ($nextStmt !== false) {
            $nextStmt->bind_param('i', $planId);
            $nextStmt->execute();
            $row = $nextStmt->get_result()->fetch_assoc();
            $next = (int) ($row['next'] ?? 1);
            $nextStmt->close();
        }
        $songId = $resolveSongId();
        $stmt = $db->prepare(
            'INSERT INTO tblServicePlanItem (planID, sectionType, position, title, songID) VALUES (?, ?, ?, ?, ?)'
        );
        if ($stmt !== false) {
            $titleVal = $title !== '' ? $title : null;
            $stmt->bind_param('isisi', $planId, $sectionType, $next, $titleVal, $songId);
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
        // 🛡️ Validate presenterID against the same site-scoped set the
        // edit-page dropdown is built from (security review) — without
        // this, a crafted POST could attribute a section to ANY userID on
        // ANY tenant, not just users active on this site.
        if ($presenterID > 0) {
            $presCheck = $db->prepare(
                'SELECT 1 FROM tblUserSites WHERE userID = ? AND siteID = ? AND isActive = 1 LIMIT 1'
            );
            if ($presCheck !== false) {
                $presCheck->bind_param('ii', $presenterID, $siteId);
                $presCheck->execute();
                $presValid = $presCheck->get_result()->fetch_row() !== null;
                $presCheck->close();
                if ($presValid === false) {
                    $presenterID = 0;
                }
            } else {
                $presenterID = 0;
            }
        }
        $pID    = $presenterID > 0 ? $presenterID : null;
        $pT     = $presenterTxt !== '' ? $presenterTxt : null;
        $tt     = $title !== '' ? $title : null;
        $dur    = $duration > 0 ? $duration : null;
        $nt     = $notes !== '' ? $notes : null;
        $songId = $resolveSongId();
        $stmt = $db->prepare(
            'UPDATE tblServicePlanItem SET sectionType = ?, title = ?, songID = ?, presenterID = ?, '
            . 'presenterText = ?, durationMin = ?, notes = ? WHERE itemID = ? AND planID = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ssiisisii', $sectionType, $tt, $songId, $pID, $pT, $dur, $nt, $itemId, $planId);
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
