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
use Portal\Core\WebhookPorts;
use Portal\Core\WebhookRetention;

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
    'SELECT d.deliveryID, d.eventType, d.status, d.attemptCount, d.responseCode, d.responseSnippet, '
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

// 🏷️ Why a delivery did not succeed, in words (#615). `responseSnippet` now
//    holds ONE word from `SafeFetch::POST_REASONS`. Only those words are ever
//    shown, through this fixed list: anything else in the column — a reply a
//    receiver sent before #615, which the nightly clear-out empties in time —
//    is NEVER printed, escaped or not. The other server's words are the one
//    thing a request aimed somewhere it should not go is trying to read back.
$reasonWords = [
    'refused'     => 'the address was refused: it points at a private or internal network, its name could not be found, '
        . 'or the connection went somewhere other than the address that was checked',
    // #615 round 1: kept apart from `refused`, which would send somebody whose
    // webhook has `:8443` or a password in it to go and look at their name server.
    'not-allowed' => 'the address is written in a way that is not allowed: a port number this installation does not '
        . 'allow, a user name or password inside it (no longer sent), a scheme other than http or https, or an '
        . 'unusual spelling of a number',
    'too-slow'    => 'the other server took too long to answer',
    'unreachable' => 'the other server could not be reached',
    'http-status' => 'the other server answered with an error',
    'no-curl'     => 'this server cannot send to outside addresses (the PHP curl extension is missing)',
];

// 🍞 Generic flash (create/update/delete redirects land here)
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'Webhooks';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Integrations' => Site::url('admin/integrations'), 'Webhooks' => ''];
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

    <?php
    // 🧾 How long a payload is kept (#713).
    //
    //    WHY A FIGURE HERE AT ALL. Both payload columns held a webhook's whole
    //    body for ever, and nothing removed either: an incoming payment event
    //    routinely carries a donor's name, e-mail address and postal address,
    //    and an outgoing notification demonstrably carries a child's name, a
    //    raw e-mail address or a prayer-request subject. Neither table has a
    //    column that links a row to a person, so an erasure request cannot
    //    reach them and never could. A time limit is the only mechanism there
    //    is.
    //
    //    🪤 THE ORGANISATION'S BOX IS FILLED FROM ITS OWN ROW, NOT FROM THE
    //       MERGED SETTINGS (#674). The merged array already holds the
    //       installation's value where this organisation has chosen nothing, so
    //       a box filled from it would show the installation's figure and a
    //       Save that touched nothing would pin it as this organisation's own.
    //    🪤 AND THE INSTALLATION'S BOX IS FILLED FROM THE LAYER, never from the
    //       merged array, for the mirror-image reason (#677).
    $ownRow = null;
    $stmtR  = $mysqli->prepare(
        'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = ? LIMIT 1'
    );
    if ($stmtR !== false) {
        $keyR = WebhookRetention::KEY;
        $stmtR->bind_param('si', $keyR, $siteId);
        $stmtR->execute();
        $rowR   = $stmtR->get_result()->fetch_assoc();
        $ownRow = $rowR === null ? null : (string) $rowR['settingValue'];
        $stmtR->close();
    }
    $ceiling      = WebhookRetention::installationAllows();
    $forEverOk    = WebhookRetention::installationAllowsForEver();
    $inForce      = WebhookRetention::daysForThisOrganisation();
    $isGlobalAdm  = App::isRootAdmin();
    $instOwn      = WebhookRetention::installationAllows();

    // 🪤 NEVER PREFILL A VALUE THE BOX'S OWN `max` FORBIDS (#668): a browser
    //    then refuses to submit the whole form, and the only way to use the
    //    page is to shorten the period. The box's max is the ceiling, so a
    //    stored figure above it is shown as the ceiling.
    $ownShown = $ownRow === null ? '' : (string) min((int) $ownRow, $ceiling === 0 ? WebhookRetention::HARD_MAX_DAYS : $ceiling);
    if ($ownRow !== null && (int) $ownRow === 0 && $forEverOk === true) {
        $ownShown = '0';
    }
    ?>
    <details class="mb-4">
        <summary class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-clock-rotate-left me-1"></i>How long payloads are kept</summary>
        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/integrations/webhooks/save'), ENT_QUOTES, 'UTF-8'); ?>" class="row g-2 mt-2 p-3 bg-light rounded">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="payload_retention">
            <div class="col-12">
                <p class="small text-secondary mb-2">
                    A webhook's payload is the event itself, so it can contain somebody's name, e-mail
                    address or postal address. Past the period below the portal <strong>empties the
                    payload and keeps the row</strong>, so your delivery history, the attempt counts and
                    the guard against an event being accepted twice are all untouched.
                    <strong>A delivery still waiting or still being retried is never emptied</strong>, because
                    the retry sends from the stored payload.
                </p>
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="ownDays">This organisation keeps payloads for</label>
                <input type="number" class="form-control form-control-sm" id="ownDays" name="ownDays"
                       min="0" max="<?php echo (int) WebhookRetention::HARD_MAX_DAYS; ?>"
                       value="<?php echo htmlspecialchars($ownShown, ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="<?php echo (int) $ceiling; ?>">
                <div class="form-text small">
                    Days. Leave empty to follow the installation<?php echo $ceiling === 0 ? '' : ' (' . (int) $ceiling . ' days)'; ?>.
                    <?php if ($forEverOk === true): ?>
                        0 means keep for ever.
                    <?php else: ?>
                        The installation does not allow "keep for ever", so 0 will be refused.
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isGlobalAdm === true): ?>
                <div class="col-md-4">
                    <label class="form-label small" for="installationDays">The installation allows at most</label>
                    <input type="number" class="form-control form-control-sm" id="installationDays" name="installationDays"
                           min="0" max="<?php echo (int) WebhookRetention::HARD_MAX_DAYS; ?>"
                           value="<?php echo (int) $instOwn; ?>">
                    <div class="form-text small">
                        Days, for every organisation on this installation. An organisation may choose
                        less and can never choose more. 0 means "for ever is allowed".
                    </div>
                </div>
            <?php endif; ?>
            <div class="col-12">
                <button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i>Save</button>
                <span class="small text-secondary ms-2">
                    In force here:
                    <?php echo $inForce === 0 ? 'kept for ever' : (int) $inForce . ' days'; ?>.
                </span>
            </div>
        </form>
    </details>

    <?php
    // 🚪 Which port numbers a webhook may use (#615, the owner's decision
    //    D-102). An INSTALLATION setting: shown to everybody so an ordinary
    //    administrator knows the rule, changeable by a global administrator
    //    only, and read from the installation's row alone.
    $anyPortNow = WebhookPorts::allowsAnyPort();
    ?>
    <div class="small text-secondary mb-3" id="webhook-ports">
        Port numbers: <?php echo $anyPortNow === true
            ? 'any port on a public address is allowed on this installation'
            : 'only the standard ports (443 for https, 80 for http) are allowed on this installation'; ?>.
        Addresses on a private or internal network are always refused.
        <?php if ($isGlobalAdm === true): ?>
            <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/integrations/webhooks/save'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline ms-1">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="webhook_ports">
                <input type="hidden" name="anyPort" value="<?php echo $anyPortNow === true ? '0' : '1'; ?>">
                <button class="btn btn-sm btn-outline-secondary"><?php echo $anyPortNow === true
                    ? 'Allow only the standard ports'
                    : 'Allow any port on a public address'; ?></button>
            </form>
        <?php endif; ?>
    </div>

    <details class="mb-4" <?php echo count($webhooks) === 0 ? 'open' : ''; ?>>
        <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Create a webhook</summary>
        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/integrations/webhooks/save'), ENT_QUOTES, 'UTF-8'); ?>" class="row g-2 mt-2 p-3 bg-light rounded">
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
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/integrations/webhooks/save'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline" data-confirm="<?php echo $isActive === true ? 'Pause this webhook?' : 'Reactivate this webhook?'; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="webhookID" value="<?php echo (int) $w['webhookID']; ?>">
                        <input type="hidden" name="isActive" value="<?php echo $isActive === true ? '0' : '1'; ?>">
                        <?php if ($isActive === true): ?>
                            <button class="btn btn-sm btn-outline-warning" title="Pause"><i class="fa-solid fa-pause"></i></button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-success" title="Reactivate"><i class="fa-solid fa-play"></i></button>
                        <?php endif; ?>
                    </form>
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/integrations/webhooks/delete'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline" data-confirm="Delete this webhook? Cannot be undone." data-confirm-destructive="true">
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
                        <?php
                        // Only a word from the fixed list above; never the column's own text.
                        $reasonKey = (string) ($d['responseSnippet'] ?? '');
                        if ($status !== 'delivered' && isset($reasonWords[$reasonKey]) === true): ?>
                            <br>why: <?php echo htmlspecialchars($reasonWords[$reasonKey], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
