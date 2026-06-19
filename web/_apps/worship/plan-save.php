<?php
// Path: _apps/worship/plan-save.php
/**
 * -----------------------------------------------------------------------------
 * Worship — Service Plan POST handler 🎶 (#308 Phase 1)
 * -----------------------------------------------------------------------------
 * Single handler for every plan-mutating action. Actions:
 *   save-metadata  — plan-level name / notes / event binding / archive
 *   add-song       — append a song-slide item (songID required)
 *   add-text       — append a text-slide item (slideBody required)
 *   add-verse      — append a verse-slide item (slideTitle ref + body required)
 *   remove-item    — delete one item
 *   move-up        — swap sortOrder with the previous item
 *   move-down      — swap sortOrder with the next item
 *
 * Write ACL: admin OR Auth::isCoordinatorOf(plan.eventID). Coordinator
 * grants don't extend to free-floating template plans (eventID NULL).
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /worship/plans', true, 302); exit();
}

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); exit('Bad request');
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$planId = (int) ($_POST['planID'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

// 📋 Load plan (or accept planID=0 only for save-metadata of a new plan).
$plan = null;
if ($planId > 0) {
    $stmt = $mysqli->prepare('SELECT planID, eventID FROM tblServicePlans WHERE planID = ? AND siteID = ?');
    $stmt->bind_param('ii', $planId, $siteId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($plan === null) { http_response_code(404); exit('Plan not found'); }
}

/**
 * 🛡️ Check write ACL for the plan. Admins always pass. Coordinators of
 * the plan's bound event pass for that one plan. Free-floating template
 * plans (eventID NULL) are admin-only.
 */
$gate = static function (?array $plan): bool {
    if (App::isAdmin() === true) { return true; }
    if ($plan === null) { return false; }
    if ($plan['eventID'] === null) { return false; }
    return Auth::isCoordinatorOf((int) $plan['eventID']);
};

// =============================================================================
// save-metadata
// =============================================================================
if ($action === 'save-metadata') {
    $name     = mb_substr(trim((string) ($_POST['name']  ?? '')), 0, 120);
    $notes    = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000);
    $eventId  = (int) ($_POST['eventID'] ?? 0);
    $isActive = (int) ($_POST['isActive'] ?? 0) === 1 ? 1 : 0;

    if ($name === '') {
        $_SESSION['flash_msg']  = 'Name is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /worship/plan' . ($planId > 0 ? '?id=' . $planId : '?new=1'), true, 302);
        exit();
    }

    // 🛡️ If an event is named, verify it belongs to this site AND the actor
    //    has authority over that event (admin or its coordinator). This stops
    //    a non-admin user from binding a plan to an event they don't run.
    $eventArg = null;
    if ($eventId > 0) {
        $stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
        $stmt->bind_param('ii', $eventId, $siteId);
        $stmt->execute();
        $ok = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($ok === false) { http_response_code(404); exit('Event not found'); }
        if (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false) {
            http_response_code(403); exit('Forbidden — you do not coordinate that event');
        }
        $eventArg = $eventId;
    }

    if ($plan === null) {
        // 🆕 Create. Non-admins MUST bind to an event they coordinate; admins
        //    can create free-floating templates.
        if (App::isAdmin() === false && $eventArg === null) {
            http_response_code(403); exit('Forbidden — non-admins must bind the plan to an event they coordinate');
        }
        $stmt = $mysqli->prepare(
            'INSERT INTO tblServicePlans (siteID, eventID, name, notes, isActive, createdByID) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $notesArg = $notes !== '' ? $notes : null;
        $stmt->bind_param('iissii', $siteId, $eventArg, $name, $notesArg, $isActive, $userId);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        Logger::activity('ServicePlanCreated', 'Plan #' . $newId . ' "' . $name . '"');
        header('Location: /worship/plan?id=' . $newId, true, 302); exit();
    }

    // ✏️ Update existing.
    if ($gate($plan) === false) {
        http_response_code(403); exit('Forbidden');
    }
    $stmt = $mysqli->prepare(
        'UPDATE tblServicePlans SET name = ?, notes = ?, eventID = ?, isActive = ? WHERE planID = ? AND siteID = ?'
    );
    $notesArg = $notes !== '' ? $notes : null;
    $stmt->bind_param('ssiiii', $name, $notesArg, $eventArg, $isActive, $planId, $siteId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('ServicePlanUpdated', 'Plan #' . $planId);
    header('Location: /worship/plan?id=' . $planId, true, 302); exit();
}

// =============================================================================
// item-mutating actions — all require an existing plan + write gate
// =============================================================================
if ($plan === null) { http_response_code(400); exit('Plan required'); }
if ($gate($plan) === false) { http_response_code(403); exit('Forbidden'); }

$redirect = '/worship/plan?id=' . $planId;

if ($action === 'add-song') {
    $songId = (int) ($_POST['songID'] ?? 0);
    if ($songId <= 0) { header('Location: ' . $redirect, true, 302); exit(); }
    // 🛡️ Confirm song belongs to this site.
    $stmt = $mysqli->prepare('SELECT songID FROM tblSongs WHERE songID = ? AND siteID = ? AND isActive = 1');
    $stmt->bind_param('ii', $songId, $siteId);
    $stmt->execute();
    $ok = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($ok === false) { http_response_code(404); exit('Song not found'); }
    $stmt = $mysqli->prepare(
        'INSERT INTO tblServicePlanItems (planID, sortOrder, itemType, songID) '
        . 'VALUES (?, (SELECT COALESCE(MAX(sortOrder), 0) + 1 FROM (SELECT * FROM tblServicePlanItems) x WHERE x.planID = ?), "song", ?)'
    );
    $stmt->bind_param('iii', $planId, $planId, $songId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('ServicePlanItemAdded', 'Plan #' . $planId . ' song #' . $songId);
} elseif ($action === 'add-text' || $action === 'add-verse') {
    $type    = $action === 'add-verse' ? 'verse' : 'text';
    $title   = mb_substr(trim((string) ($_POST['slideTitle'] ?? '')), 0, 255);
    $body    = trim((string) ($_POST['slideBody'] ?? ''));
    if (mb_strlen($body) > 50000) { $body = mb_substr($body, 0, 50000); }
    if ($body === '' || ($type === 'verse' && $title === '')) {
        $_SESSION['flash_msg']  = 'Body required' . ($type === 'verse' ? ' and verse reference required' : '') . '.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirect, true, 302); exit();
    }
    $titleArg = $title !== '' ? $title : null;
    $stmt = $mysqli->prepare(
        'INSERT INTO tblServicePlanItems (planID, sortOrder, itemType, slideTitle, slideBody) '
        . 'VALUES (?, (SELECT COALESCE(MAX(sortOrder), 0) + 1 FROM (SELECT * FROM tblServicePlanItems) x WHERE x.planID = ?), ?, ?, ?)'
    );
    $stmt->bind_param('iisss', $planId, $planId, $type, $titleArg, $body);
    $stmt->execute();
    $stmt->close();
    Logger::activity('ServicePlanItemAdded', 'Plan #' . $planId . ' ' . $type . ' "' . $title . '"');
} elseif ($action === 'remove-item') {
    $itemId = (int) ($_POST['itemID'] ?? 0);
    if ($itemId <= 0) { header('Location: ' . $redirect, true, 302); exit(); }
    $stmt = $mysqli->prepare('DELETE FROM tblServicePlanItems WHERE itemID = ? AND planID = ?');
    $stmt->bind_param('ii', $itemId, $planId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('ServicePlanItemRemoved', 'Plan #' . $planId . ' item #' . $itemId);
} elseif ($action === 'move-up' || $action === 'move-down') {
    $itemId = (int) ($_POST['itemID'] ?? 0);
    if ($itemId <= 0) { header('Location: ' . $redirect, true, 302); exit(); }

    // 📋 Get current sortOrder of the target item.
    $stmt = $mysqli->prepare('SELECT sortOrder FROM tblServicePlanItems WHERE itemID = ? AND planID = ?');
    $stmt->bind_param('ii', $itemId, $planId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($row === null) { header('Location: ' . $redirect, true, 302); exit(); }
    $currentOrder = (int) $row['sortOrder'];

    // 🔄 Find the neighbour to swap with.
    $cmp = $action === 'move-up' ? '<' : '>';
    $dir = $action === 'move-up' ? 'DESC' : 'ASC';
    $stmt = $mysqli->prepare(
        'SELECT itemID, sortOrder FROM tblServicePlanItems '
        . "WHERE planID = ? AND sortOrder $cmp ? "
        . "ORDER BY sortOrder $dir LIMIT 1"
    );
    $stmt->bind_param('ii', $planId, $currentOrder);
    $stmt->execute();
    $neighbour = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($neighbour !== null) {
        $neighbourId    = (int) $neighbour['itemID'];
        $neighbourOrder = (int) $neighbour['sortOrder'];
        // Two UPDATEs in a tiny transaction-like sequence; not atomic but
        // race-window is one render. Adequate for v1.
        $stmt = $mysqli->prepare('UPDATE tblServicePlanItems SET sortOrder = ? WHERE itemID = ? AND planID = ?');
        $stmt->bind_param('iii', $neighbourOrder, $itemId, $planId);
        $stmt->execute();
        $stmt->close();
        $stmt = $mysqli->prepare('UPDATE tblServicePlanItems SET sortOrder = ? WHERE itemID = ? AND planID = ?');
        $stmt->bind_param('iii', $currentOrder, $neighbourId, $planId);
        $stmt->execute();
        $stmt->close();
    }
}

header('Location: ' . $redirect, true, 302);
exit();
