<?php
// Path: public_html/giving/cat-save.php
/**
 * Giving — category add / toggle handler.
 *
 * @package   Portal\Giving
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/266
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$action = (string) ($_POST['action'] ?? '');

if ($action === 'add') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $desc = trim((string) ($_POST['description'] ?? ''));
    $fund = trim((string) ($_POST['defaultFund'] ?? ''));
    if ($name !== '') {
        $stmt = $db->prepare('INSERT INTO tblGivingCategory (siteID, name, description, defaultFund) VALUES (?, ?, ?, ?)');
        if ($stmt !== false) {
            $stmt->bind_param('isss', $siteId, $name, $desc, $fund);
            $stmt->execute();
            $stmt->close();
        }
        $_SESSION['flash_msg']  = 'Category added.';
        $_SESSION['flash_type'] = 'success';
    }
} elseif ($action === 'toggle') {
    $id = (int) ($_POST['categoryID'] ?? 0);
    $stmt = $db->prepare('UPDATE tblGivingCategory SET isActive = 1 - isActive WHERE categoryID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $id, $siteId);
        $stmt->execute();
        $stmt->close();
    }
}

header('Location: /giving/categories');
exit();
