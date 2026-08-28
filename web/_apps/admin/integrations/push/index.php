<?php
// Path: _apps/admin/integrations/push/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Web Push configuration 🔔🔑 (#322)
 * -----------------------------------------------------------------------------
 * Owner-facing setup page for VAPID (RFC 8292) + RFC 8291 Web Push. Exactly
 * the same "INERT until configured" pattern as PayPal/Cloudflare Stream:
 * `WebPush::isConfigured()` gates every send path AND the client bootstrap
 * (no public key ⇒ no subscribe button anywhere), so this page's banner is
 * the single source of truth for "is this doing anything yet".
 *
 * The VAPID private key is NEVER re-displayed once saved — this page shows
 * only a "configured ✔" badge and a SHA-256 fingerprint (first 8 hex) of
 * the PUBLIC key, exactly the precedent `admin/integrations/cloudflare-
 * stream/index.php` set for its signing key PEM.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\WebPush;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();

$settings = App::settings()['push'] ?? [];

$enabled        = (string) ($settings['enabled'] ?? 'false') === 'true';
$publicKey      = (string) ($settings['vapidPublicKey'] ?? '');
$hasPrivateKey  = ((string) ($settings['vapidPrivateKey'] ?? '')) !== '';
$contact        = (string) ($settings['contact'] ?? '');
$ttlGolive      = (string) ($settings['ttl']['golive'] ?? '900');
$ttlReminder    = (string) ($settings['ttl']['reminder'] ?? '3600');
$autoGolive     = (string) ($settings['golive']['auto'] ?? 'false') === 'true';
$broadcast      = (string) ($settings['reminders']['broadcast'] ?? 'false') === 'true';
$allowlist      = (string) ($settings['endpointHostAllowlist'] ?? '');

$isConfigured = WebPush::isConfigured();
$fingerprint  = $publicKey !== '' ? strtoupper(substr(hash('sha256', $publicKey), 0, 8)) : '';

// 📊 Subscription counts for this site — never endpoint lists, counts only
//    (§7 security checklist item 4: no enumeration surface on this page).
$counts = ['livestream' => 0, 'reminders' => 0, 'announcements' => 0, 'active' => 0, 'pruned' => 0];
$stmt = $db->prepare(
    "SELECT SUM(isActive = 1 AND channels LIKE '%\"livestream\"%') AS liveN, "
    . "SUM(isActive = 1 AND channels LIKE '%\"reminders\"%') AS remN, "
    . "SUM(isActive = 1 AND channels LIKE '%\"announcements\"%') AS annN, "
    . 'SUM(isActive = 1) AS activeN, SUM(isActive = 0) AS prunedN '
    . 'FROM tblPushSubscriptions WHERE siteID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row !== null) {
        $counts['livestream']    = (int) ($row['liveN'] ?? 0);
        $counts['reminders']     = (int) ($row['remN'] ?? 0);
        $counts['announcements'] = (int) ($row['annN'] ?? 0);
        $counts['active']        = (int) ($row['activeN'] ?? 0);
        $counts['pruned']        = (int) ($row['prunedN'] ?? 0);
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

$pageTitle   = 'Web Push Integration';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Integrations' => '/admin/integrations', 'Web Push' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-bell me-2"></i>Web Push Integration</h1>
<p class="text-secondary">
    Sends "we're live now" and service-reminder browser notifications (#322) — VAPID (RFC 8292)
    signed, end-to-end encrypted per RFC 8291. No third-party service account is required; the
    push services (Chrome/FCM, Firefox, Safari, Edge) route messages using your own key pair.
</p>

<?php if ($isConfigured === true): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
        <i class="fa-solid fa-circle-check"></i>
        <div><strong>Configured and active.</strong> Subscribers can enable push at <code>/account/notifications</code>.</div>
    </div>
<?php else: ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
            <strong>Inert.</strong> Web Push does nothing on this install yet — generate or paste a VAPID key
            pair, set a contact, and tick "Enable Web Push" below.
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><strong>VAPID key pair</strong></div>
    <div class="card-body">
        <p class="mb-2">
            Public key:
            <?php if ($publicKey !== ''): ?>
                <span class="badge bg-success">configured</span>
                <code class="ms-1">fingerprint <?php echo htmlspecialchars($fingerprint, ENT_QUOTES, 'UTF-8'); ?>…</code>
            <?php else: ?>
                <span class="badge bg-secondary">not set</span>
            <?php endif; ?>
        </p>
        <p class="mb-3">
            Private key:
            <?php if ($hasPrivateKey === true): ?>
                <span class="badge bg-success">configured (encrypted at rest)</span>
            <?php else: ?>
                <span class="badge bg-secondary">not set</span>
            <?php endif; ?>
        </p>

        <div class="row g-3">
            <div class="col-md-6">
                <form method="post" action="/admin/integrations/push/save">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="generate">
                    <button type="submit" class="btn btn-primary"
                            data-confirm="Generate a NEW VAPID key pair? Any existing subscriber (if there are any) will need to re-subscribe — this is a fresh start, not a rotation.">
                        <i class="fa-solid fa-key me-1"></i>Generate new key pair
                    </button>
                </form>
                <small class="text-muted d-block mt-1">Simplest path — one click, no OpenSSL needed.</small>
            </div>
            <div class="col-md-6">
                <form method="post" action="/admin/integrations/push/save">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="import">
                    <textarea name="privateKeyPaste" class="form-control font-monospace" rows="3" autocomplete="off"
                              placeholder="-----BEGIN EC PRIVATE KEY-----… or a bare base64url scalar"></textarea>
                    <button type="submit" class="btn btn-outline-primary mt-2">
                        <i class="fa-solid fa-file-import me-1"></i>Import pasted key
                    </button>
                </form>
                <small class="text-muted d-block mt-1">
                    Accepts a PEM from <code>openssl ecparam -name prime256v1 -genkey -noout -out vapid.pem</code>
                    (paste the file contents), or the bare private-key string some tools export standalone.
                </small>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Settings</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/integrations/push/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="save">

            <div class="col-12">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="pushEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="pushEnabled">Enable Web Push</label>
                </div>
            </div>

            <div class="col-md-8">
                <label class="form-label">Contact (RFC 8292)</label>
                <input type="text" name="contact" class="form-control font-monospace" maxlength="255"
                       value="<?php echo htmlspecialchars($contact, ENT_QUOTES, 'UTF-8'); ?>" placeholder="mailto:admin@example.org">
                <small class="text-muted">A <code>mailto:</code> or <code>https:</code> URI a push service can use to reach you if this install starts misbehaving.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label">Go-live TTL (seconds)</label>
                <input type="number" name="ttlGolive" class="form-control" min="60" max="86400"
                       value="<?php echo htmlspecialchars($ttlGolive, ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted">Default 900 (15 min) — a stale "we're live" push isn't worth delivering late.</small>
            </div>
            <div class="col-md-4">
                <label class="form-label">Reminder TTL (seconds)</label>
                <input type="number" name="ttlReminder" class="form-control" min="60" max="86400"
                       value="<?php echo htmlspecialchars($ttlReminder, ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="pushAutoGolive" name="golive_auto" value="1" <?php echo $autoGolive === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="pushAutoGolive">Auto-detect go-live (cron)</label>
                </div>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="pushBroadcast" name="reminders_broadcast" value="1" <?php echo $broadcast === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="pushBroadcast">Broadcast "starting soon" (anonymous)</label>
                </div>
            </div>

            <div class="col-12">
                <label class="form-label">Push-service host allowlist (SSRF guard)</label>
                <textarea name="endpointHostAllowlist" class="form-control font-monospace small" rows="2"><?php echo htmlspecialchars($allowlist, ENT_QUOTES, 'UTF-8'); ?></textarea>
                <small class="text-muted">Comma-separated bare hostnames. Every subscribe endpoint must suffix-match one of these — add a new browser vendor's push host here if a legitimate subscription is ever rejected.</small>
            </div>

            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save settings</button>
            </div>
        </form>

        <form method="post" action="/admin/integrations/push/test" class="mt-3 pt-3 border-top">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <button class="btn btn-outline-secondary" type="submit" <?php echo $isConfigured === false ? 'disabled' : ''; ?>>
                <i class="fa-solid fa-paper-plane me-1"></i>Send test notification to my devices
            </button>
            <small class="text-muted d-block mt-1">
                Sends to YOUR OWN active subscriptions only. Enable push at <a href="/account/notifications">/account/notifications</a> first, on the device you want to test.
            </small>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Subscriptions on this site</strong></div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="row py-2 border-bottom">
                <div class="col-6">Active — livestream channel</div>
                <div class="col-6 text-end"><?php echo $counts['livestream']; ?></div>
            </div>
            <div class="row py-2 border-bottom">
                <div class="col-6">Active — reminders channel</div>
                <div class="col-6 text-end"><?php echo $counts['reminders']; ?></div>
            </div>
            <div class="row py-2 border-bottom">
                <div class="col-6">Active — announcements channel <span class="text-muted small">(reserved, no sender yet)</span></div>
                <div class="col-6 text-end"><?php echo $counts['announcements']; ?></div>
            </div>
            <div class="row py-2 border-bottom">
                <div class="col-6">Total active subscriptions</div>
                <div class="col-6 text-end"><?php echo $counts['active']; ?></div>
            </div>
            <div class="row py-2">
                <div class="col-6">Pruned (dead endpoint — 404/410, or 8+ consecutive failures)</div>
                <div class="col-6 text-end"><?php echo $counts['pruned']; ?></div>
            </div>
        </div>
        <p class="small text-muted mt-2 mb-0">
            Counts only — endpoint URLs and keys are never listed here (they're capability
            credentials, not identifying information worth displaying).
        </p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
