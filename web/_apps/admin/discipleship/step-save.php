<?php
// Path: _apps/admin/discipleship/step-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship Pathway step save (create + update) POST 📖 (#303 Phase 1, Phase 2)
 * -----------------------------------------------------------------------------
 * Single handler for both:
 *   • stepID === 0  → INSERT new step against pathwayID
 *   • stepID > 0    → UPDATE existing step (verified via pathwayID join)
 *
 * The parent pathway is re-verified against siteID on EVERY save so a forged
 * pathwayID can't write into another site's pathway list.
 *
 * Phase 2 adds `autoRule` + `autoRefID` validation: a rule of 'none' forces
 * `autoRefID` to NULL; any other rule requires its ref (an eventID for
 * attended_event/rsvpd_event, a categoryID for attended_category) to
 * resolve to an existing row AT THIS SITE via a prepared SELECT — an
 * unresolved ref rejects the whole save with a flash rather than silently
 * storing a dangling reference.
 *
 * @package   Portal\App\Admin\Discipleship
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/303
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Settings;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/discipleship/pathways', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

if (Auth::verifyCsrf((string) ($_POST['csrf_token'] ?? '')) === false) {
    http_response_code(400);
    exit('Bad request');
}

$enabled = (string) Settings::get('discipleship.enabled', 'false');
if ($enabled !== '1' && $enabled !== 'true') {
    $_SESSION['flash_msg']  = 'Discipleship app is disabled.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/discipleship/pathways', true, 302);
    exit();
}

$db        = App::db();
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$pathwayId = (int) ($_POST['pathwayID'] ?? 0);
$stepId    = (int) ($_POST['stepID'] ?? 0);
$sortOrder = (int) ($_POST['sortOrder'] ?? 0);
$name      = trim((string) ($_POST['name'] ?? ''));
$desc      = trim((string) ($_POST['description'] ?? ''));
$hint      = trim((string) ($_POST['completionHint'] ?? ''));
$optional  = isset($_POST['isOptional']) === true ? 1 : 0;

// 🤖 Auto-completion rule (#303 Phase 2).
$autoRule = (string) ($_POST['autoRule'] ?? 'none');
$validRules = ['none', 'attended_event', 'attended_category', 'rsvpd_event'];
if (in_array($autoRule, $validRules, true) === false) {
    $autoRule = 'none';
}

// ✂️ Length-clip + nullify-if-empty for varchar columns.
if (strlen($name) > 255) { $name = substr($name, 0, 255); }
if (strlen($desc) > 1000) { $desc = substr($desc, 0, 1000); }
if (strlen($hint) > 500) { $hint = substr($hint, 0, 500); }
$descVal = $desc === '' ? null : $desc;
$hintVal = $hint === '' ? null : $hint;

if ($sortOrder < 0) { $sortOrder = 0; }

if ($pathwayId <= 0 || $name === '') {
    $_SESSION['flash_msg']  = 'Step name is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/discipleship/pathways/edit?id=' . $pathwayId, true, 302);
    exit();
}

// 🛡️ Cross-site guard — the parent pathway must belong to active site.
$stmt = $db->prepare('SELECT pathwayID FROM tblPathways WHERE pathwayID = ? AND siteID = ?');
if ($stmt === false) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('ii', $pathwayId, $siteId);
$stmt->execute();
$parentOk = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($parentOk === false) {
    http_response_code(404);
    exit('Pathway not found');
}

// 🛡️ Auto-rule ref validation — a rule requiring a ref must resolve to an
// existing row AT THIS SITE. 'none' always forces autoRefID to NULL.
$autoRefId = null;
if ($autoRule === 'attended_event' || $autoRule === 'rsvpd_event') {
    $refCandidate = (int) ($_POST['autoRefEventID'] ?? 0);
    if ($refCandidate <= 0) {
        $_SESSION['flash_msg']  = 'Choose an event for this auto-completion rule.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/discipleship/pathways/edit?id=' . $pathwayId, true, 302);
        exit();
    }
    $chk = $db->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ?');
    if ($chk === false) {
        http_response_code(500);
        exit('Database error');
    }
    $chk->bind_param('ii', $refCandidate, $siteId);
    $chk->execute();
    $refOk = $chk->get_result()->fetch_assoc() !== null;
    $chk->close();
    if ($refOk === false) {
        $_SESSION['flash_msg']  = 'That event could not be found on this site.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/discipleship/pathways/edit?id=' . $pathwayId, true, 302);
        exit();
    }
    $autoRefId = $refCandidate;
} elseif ($autoRule === 'attended_category') {
    $refCandidate = (int) ($_POST['autoRefCategoryID'] ?? 0);
    if ($refCandidate <= 0) {
        $_SESSION['flash_msg']  = 'Choose a category for this auto-completion rule.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/discipleship/pathways/edit?id=' . $pathwayId, true, 302);
        exit();
    }
    $chk = $db->prepare('SELECT categoryID FROM tblEventCategories WHERE categoryID = ? AND siteID = ?');
    if ($chk === false) {
        http_response_code(500);
        exit('Database error');
    }
    $chk->bind_param('ii', $refCandidate, $siteId);
    $chk->execute();
    $refOk = $chk->get_result()->fetch_assoc() !== null;
    $chk->close();
    if ($refOk === false) {
        $_SESSION['flash_msg']  = 'That category could not be found on this site.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/discipleship/pathways/edit?id=' . $pathwayId, true, 302);
        exit();
    }
    $autoRefId = $refCandidate;
}
// else $autoRule === 'none' — $autoRefId stays NULL.

if ($stepId === 0) {
    // 🆕 INSERT new step.
    $stmt = $db->prepare(
        'INSERT INTO tblPathwaySteps (pathwayID, sortOrder, name, description, completionHint, isOptional, autoRule, autoRefID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        http_response_code(500);
        exit('Database error');
    }
    $stmt->bind_param('iisssisi', $pathwayId, $sortOrder, $name, $descVal, $hintVal, $optional, $autoRule, $autoRefId);
    $stmt->execute();
    $newStepId = (int) $stmt->insert_id;
    $stmt->close();

    Logger::activity(
        'DiscipleshipStepCreated',
        'pathwayID=' . $pathwayId . ' stepID=' . $newStepId . ' name=' . $name,
        $userId
    );

    $_SESSION['flash_msg']  = 'Step added.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /admin/discipleship/pathways/edit?id=' . $pathwayId, true, 302);
    exit();
}

// ✏️ UPDATE existing step — scoped to pathwayID (which is already site-verified).
$stmt = $db->prepare(
    'UPDATE tblPathwaySteps SET sortOrder = ?, name = ?, description = ?, '
    . 'completionHint = ?, isOptional = ?, autoRule = ?, autoRefID = ? '
    . 'WHERE stepID = ? AND pathwayID = ?'
);
if ($stmt === false) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('isssisiii', $sortOrder, $name, $descVal, $hintVal, $optional, $autoRule, $autoRefId, $stepId, $pathwayId);
$stmt->execute();
$stmt->close();

Logger::activity(
    'DiscipleshipStepUpdated',
    'pathwayID=' . $pathwayId . ' stepID=' . $stepId . ' name=' . $name,
    $userId
);

$_SESSION['flash_msg']  = 'Step saved.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/discipleship/pathways/edit?id=' . $pathwayId, true, 302);
exit();
