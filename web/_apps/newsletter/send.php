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

// 🔗 Can it be sent at all (#500)? Asked BEFORE anybody is locked in or the
//    newsletter is marked as "sending". A newsletter is not sent without the
//    portal's address, because every copy must carry a whole "unsubscribe"
//    link. This page used to lock the recipients in and mark the newsletter
//    "sending" first, and only then call dispatch(), which answered "sent 0,
//    failed 0". The sender saw "Sending in progress" and a "Continue
//    sending" button that could never finish, and no reason (independent
//    check, 12 October 2026). Now, when it cannot be sent, nothing is locked
//    in and nothing is marked.
//
//    dispatch() is still called either way. It is the one that refuses: it
//    writes the entry in Admin, Errors and gives the reason back in
//    `notSent`, and the message below is shown on that reason. It cannot
//    answer differently from the question asked here, because PortalAddress
//    reads the setting once a request.
$canStart = Newsletter::cannotSendBecause($siteId) === '';
if ($canStart === true && in_array((string) $news['status'], ['draft','scheduled'], true) === true) {
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

if ((string) ($result['notSent'] ?? '') !== '') {
    // One reason exists today (Newsletter::NOT_SENT_NO_ADDRESS), and these
    // are its words. Only a global administrator can save the address, so
    // the sentence says whom to ask as well as where it is set.
    $_SESSION['flash_msg']  = 'This newsletter was not sent, and nobody was sent a copy just now. The portal does not know its '
        . 'own web address, so the unsubscribe link that every copy must carry could not be written. Ask a global '
        . 'administrator to set the portal\'s address at Admin, Settings, Organisation, then send it again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /newsletter/recipients?id=' . $id);
    exit();
}

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
// ⏳ Copies still WAITING in the mail queue (#592), from this pass or an
//    earlier one. While there are any, the newsletter is NOT marked as sent:
//    it has not all gone. Pressing Send again once they have gone marks it.
$stillWaiting = 0;
$waitingMark  = Newsletter::WAITING;
$stmt = $db->prepare('SELECT COUNT(*) FROM tblNewsletterRecipient WHERE newsletterID = ? AND deliveredAt IS NULL AND errorMsg = ?');
if ($stmt !== false) {
    $stmt->bind_param('is', $id, $waitingMark);
    $stmt->execute();
    $stmt->bind_result($stillWaiting);
    $stmt->fetch();
    $stmt->close();
}
if ($pending === 0 && $stillWaiting === 0) {
    $u = $db->prepare('UPDATE tblNewsletter SET status = "sent", sentAt = NOW() WHERE newsletterID = ?');
    if ($u !== false) {
        $u->bind_param('i', $id);
        $u->execute();
        $u->close();
    }
}

// ⏳ "Waiting" is said apart from "sent" (#592): a copy kept in the mail queue
//    has not gone. It used to be counted as sent and marked as delivered.
$_SESSION['flash_msg']  = sprintf(
    'Sent %d, failed %d. %s%s',
    (int) $result['sent'],
    (int) $result['failed'],
    $stillWaiting > 0
        ? $stillWaiting . ' waiting in the mail queue: each is marked as delivered only once it has gone (the health page says whether waiting mail is being sent). Press Send again after that to mark the newsletter as sent. '
        : '',
    $pending > 0 ? $pending . ' remaining (rate-limited — re-trigger to continue).' : ($stillWaiting > 0 ? '' : 'Done.')
);
$_SESSION['flash_type'] = (int) $result['failed'] > 0 ? 'warning' : 'success';

header('Location: /newsletter/recipients?id=' . $id);
exit();
