<?php
// Path: public_html/projects/manage-save.php
/**
 * Projects — create / edit save handler.
 *
 * @package   Portal\Projects
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/267
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Projects;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$id     = (int) ($_POST['projectID'] ?? 0);

$title       = trim((string) ($_POST['title'] ?? ''));
$targetRaw   = (string) ($_POST['targetAmount'] ?? '');
$clean       = preg_replace('/[^0-9.]/', '', $targetRaw) ?? '';
$targetPence = (int) round(((float) $clean) * 100);
$status      = (string) ($_POST['status'] ?? 'planning');
$started     = (string) ($_POST['startedAt'] ?? '');
$ends        = (string) ($_POST['endsAt'] ?? '');
$desc        = (string) ($_POST['description'] ?? '');
$cover       = trim((string) ($_POST['coverImagePath'] ?? ''));
$isPublic    = isset($_POST['isPublic']) === true ? 1 : 0;

if (in_array($status, ['planning','active','funded','completed','cancelled'], true) === false) {
    $status = 'planning';
}
if ($title === '' || $targetPence < 100) {
    $_SESSION['flash_msg']  = 'Title and target amount are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /projects/manage');
    exit();
}
$startOrNull = $started !== '' && strtotime($started) !== false ? $started : null;
$endOrNull   = $ends    !== '' && strtotime($ends) !== false    ? $ends    : null;
$coverOrNull = $cover !== '' ? $cover : null;

$slugForRedirect = '';
if ($id > 0) {
    $stmt = $db->prepare('SELECT slug FROM tblProject WHERE projectID = ? AND siteID = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $id, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row !== null) {
            $slugForRedirect = (string) $row['slug'];
        }
    }
    $u = $db->prepare(
        'UPDATE tblProject SET title = ?, description = ?, targetAmountPence = ?, status = ?, '
        . 'startedAt = ?, endsAt = ?, coverImagePath = ?, isPublic = ? '
        . 'WHERE projectID = ? AND siteID = ?'
    );
    if ($u !== false) {
        $u->bind_param('ssisssssii', $title, $desc, $targetPence, $status, $startOrNull, $endOrNull, $coverOrNull, $isPublic, $id, $siteId);
        $u->execute();
        $u->close();
    }
    $_SESSION['flash_msg']  = 'Project updated.';
} else {
    $slug = Projects::uniqueSlug($siteId, $title);
    $slugForRedirect = $slug;
    $i = $db->prepare(
        'INSERT INTO tblProject (siteID, slug, title, description, targetAmountPence, status, '
        . 'startedAt, endsAt, coverImagePath, isPublic, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($i !== false) {
        $i->bind_param('isssissssii', $siteId, $slug, $title, $desc, $targetPence, $status, $startOrNull, $endOrNull, $coverOrNull, $isPublic, $userId);
        $i->execute();
        $i->close();
    }
    $_SESSION['flash_msg']  = 'Project created.';
}
$_SESSION['flash_type'] = 'success';

if ($slugForRedirect !== '') {
    header('Location: /projects/manage?slug=' . urlencode($slugForRedirect));
} else {
    header('Location: /projects/manage');
}
exit();
