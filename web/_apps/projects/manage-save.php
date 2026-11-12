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
$targetRaw   = $_POST['targetAmount'] ?? '';
$status      = (string) ($_POST['status'] ?? 'planning');
$started     = (string) ($_POST['startedAt'] ?? '');
$ends        = (string) ($_POST['endsAt'] ?? '');
$desc        = (string) ($_POST['description'] ?? '');
$cover       = trim((string) ($_POST['coverImagePath'] ?? ''));
$isPublic    = isset($_POST['isPublic']) === true ? 1 : 0;

if (in_array($status, ['planning','active','funded','completed','cancelled'], true) === false) {
    $status = 'planning';
}
// 💷 The target is read by the ONE converter, Currency::parseAmount()
//    (#1004), in the project's own currency (an edited project keeps its own,
//    D-139; a new one is the organisation's). It used to strip everything but
//    figures and points and go through a float, so "1e5" became 15.00 and an
//    absurd figure wrapped into a small one.
$targetCurrency = \Portal\Core\Currency::ofSite();
if ($id > 0) {
    $cur = $db->prepare('SELECT `currency` FROM `tblProject` WHERE `projectID` = ? AND `siteID` = ? LIMIT 1');
    $cur->bind_param('ii', $id, $siteId);
    $cur->execute();
    $curRow = $cur->get_result()->fetch_assoc();
    $cur->close();
    $targetCurrency = is_array($curRow) === true ? (string) $curRow['currency'] : $targetCurrency;
}
$targetText  = is_string($targetRaw) === true ? $targetRaw : '';
$targetPence = \Portal\Core\Currency::parseAmount($targetText, $targetCurrency);
// A refused figure says why; an EMPTY box keeps the old "required" sentence below.
if ($title !== '' && trim($targetText) !== '' && is_string($targetPence) === true) {
    $_SESSION['flash_msg']  = 'Target amount: ' . $targetPence . ' Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('projects/manage'));
    exit();
}
if ($title === '' || is_string($targetPence) === true || $targetPence < 100) {
    $_SESSION['flash_msg']  = 'Title and target amount are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('projects/manage'));
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
        . 'startedAt, endsAt, coverImagePath, isPublic, createdByID, `currency`) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($i !== false) {
        // 💷 The project is made in the organisation's currency (#966). This
        //    INSERT used to name no currency, so EVERY project was stored as GBP
        //    by the column's default, whatever `projects.currency` said; the
        //    column has no default now, so leaving it out fails loudly.
        $projectCurrency = \Portal\Core\Currency::ofSite();
        $i->bind_param('isssissssiis', $siteId, $slug, $title, $desc, $targetPence, $status, $startOrNull, $endOrNull, $coverOrNull, $isPublic, $userId, $projectCurrency);
        $i->execute();
        $i->close();
    }
    $_SESSION['flash_msg']  = 'Project created.';
}
$_SESSION['flash_type'] = 'success';

if ($slugForRedirect !== '') {
    header('Location: ' . Site::url('projects/manage') . '?slug=' . urlencode($slugForRedirect));
} else {
    header('Location: ' . Site::url('projects/manage'));
}
exit();
