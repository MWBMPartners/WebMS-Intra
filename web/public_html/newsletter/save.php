<?php
// Path: public_html/newsletter/save.php
/**
 * Newsletter — header save (title/subject/segment/scheduledFor).
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
$id     = (int) ($_POST['newsletterID'] ?? 0);

$title     = trim((string) ($_POST['title'] ?? ''));
$subject   = trim((string) ($_POST['subject'] ?? ''));
$segmentId = (int) ($_POST['segmentID'] ?? 0);
$schedFor  = trim((string) ($_POST['scheduledFor'] ?? ''));

if ($id > 0 && $title !== '') {
    $segOrNull = $segmentId > 0 ? $segmentId : null;
    $schedOrNull = $schedFor !== '' && strtotime($schedFor) !== false ? date('Y-m-d H:i:s', (int) strtotime($schedFor)) : null;
    $status = $schedOrNull !== null ? 'scheduled' : 'draft';
    $stmt = $db->prepare('UPDATE tblNewsletter SET title = ?, subject = ?, segmentID = ?, scheduledFor = ?, status = ? WHERE newsletterID = ? AND siteID = ? AND status IN ("draft","scheduled")');
    if ($stmt !== false) {
        $stmt->bind_param('ssissii', $title, $subject, $segOrNull, $schedOrNull, $status, $id, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    $_SESSION['flash_msg']  = 'Newsletter saved.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: /newsletter/edit?id=' . $id);
exit();
