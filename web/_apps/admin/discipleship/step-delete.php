<?php
// Path: _apps/admin/discipleship/step-delete.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship Pathway step delete POST 📖 (#303 Phase 1)
 * -----------------------------------------------------------------------------
 * Deletes a single step from a pathway. Cross-site guarded via parent
 * pathway lookup (siteID match) before the DELETE fires.
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

if ($pathwayId <= 0 || $stepId <= 0) {
    http_response_code(400);
    exit('Invalid step');
}

// 🛡️ Cross-site guard — verify parent pathway is in the active site.
$stmt = $db->prepare(
    'SELECT s.stepID, s.name FROM tblPathwaySteps s '
    . 'JOIN tblPathways p ON p.pathwayID = s.pathwayID '
    . 'WHERE s.stepID = ? AND s.pathwayID = ? AND p.siteID = ?'
);
if ($stmt === false) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('iii', $stepId, $pathwayId, $siteId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row === null) {
    http_response_code(404);
    exit('Step not found');
}

$stepName = (string) ($row['name'] ?? '');

$stmt = $db->prepare('DELETE FROM tblPathwaySteps WHERE stepID = ? AND pathwayID = ?');
if ($stmt === false) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('ii', $stepId, $pathwayId);
$stmt->execute();
$stmt->close();

Logger::activity(
    'DiscipleshipStepDeleted',
    'pathwayID=' . $pathwayId . ' stepID=' . $stepId . ' name=' . $stepName,
    $userId
);

$_SESSION['flash_msg']  = 'Step deleted.';
$_SESSION['flash_type'] = 'warning';
header('Location: /admin/discipleship/pathways/edit?id=' . $pathwayId, true, 302);
exit();
