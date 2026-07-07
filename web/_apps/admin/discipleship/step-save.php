<?php
// Path: _apps/admin/discipleship/step-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship Pathway step save (create + update) POST 📖 (#303 Phase 1)
 * -----------------------------------------------------------------------------
 * Single handler for both:
 *   • stepID === 0  → INSERT new step against pathwayID
 *   • stepID > 0    → UPDATE existing step (verified via pathwayID join)
 *
 * The parent pathway is re-verified against siteID on EVERY save so a forged
 * pathwayID can't write into another site's pathway list.
 *
 * @package   Portal\App\Admin\Discipleship
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
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

if ($stepId === 0) {
    // 🆕 INSERT new step.
    $stmt = $db->prepare(
        'INSERT INTO tblPathwaySteps (pathwayID, sortOrder, name, description, completionHint, isOptional) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        http_response_code(500);
        exit('Database error');
    }
    $stmt->bind_param('iisssi', $pathwayId, $sortOrder, $name, $descVal, $hintVal, $optional);
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
    . 'completionHint = ?, isOptional = ? '
    . 'WHERE stepID = ? AND pathwayID = ?'
);
if ($stmt === false) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('isssiii', $sortOrder, $name, $descVal, $hintVal, $optional, $stepId, $pathwayId);
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
