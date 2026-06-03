<?php
// Path: public_html/admin/integrations/zoom/index.php
/**
 * Admin — Zoom integration settings + org-level connect.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/274
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

$db       = App::db();
$siteId   = Site::id();
$settings = App::settings()['zoom'] ?? [];
$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$mode     = (string) ($settings['mode'] ?? 'org');
$clientId = (string) ($settings['clientID'] ?? '');
$hasSecret = ((string) ($settings['clientSecret'] ?? '')) !== '';
$hasWebhook = ((string) ($settings['webhookSecret'] ?? '')) !== '';

$orgAccount = null;
$stmt = $db->prepare('SELECT accountID, zoomUserId, zoomAccountEmail, accessTokenExpiresAt, updatedAt FROM tblZoomAccount WHERE siteID = ? AND userID IS NULL LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $orgAccount = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$userAccounts = [];
$stmt = $db->prepare(
    'SELECT z.accountID, z.zoomAccountEmail, z.updatedAt, u.fullName, u.userID '
    . 'FROM tblZoomAccount z INNER JOIN tblUsers u ON u.userID = z.userID '
    . 'WHERE z.siteID = ? AND z.userID IS NOT NULL ORDER BY z.updatedAt DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $userAccounts[] = $r;
    }
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = Auth::csrfToken();

$pageTitle   = 'Zoom Integration';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Integrations' => '/admin/integrations', 'Zoom' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-video me-2"></i>Zoom Integration</h1>
<p class="text-secondary">Create Zoom meetings from calendar events; auto-link recordings via webhook.</p>

<div class="card mb-4">
    <div class="card-header"><strong>OAuth credentials</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/integrations/zoom/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-6">
                <label class="form-label">Client ID</label>
                <input type="text" name="clientID" class="form-control" value="<?php echo htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Client Secret <?php echo $hasSecret === true ? '<span class="badge bg-success">configured</span>' : '<span class="badge bg-secondary">not set</span>'; ?></label>
                <input type="password" name="clientSecret" class="form-control" placeholder="<?php echo $hasSecret === true ? 'Leave blank to keep current' : ''; ?>" autocomplete="off">
            </div>
            <div class="col-md-6">
                <label class="form-label">Webhook secret token <?php echo $hasWebhook === true ? '<span class="badge bg-success">configured</span>' : '<span class="badge bg-secondary">not set</span>'; ?></label>
                <input type="password" name="webhookSecret" class="form-control" placeholder="<?php echo $hasWebhook === true ? 'Leave blank to keep current' : ''; ?>" autocomplete="off">
                <small class="text-muted">Webhook URL: <code><?php echo htmlspecialchars((($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/admin/integrations/zoom/webhook', ENT_QUOTES, 'UTF-8'); ?></code></small>
            </div>
            <div class="col-md-3">
                <label class="form-label">Account mode</label>
                <select name="mode" class="form-select">
                    <option value="org"  <?php echo $mode === 'org'  ? 'selected' : ''; ?>>Org-level (single account for all)</option>
                    <option value="user" <?php echo $mode === 'user' ? 'selected' : ''; ?>>Per-user (each member connects)</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="zoomEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="zoomEnabled">Enable Zoom app</label>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save credentials</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Org account</strong></div>
    <div class="card-body">
        <?php if ($orgAccount !== null): ?>
            <p class="mb-2">
                <i class="fa-solid fa-check-circle text-success me-1"></i>
                Connected: <strong><?php echo htmlspecialchars((string) ($orgAccount['zoomAccountEmail'] ?? $orgAccount['zoomUserId']), ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="small text-muted ms-2">last refresh: <?php echo htmlspecialchars((string) $orgAccount['updatedAt'], ENT_QUOTES, 'UTF-8'); ?></span>
            </p>
            <form method="post" action="/admin/integrations/zoom/disconnect" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="accountID" value="<?php echo (int) $orgAccount['accountID']; ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Disconnect the org Zoom account?">Disconnect</button>
            </form>
        <?php elseif ($clientId === '' || $hasSecret === false): ?>
            <p class="text-muted mb-0">Configure Client ID + Secret above before connecting.</p>
        <?php else: ?>
            <a href="/admin/integrations/zoom/connect" class="btn btn-primary"><i class="fa-solid fa-link me-1"></i>Connect Zoom account</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($mode === 'user' && count($userAccounts) > 0): ?>
    <div class="card mb-4">
        <div class="card-header"><strong>Connected user accounts</strong></div>
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($userAccounts as $ua): ?>
                    <div class="row py-2 border-bottom">
                        <div class="col-md-4"><strong><?php echo htmlspecialchars((string) $ua['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="col-md-5 small text-muted"><?php echo htmlspecialchars((string) ($ua['zoomAccountEmail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-3 small text-muted text-end"><?php echo htmlspecialchars((string) $ua['updatedAt'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
