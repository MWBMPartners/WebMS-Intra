<?php
// Path: public_html/admin/email-templates/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Email Templates listing 📨
 * -----------------------------------------------------------------------------
 * Lists every email template the portal knows about. Shows whether the
 * site has its own override or is using the global default.
 *
 * @package   Portal\Admin
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$pageTitle   = 'Email Templates';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Email Templates' => ''];

$siteId = Site::id();

// 📋 Fetch templates: globals + this-site overrides, then merge by key.
$rows = [];
$stmt = $mysqli->prepare(
    'SELECT templateID, siteID, templateKey, subject, description, availableTokens, isActive, updatedAt '
    . 'FROM tblEmailTemplates '
    . 'WHERE siteID IS NULL OR siteID = ? '
    . 'ORDER BY templateKey, siteID IS NOT NULL'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 🗂️ Group by templateKey — keep the site-specific override when present.
$grouped = [];
foreach ($rows as $r) {
    $key = $r['templateKey'];
    if (isset($grouped[$key]) === false || $r['siteID'] !== null) {
        $grouped[$key] = $r;
    }
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-envelope me-2"></i>Email Templates</h1>
    <a href="/admin" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Admin
    </a>
</div>

<div class="alert alert-info small">
    <i class="fa-solid fa-circle-info me-1"></i>
    Templates use <code>{{token}}</code> placeholders. The Mailer substitutes them
    at send time. Editing a global default creates a per-site override; clearing
    the override returns the template to its global default.
</div>

<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-3">Key</div>
        <div class="col-md-4">Subject</div>
        <div class="col-md-3">Tokens</div>
        <div class="col-md-2 text-end">Scope / Actions</div>
    </div>

    <?php foreach ($grouped as $tpl): ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-3">
                <code class="small"><?php echo htmlspecialchars($tpl['templateKey'], ENT_QUOTES, 'UTF-8'); ?></code>
                <?php if ($tpl['description'] !== null && $tpl['description'] !== ''): ?>
                    <div class="small text-muted"><?php echo htmlspecialchars($tpl['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-4 small">
                <?php echo htmlspecialchars($tpl['subject'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="col-12 col-md-3">
                <?php if ($tpl['availableTokens'] !== null && $tpl['availableTokens'] !== ''): ?>
                    <code class="small text-muted"><?php echo htmlspecialchars($tpl['availableTokens'], ENT_QUOTES, 'UTF-8'); ?></code>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2 text-end">
                <?php if ($tpl['siteID'] !== null): ?>
                    <span class="badge bg-primary mb-1">Site override</span>
                <?php else: ?>
                    <span class="badge bg-secondary mb-1">Global</span>
                <?php endif; ?>
                <br>
                <a class="btn btn-sm btn-outline-primary"
                   href="/admin/email-templates/edit?key=<?php echo urlencode($tpl['templateKey']); ?>">
                    <i class="fa-solid fa-pen"></i> Edit
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
