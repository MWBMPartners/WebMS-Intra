<?php
// Path: public_html/newsletter/send.php
/**
 * Newsletter — trigger send: resolve segment, lock in recipients, dispatch
 * up to `newsletter.batchPerHour` in this pass (the rest go on subsequent
 * invocations — caller can re-trigger or a cron can sweep).
 *
 * @package   Portal\Newsletter
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Newsletter;
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

$news = null;
$stmt = $db->prepare('SELECT status, segmentID FROM tblNewsletter WHERE newsletterID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $news = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($news === null) {
    http_response_code(404);
    exit('Newsletter not found');
}

if (in_array((string) $news['status'], ['draft','scheduled'], true) === true) {
    $segmentId = $news['segmentID'] !== null ? (int) $news['segmentID'] : null;
    $recipients = Newsletter::resolveSegment($siteId, $segmentId);
    Newsletter::lockInRecipients($id, $recipients);

    $u = $db->prepare('UPDATE tblNewsletter SET status = "sending" WHERE newsletterID = ?');
    if ($u !== false) {
        $u->bind_param('i', $id);
        $u->execute();
        $u->close();
    }
}

$result = Newsletter::dispatch($id, $siteId);

// Mark complete when no rows are still pending.
$stmt = $db->prepare(
    'SELECT COUNT(*) FROM tblNewsletterRecipient '
    . 'WHERE newsletterID = ? AND deliveredAt IS NULL AND errorMsg IS NULL'
);
$pending = 0;
if ($stmt !== false) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($pending);
    $stmt->fetch();
    $stmt->close();
}
if ($pending === 0) {
    $u = $db->prepare('UPDATE tblNewsletter SET status = "sent", sentAt = NOW() WHERE newsletterID = ?');
    if ($u !== false) {
        $u->bind_param('i', $id);
        $u->execute();
        $u->close();
    }
}

$_SESSION['flash_msg']  = sprintf(
    'Sent %d, failed %d. %s',
    (int) $result['sent'],
    (int) $result['failed'],
    $pending > 0 ? $pending . ' remaining (rate-limited — re-trigger to continue).' : 'Done.'
);
$_SESSION['flash_type'] = (int) $result['failed'] > 0 ? 'warning' : 'success';

header('Location: /newsletter/recipients?id=' . $id);
exit();
