<?php
// Path: public_html/newsletter/recipients.php
/**
 * Newsletter — recipient preview (pre-send) + per-row delivery state (post-send).
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
$stmt = $db->prepare('SELECT title, status, segmentID FROM tblNewsletter WHERE newsletterID = ? AND siteID = ? LIMIT 1');
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

// Already-locked-in recipients (post send-trigger).
$locked = [];
$stmt = $db->prepare(
    'SELECT r.recipientID, r.emailAddress, r.deliveredAt, r.openedAt, r.clickedAt, r.errorMsg, u.fullName '
    . 'FROM tblNewsletterRecipient r INNER JOIN tblUsers u ON u.userID = r.userID '
    . 'WHERE r.newsletterID = ? ORDER BY r.recipientID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $locked[] = $r;
    }
    $stmt->close();
}

// Pre-send preview of what the segment resolves to right now.
$previewRecipients = [];
if (count($locked) === 0) {
    $previewRecipients = Newsletter::resolveSegment($siteId, $news['segmentID'] !== null ? (int) $news['segmentID'] : null);
}

$pageTitle   = 'Recipients';
$pageSection = 'newsletter';
$breadcrumbs = ['Dashboard' => '/', 'Newsletter' => '/newsletter', 'Recipients' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-users me-2"></i>Recipients — <?php echo htmlspecialchars((string) $news['title'], ENT_QUOTES, 'UTF-8'); ?></h1>

<?php if (count($locked) > 0): ?>
    <p class="text-secondary">Delivery state — <?php echo count($locked); ?> recipient(s).</p>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($locked as $r): ?>
                <div class="row py-2 border-bottom align-items-center">
                    <div class="col-md-4"><strong><?php echo htmlspecialchars((string) $r['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-md-4 small text-muted"><?php echo htmlspecialchars((string) $r['emailAddress'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2 small">
                        <?php if ($r['errorMsg'] !== null): ?>
                            <span class="badge bg-danger">error</span>
                        <?php elseif ($r['deliveredAt'] !== null): ?>
                            <span class="badge bg-success">delivered</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">pending</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2 small text-muted">
                        <?php if ($r['openedAt'] !== null): ?>opened<?php endif; ?>
                        <?php if ($r['clickedAt'] !== null): ?>· clicked<?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php else: ?>
    <p class="text-secondary">Resolved segment preview (<?php echo count($previewRecipients); ?> recipient(s)) — will be locked in on send.</p>
    <div class="card"><div class="card-body">
        <?php if (count($previewRecipients) === 0): ?>
            <p class="text-muted mb-0">No matching members — check the segment rules and opt-in state.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach (array_slice($previewRecipients, 0, 100) as $r): ?>
                    <div class="row py-1 border-bottom">
                        <div class="col-md-5"><?php echo htmlspecialchars((string) $r['fullName'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-7 small text-muted"><?php echo htmlspecialchars((string) $r['emailAddress'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (count($previewRecipients) > 100): ?>
                    <p class="small text-muted mt-2">… and <?php echo count($previewRecipients) - 100; ?> more.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div></div>
<?php endif; ?>

<p class="mt-3"><a href="/newsletter/edit?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm">← Back to edit</a></p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
