<?php
// Path: public_html/newsletter/unsubscribe.php
/**
 * Newsletter — one-click unsubscribe (no login). Token resolves to a
 * userID + siteID; we upsert tblNewsletterSubscription with optedIn = 0.
 *
 * @package   Portal\Newsletter
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Site;

$token = (string) ($_GET['token'] ?? '');
if ($token === '' || preg_match('/^[a-f0-9]{40}$/', $token) !== 1) {
    // 🔗 A proper page, not a line of bare text (#569). This used to answer
    //    `text/plain` with "Invalid unsubscribe link." — unstyled, no heading,
    //    nothing to do next — and it is reached from an e-mail, which is
    //    exactly where a link gets broken in half by a mail program or
    //    followed months later. The same page is used for every reason a token
    //    fails, on purpose: see the template's own header.
    http_response_code(400);
    $linkPurpose = 'unsubscribe from our newsletter';
    $linkAdvice  = 'If you still want to unsubscribe, use the link at the bottom of a recent newsletter.';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'link-unusable.php';
    exit();
}

$db = App::db();

// Try recipient-issued token first (most common — sent in a newsletter).
$userId = 0;
$siteId = 0;
$stmt = $db->prepare(
    'SELECT n.siteID, r.userID FROM tblNewsletterRecipient r '
    . 'INNER JOIN tblNewsletter n ON n.newsletterID = r.newsletterID '
    . 'WHERE r.unsubToken = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->bind_result($siteId, $userId);
    $stmt->fetch();
    $stmt->close();
}

// Fallback: subscription-level token (manage-preferences link).
if ($userId === 0) {
    $stmt = $db->prepare('SELECT siteID, userID FROM tblNewsletterSubscription WHERE unsubToken = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->bind_result($siteId, $userId);
        $stmt->fetch();
        $stmt->close();
    }
}

$ok = false;
if ($userId > 0 && $siteId > 0) {
    $fresh = bin2hex(random_bytes(20));
    $stmt = $db->prepare(
        'INSERT INTO tblNewsletterSubscription (siteID, userID, optedIn, unsubToken) VALUES (?, ?, 0, ?) '
        . 'ON DUPLICATE KEY UPDATE optedIn = 0'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iis', $siteId, $userId, $fresh);
        $ok = $stmt->execute();
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Unsubscribe</title>
    <?php
    // 🔗 #569 — the description, canonical address and preview card.
    //    This page prints its own <head>, so it includes the shared
    //    file itself rather than inheriting it from header.php.
    $pageTitle       = 'Unsubscribe';
    $pageDescription = 'Stop receiving our newsletter.';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'head-meta.php';
    ?>

<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/css/print.css">
<style>
body { font-family: Arial, sans-serif; max-width: 480px; margin: 80px auto; padding: 24px; text-align: center; color: #1b2330; }
h1 { color: #5e6ad2; }
.box { background:#f8fafc; padding: 24px; border-radius: 6px; }
</style>
</head>
<body>
<div class="box">
    <?php if ($ok === true): ?>
        <h1>You've been unsubscribed.</h1>
        <p>You will no longer receive newsletters from this portal. If you change your mind, sign in and manage your notifications.</p>
    <?php else: ?>
        <h1>Link expired</h1>
        <p>This unsubscribe link is invalid or has already been used. If you'd still like to opt out, sign in and update your notification preferences.</p>
    <?php endif; ?>
    <p><a href="<?php echo htmlspecialchars(Site::url(''), ENT_QUOTES, 'UTF-8'); ?>">Return to the portal</a></p>
</div>
</body>
</html>
