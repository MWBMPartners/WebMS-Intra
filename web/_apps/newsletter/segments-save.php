<?php
// Path: public_html/newsletter/segments-save.php
/**
 * Newsletter — segment add/delete POST handler.
 *
 * @package   Portal\Newsletter
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
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
$action = (string) ($_POST['action'] ?? '');

if ($action === 'add') {
    $name  = trim((string) ($_POST['name'] ?? ''));
    $roles = trim((string) ($_POST['roles'] ?? ''));
    if ($name === '') {
        $_SESSION['flash_msg']  = 'Segment name is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /newsletter/segments');
        exit();
    }
    $rule = ['all' => true];
    if ($roles !== '') {
        $list = array_values(array_filter(array_map('trim', explode(',', $roles))));
        if ($list !== []) {
            $rule = ['roles' => $list];
        }
    }
    $json = json_encode($rule, JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare('INSERT INTO tblNewsletterSegment (siteID, name, ruleJson) VALUES (?, ?, ?)');
    if ($stmt !== false) {
        $stmt->bind_param('iss', $siteId, $name, $json);
        $stmt->execute();
        $stmt->close();
    }
    $_SESSION['flash_msg']  = 'Segment added.';
    $_SESSION['flash_type'] = 'success';
} elseif ($action === 'delete') {
    $segId = (int) ($_POST['segmentID'] ?? 0);
    $stmt = $db->prepare('DELETE FROM tblNewsletterSegment WHERE segmentID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $segId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    $_SESSION['flash_msg']  = 'Segment deleted.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: /newsletter/segments');
exit();
