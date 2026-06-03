<?php
// Path: public_html/newsletter/block-delete.php
/**
 * Newsletter — delete a content block.
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

$db        = App::db();
$siteId    = Site::id();
$newsId    = (int) ($_POST['newsletterID'] ?? 0);
$contentId = (int) ($_POST['contentID'] ?? 0);

$stmt = $db->prepare(
    'DELETE c FROM tblNewsletterContent c '
    . 'INNER JOIN tblNewsletter n ON n.newsletterID = c.newsletterID '
    . 'WHERE c.contentID = ? AND c.newsletterID = ? AND n.siteID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $contentId, $newsId, $siteId);
    $stmt->execute();
    $stmt->close();
}

header('Location: /newsletter/edit?id=' . $newsId);
exit();
