<?php
// Path: public_html/newsletter/preview.php
/**
 * Newsletter — desktop preview. Shows the rendered HTML as it would
 * appear in a real email (sans personalisation tokens). Wrapped in the
 * Mailer base template so it looks like the real send.
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

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_GET['id'] ?? 0);

$news = null;
$stmt = $db->prepare('SELECT title, subject FROM tblNewsletter WHERE newsletterID = ? AND siteID = ? LIMIT 1');
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

$body = Newsletter::renderHtml($id, $siteId);
$body = str_replace('{{UNSUB_URL}}', '#preview-unsubscribe', $body);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Preview — <?php echo htmlspecialchars((string) $news['title'], ENT_QUOTES, 'UTF-8'); ?></title>
<style>
body { background:#f3f4f6; margin:0; font-family: Arial, sans-serif; }
.preview-wrap { max-width: 640px; margin: 24px auto; background:#fff; padding: 24px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
.preview-meta { background:#eef2ff; padding:8px 12px; font-size:12px; color:#4b5563; margin-bottom:16px; border-radius:4px; }
</style>
</head>
<body>
<div class="preview-wrap">
    <div class="preview-meta">
        <strong>Preview</strong> · Subject: <?php echo htmlspecialchars((string) ($news['subject'] ?? $news['title']), ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php echo $body; ?>
</div>
</body>
</html>
