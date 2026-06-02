<?php
// Path: public_html/newsletter/block-save.php
/**
 * Newsletter — add a content block.
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
$blockType = (string) ($_POST['blockType'] ?? 'text');
$content   = trim((string) ($_POST['content'] ?? ''));

$validTypes = ['text','image','heading','divider','cta','announcements','events','prayers','sermon'];
if (in_array($blockType, $validTypes, true) === false) {
    $blockType = 'text';
}

// Verify newsletter belongs to this site.
$stmt = $db->prepare('SELECT 1 FROM tblNewsletter WHERE newsletterID = ? AND siteID = ?');
$ok = false;
if ($stmt !== false) {
    $stmt->bind_param('ii', $newsId, $siteId);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
}
if ($ok === false) {
    http_response_code(404);
    exit('Newsletter not found');
}

$cfg = [];
switch ($blockType) {
    case 'heading':
    case 'text':
        $cfg = ['text' => $content];
        break;
    case 'image':
        $cfg = ['url' => $content];
        break;
    case 'cta':
        $parts = explode('|', $content, 2);
        $cfg = ['label' => trim($parts[0] ?? 'Read more'), 'url' => trim($parts[1] ?? '#')];
        break;
    case 'announcements':
    case 'prayers':
        $cfg = ['count' => max(1, min(10, (int) ($content !== '' ? $content : '3')))];
        break;
    case 'events':
        $cfg = ['days' => max(1, min(60, (int) ($content !== '' ? $content : '14')))];
        break;
    case 'divider':
    case 'sermon':
        $cfg = [];
        break;
}

// Append to end — position = max(position) + 1.
$nextPos = 0;
$stmt = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM tblNewsletterContent WHERE newsletterID = ?');
if ($stmt !== false) {
    $stmt->bind_param('i', $newsId);
    $stmt->execute();
    $stmt->bind_result($nextPos);
    $stmt->fetch();
    $stmt->close();
}

$payload = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$stmt = $db->prepare('INSERT INTO tblNewsletterContent (newsletterID, blockType, position, payload) VALUES (?, ?, ?, ?)');
if ($stmt !== false) {
    $stmt->bind_param('isis', $newsId, $blockType, $nextPos, $payload);
    $stmt->execute();
    $stmt->close();
}

header('Location: /newsletter/edit?id=' . $newsId);
exit();
