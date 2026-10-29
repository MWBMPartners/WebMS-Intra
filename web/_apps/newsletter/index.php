<?php
// Path: public_html/newsletter/index.php
/**
 * Newsletter — drafts, scheduled, and sent.
 *
 * @package   Portal\Newsletter
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Settings;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();

$rows = [];
$stmt = $db->prepare(
    'SELECT n.newsletterID, n.title, n.subject, n.status, n.scheduledFor, n.sentAt, n.sentCount, '
    . '       (SELECT COUNT(*) FROM tblNewsletterRecipient r WHERE r.newsletterID = n.newsletterID) AS recipientCount '
    . 'FROM tblNewsletter n WHERE n.siteID = ? ORDER BY n.updatedAt DESC LIMIT 100'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'Newsletter';
$pageSection = 'newsletter';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Newsletter' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php
// 📣 Tell the truth when `newsletter.provider` names something this release
//    does not implement. Only `internal` has an adapter; `mailermatt` and
//    `mailchimp` are accepted by Newsletter::providerSend() and fall back to
//    the portal's own mailer, so the newsletter DOES go out -- but the
//    setting is then describing a route that is not being used.
//
//    No admin page offers those values, so reaching this state means somebody
//    typed one into the general Settings editor. That is exactly the person
//    who needs telling, because otherwise the only clue is a source comment.
//
//    Read with a plain settings lookup rather than a new helper: there is one
//    setting and one question, and a helper would be a second place to keep
//    the list of what is implemented in step.
$newsletterRoute = strtolower(trim((string) Settings::get('newsletter.provider', 'internal')));
?>
<?php if ($newsletterRoute !== '' && $newsletterRoute !== 'internal'): ?>
    <div class="alert alert-warning d-flex align-items-start" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2 mt-1"></i>
        <div>
            <strong>Newsletters are being sent by the portal itself, not by
            &ldquo;<?php echo htmlspecialchars($newsletterRoute, ENT_QUOTES, 'UTF-8'); ?>&rdquo;.</strong>
            That sending service is not built into this release, so the setting has no
            effect and your newsletters go out through the portal&rsquo;s own e-mail
            settings instead. Nothing is lost and nothing is failing &mdash; but if you
            meant to use an outside service, it is not in use. Set
            <code>newsletter.provider</code> back to <code>internal</code> to stop this
            message.
        </div>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-envelope-open-text me-2"></i>Newsletter</h1>
        <p class="text-secondary mb-0">Compose and send branded newsletters to your membership.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?php echo htmlspecialchars(Site::url('newsletter/segments'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-users me-1"></i>Segments</a>
        <a href="<?php echo htmlspecialchars(Site::url('newsletter/new'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>New newsletter</a>
    </div>
</div>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">No newsletters yet. <a href="<?php echo htmlspecialchars(Site::url('newsletter/new'), ENT_QUOTES, 'UTF-8'); ?>">Compose the first →</a></div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($rows as $r):
                    $statusCls = match ((string) $r['status']) {
                        'sent'      => 'success',
                        'sending'   => 'warning',
                        'scheduled' => 'info',
                        'cancelled' => 'secondary',
                        default     => 'secondary',
                    };
                ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-5">
                            <a href="<?php echo htmlspecialchars(Site::url('newsletter/edit'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $r['newsletterID']; ?>" class="text-decoration-none">
                                <strong><?php echo htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </a>
                            <?php if (($r['subject'] ?? '') !== ''): ?>
                                <div class="small text-muted"><?php echo htmlspecialchars((string) $r['subject'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2"><span class="badge bg-<?php echo $statusCls; ?>"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2 small text-muted">
                            <?php if ($r['sentAt'] !== null): ?>
                                Sent <?php echo htmlspecialchars(date('j M, H:i', (int) strtotime((string) $r['sentAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            <?php elseif ($r['scheduledFor'] !== null): ?>
                                For <?php echo htmlspecialchars(date('j M, H:i', (int) strtotime((string) $r['scheduledFor'])), ENT_QUOTES, 'UTF-8'); ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </div>
                        <div class="col-md-1 small text-muted text-end"><?php echo (int) $r['sentCount']; ?> / <?php echo (int) $r['recipientCount']; ?></div>
                        <div class="col-md-2 text-end">
                            <a href="<?php echo htmlspecialchars(Site::url('newsletter/preview'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $r['newsletterID']; ?>" class="btn btn-outline-secondary btn-sm" target="_blank">Preview</a>
                            <a href="<?php echo htmlspecialchars(Site::url('newsletter/edit'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $r['newsletterID']; ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
