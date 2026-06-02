<?php
// Path: public_html/projects/update-post.php
/**
 * Projects — post an update to a project's feed.
 *
 * @package   Portal\Projects
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/267
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
$userId = (int) ($_SESSION['user_id'] ?? 0);
$slug   = (string) ($_POST['slug'] ?? '');
$content = trim((string) ($_POST['content'] ?? ''));

if ($slug === '' || $content === '') {
    header('Location: /projects/manage');
    exit();
}

$projectId = 0;
$stmt = $db->prepare('SELECT projectID FROM tblProject WHERE siteID = ? AND slug = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $slug);
    $stmt->execute();
    $stmt->bind_result($projectId);
    $stmt->fetch();
    $stmt->close();
}
if ($projectId > 0) {
    $ins = $db->prepare('INSERT INTO tblProjectUpdate (projectID, postedByID, content) VALUES (?, ?, ?)');
    if ($ins !== false) {
        $ins->bind_param('iis', $projectId, $userId, $content);
        $ins->execute();
        $ins->close();
    }
    $_SESSION['flash_msg']  = 'Update posted.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: /projects/manage?slug=' . urlencode($slug));
exit();
