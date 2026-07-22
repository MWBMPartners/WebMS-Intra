<?php
// Path: _apps/admin/integrations/webhooks/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Webhooks management 🪝 (#324)
 * -----------------------------------------------------------------------------
 * List + create UI for tblWebhooks. The HMAC signing secret is shown ONCE
 * here on creation, via a flash message — the plaintext is never persisted
 * anywhere the UI re-reads it from, and it is never re-displayed after this
 * single reveal (mirrors the API-keys mint flow, #323).
 *
 * Routes (seeded in migration 111 / full_schema.sql — this file resolves
 * the previously-missing `admin/integrations/webhooks` target):
 *   admin/integrations/webhooks         → this file    (GET  list + create form)
 *   admin/integrations/webhooks/save    → save.php      (POST create/update)
 *   admin/integrations/webhooks/delete  → delete.php    (POST delete)
 *
 * Backend: Portal\Core\WebhookDispatcher::emit() (already shipped in #324)
 * resolves active webhooks by eventTypes and POSTs the signed payload.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/324
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

// 🛡️ Admin gate — identical to api-keys.php.
Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$siteId = Site::id();

// ============================================================================
// 📋 Load this site's webhooks + creator name
// ============================================================================
$webhooks = [];
$stmt = $mysqli->prepare(
    'SELECT w.webhookID, w.name, w.eventTypes, w.targetUrl, w.isActive, w.createdAt, '
    . '       w.lastDeliveryAt, u.fullName AS creatorName '
    . 'FROM tblWebhooks w LEFT JOIN tblUsers u ON u.userID = w.createdByID '
    . 'WHERE w.siteID = ? ORDER BY w.isActive DESC, w.createdAt DESC LIMIT 200'
);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();
while (($r = $result->fetch_assoc()) !== null) {
    $webhooks[] = $r;
}
$stmt->close();

// ============================================================================
// 📨 Recent deliveries (last 25 for this site, joined for the webhook name)
// ============================================================================
$deliveries = [];
$stmt = $mysqli->prepare(
    'SELECT d.deliveryID, d.eventType, d.status, d.attemptCount, d.responseCode, '
    . '       d.lastAttemptAt, d.createdAt, w.name AS webhookName '
    . 'FROM tblWebhookDeliveries d INNER JOIN tblWebhooks w ON w.webhookID = d.webhookID '
    . 'WHERE w.siteID = ? ORDER BY d.createdAt DESC LIMIT 25'
);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();
while (($r = $result->fetch_assoc()) !== null) {
    $deliveries[] = $r;
}
$stmt->close();

// 🍞 Generic flash (create/update/delete redirects land here)
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'Webhooks';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Integrations' => '/admin/integrations', 'Webhooks' => ''];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

if ($flashMsg !== '') {
    echo '<div class="alert alert-' . htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') . '</div>';
}

// 🔑 Flash for the newly-created signing secret — shown ONCE.
if (isset($_SESSION['webhook_secret_minted']) === true) {
    $minted = $_SESSION['webhook_secret_minted'];
    unset($_SESSION['webhook_secret_minted']);
    echo '<div class="container py-2" style="max-width:960px;">';
    echo '<div class="alert alert-warning">';
    echo '<h2 class="h5"><i class="fa-solid fa-key me-1"></i>Copy the signing secret NOW</h2>';
    echo '<p class="mb-2">This is the only time the full plaintext secret will be shown. Use it to verify the '
        . '<code>X-Webhook-Signature</code> header on incoming deliveries.</p>';
    echo '<code class="d-block p-2 bg-light" style="font-family:monospace; word-break:break-all; user-select:all;">';
    echo htmlspecialchars((string) $minted['plaintext'], ENT_QUOTES, 'UTF-8');
    echo '</code>';
    echo '<p class="small mt-2 mb-0">Webhook #' . (int) $minted['webhookID'] . ' &middot; <strong>'
        . htmlspecialchars((string) $minted['name'], ENT_QUOTES, 'UTF-8') . '</strong></p>';
    echo '</div></div>';
}
?>
<div class="container py-3" style="max-width:960px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-tower-broadcast me-2 text-primary"></i>Webhooks</h1>
    <p class="text-muted small">
        Outbound event notifications — the portal POSTs a signed JSON payload to your target URL
        whenever a subscribed event fires (comma-separated <code>app.action</code> keys, or <code>all</code>).
        Verify authenticity via the <code>X-Webhook-Signature</code> header
        (<code>sha256=hex(hmac_sha256(body, signingSecret))</code>). The signing secret is shown
        ONCE at creation — copy it then.
    </p>

    <details class="mb-4" <?php echo count($webhooks) === 0 ? 'open' : ''; ?>>
        <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Create a webhook</summary>
        <form method="post" action="/admin/integrations/webhooks/save" class="row g-2 mt-2 p-3 bg-light rounded">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <div class="col-md-4">
                <label class="form-label small">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" required maxlength="100" class="form-control form-control-sm" placeholder="e.g. Zapier — new prayer requests">
            </div>
            <div class="col-md-5">
                <label class="form-label small">Target URL <span class="text-danger">*</span></label>
                <input type="url" name="targetUrl" required maxlength="500" class="form-control form-control-sm" placeholder="https://example.com/hooks/webms">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Event types</label>
                <input type="text" name="eventTypes" maxlength="500" class="form-control form-control-sm" value="all" placeholder="prayer-requests.created, expenses.approved">
                <div class="form-text small">CSV of <code>app.action</code> keys, or <code>all</code>.</div>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="webhookActive" name="isActive" value="1" checked>
                    <label class="form-check-label small" for="webhookActive">Active immediately</label>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Create webhook</button>
            </div>
        </form>
    </details>

    <?php if (count($webhooks) === 0): ?>
        <div class="alert alert-info small">No webhooks configured yet.</div>
    <?php else: ?>
        <div class="portal-data-list mb-4">
        <?php foreach ($webhooks as $w):
            $isActive      = (int) $w['isActive'] === 1;
            $targetUrl     = (string) $w['targetUrl'];
            $targetDisplay = mb_strlen($targetUrl) > 60 ? mb_substr($targetUrl, 0, 57) . '…' : $targetUrl;
        ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $w['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ($isActive === true): ?>
                        <span class="badge bg-success ms-1">Active</span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-1">Paused</span>
                    <?php endif; ?>
                    <div class="small text-muted">
                        <code title="<?php echo htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($targetDisplay, ENT_QUOTES, 'UTF-8'); ?></code>
                        <br>
                        events: <code><?php echo htmlspecialchars((string) $w['eventTypes'], ENT_QUOTES, 'UTF-8'); ?></code>
                        &middot;
                        created <?php echo htmlspecialchars(date('j M Y', strtotime((string) $w['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (empty($w['creatorName']) === false): ?>
                            by <?php echo htmlspecialchars((string) $w['creatorName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                        <?php if (empty($w['lastDeliveryAt']) === false): ?>
                            <br>last delivery <?php echo htmlspecialchars(date('j M, H:i', strtotime((string) $w['lastDeliveryAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <br><span class="text-muted">no deliveries yet</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="portal-data-row-aside">
                    <form method="post" action="/admin/integrations/webhooks/save" class="d-inline" onsubmit="return confirm(<?php echo $isActive === true ? "'Pause this webhook?'" : "'Reactivate this webhook?'"; ?>);">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="webhookID" value="<?php echo (int) $w['webhookID']; ?>">
                        <input type="hidden" name="isActive" value="<?php echo $isActive === true ? '0' : '1'; ?>">
                        <?php if ($isActive === true): ?>
                            <button class="btn btn-sm btn-outline-warning" title="Pause"><i class="fa-solid fa-pause"></i></button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-success" title="Reactivate"><i class="fa-solid fa-play"></i></button>
                        <?php endif; ?>
                    </form>
                    <form method="post" action="/admin/integrations/webhooks/delete" class="d-inline" onsubmit="return confirm('Delete this webhook? Cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="webhookID" value="<?php echo (int) $w['webhookID']; ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (count($deliveries) > 0): ?>
        <h2 class="h5 mt-4 mb-2"><i class="fa-solid fa-clock-rotate-left me-2 text-secondary"></i>Recent deliveries</h2>
        <div class="portal-data-list">
        <?php foreach ($deliveries as $d):
            $status = (string) $d['status'];
            if ($status === 'delivered') {
                $badgeClass = 'bg-success';
            } elseif ($status === 'failed') {
                $badgeClass = 'bg-danger';
            } elseif ($status === 'dead') {
                $badgeClass = 'bg-dark';
            } else {
                $badgeClass = 'bg-secondary';
            }
        ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $d['webhookName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    &middot; <code><?php echo htmlspecialchars((string) $d['eventType'], ENT_QUOTES, 'UTF-8'); ?></code>
                    <span class="badge <?php echo $badgeClass; ?> ms-1"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                    <div class="small text-muted">
                        attempt <?php echo (int) $d['attemptCount']; ?>
                        <?php if (empty($d['responseCode']) === false): ?>
                            &middot; HTTP <?php echo (int) $d['responseCode']; ?>
                        <?php endif; ?>
                        <?php if (empty($d['lastAttemptAt']) === false): ?>
                            &middot; last attempt <?php echo htmlspecialchars(date('j M, H:i', strtotime((string) $d['lastAttemptAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                        &middot; queued <?php echo htmlspecialchars(date('j M, H:i', strtotime((string) $d['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
