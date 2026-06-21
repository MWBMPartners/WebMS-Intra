<?php
// Path: _apps/admin/discipleship/pathway-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship Pathway save (create + update) POST 📖 (#303 Phase 1)
 * -----------------------------------------------------------------------------
 * Single handler for both:
 *   • pathwayID === 0  → INSERT new pathway
 *   • pathwayID > 0    → UPDATE existing (cross-site guarded by siteID = ?)
 *
 * Gated by:
 *   • Auth::requireLogin()
 *   • App::isAdmin() === true
 *   • Auth::verifyCsrf()
 *   • Settings::get('discipleship.enabled') truthy
 *   • Cross-site guard on update
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
$name      = trim((string) ($_POST['name'] ?? ''));
$desc      = trim((string) ($_POST['description'] ?? ''));
$isActive  = isset($_POST['isActive']) === true ? 1 : 0;

// ✂️ Length-clip to schema limits in case a client bypassed maxlength.
if (strlen($name) > 120) { $name = substr($name, 0, 120); }
if (strlen($desc) > 1000) { $desc = substr($desc, 0, 1000); }
if ($desc === '') { $desc = null; }

if ($name === '') {
    $_SESSION['flash_msg']  = 'Name is required.';
    $_SESSION['flash_type'] = 'danger';
    $redirect = $pathwayId > 0
        ? '/admin/discipleship/pathways/edit?id=' . $pathwayId
        : '/admin/discipleship/pathways/new';
    header('Location: ' . $redirect, true, 302);
    exit();
}

if ($pathwayId === 0) {
    // 🆕 INSERT new pathway.
    $stmt = $db->prepare(
        'INSERT INTO tblPathways (siteID, name, description, isActive, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        http_response_code(500);
        exit('Database error');
    }
    $stmt->bind_param('issii', $siteId, $name, $desc, $isActive, $userId);
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();

    Logger::activity(
        'DiscipleshipPathwayCreated',
        'pathwayID=' . $newId . ' name=' . $name,
        $userId
    );

    $_SESSION['flash_msg']  = 'Pathway created. Add steps below.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /admin/discipleship/pathways/edit?id=' . $newId, true, 302);
    exit();
}

// ✏️ UPDATE existing pathway — cross-site guard via siteID = ? in WHERE.
$stmt = $db->prepare(
    'UPDATE tblPathways SET name = ?, description = ?, isActive = ? '
    . 'WHERE pathwayID = ? AND siteID = ?'
);
if ($stmt === false) {
    http_response_code(500);
    exit('Database error');
}
$stmt->bind_param('ssiii', $name, $desc, $isActive, $pathwayId, $siteId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === -1) {
    $_SESSION['flash_msg']  = 'Pathway could not be saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/discipleship/pathways/edit?id=' . $pathwayId, true, 302);
    exit();
}

Logger::activity(
    'DiscipleshipPathwayUpdated',
    'pathwayID=' . $pathwayId . ' name=' . $name,
    $userId
);

$_SESSION['flash_msg']  = 'Pathway saved.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/discipleship/pathways/edit?id=' . $pathwayId, true, 302);
exit();
