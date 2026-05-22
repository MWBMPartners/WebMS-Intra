<?php
// Path: public_html/admin/email-templates/edit.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Email Template editor 📨
 * -----------------------------------------------------------------------------
 * Edit a single template by key. Shows BOTH the global default (read-only)
 * AND the per-site override (editable). Saving creates/updates the site
 * override; clearing reverts to the global default.
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

$pageTitle   = 'Edit email template';
$pageSection = 'admin';
$breadcrumbs = [
    'Dashboard'       => '/',
    'Admin'           => '/admin',
    'Email Templates' => '/admin/email-templates',
    'Edit'            => '',
];

$siteId = Site::id();
$key    = trim((string) ($_GET['key'] ?? ''));
if ($key === '' || preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key) !== 1) {
    header('Location: /admin/email-templates', true, 302);
    exit();
}

// 🔍 Load global default + site override (if any) in one query
$rows = [];
$stmt = $mysqli->prepare(
    'SELECT templateID, siteID, templateKey, subject, bodyHtml, description, availableTokens '
    . 'FROM tblEmailTemplates WHERE templateKey = ? AND (siteID IS NULL OR siteID = ?)'
);
if ($stmt !== false) {
    $stmt->bind_param('si', $key, $siteId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

if (count($rows) === 0) {
    header('Location: /admin/email-templates?missing=1', true, 302);
    exit();
}

$global = null;
$site   = null;
foreach ($rows as $r) {
    if ($r['siteID'] === null) {
        $global = $r;
    } else {
        $site = $r;
    }
}

// Prefer the override for the editor (or fall back to global as the base)
$editable = $site ?? $global;

$flashMsg  = $_SESSION['email_template_flash'] ?? '';
$flashType = $_SESSION['email_template_flash_type'] ?? '';
unset($_SESSION['email_template_flash'], $_SESSION['email_template_flash_type']);

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-envelope me-2"></i>Edit template</h1>
    <a href="/admin/email-templates" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to list
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<p class="text-muted">
    Key: <code><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></code>
    &middot;
    Status:
    <?php if ($site !== null): ?>
        <span class="badge bg-primary">Site override active</span>
    <?php else: ?>
        <span class="badge bg-secondary">Using global default</span>
    <?php endif; ?>
</p>

<form method="post" action="/admin/email-templates/save">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="templateKey" value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="mb-3">
                <label for="subject" class="form-label">Subject</label>
                <input type="text" class="form-control" id="subject" name="subject" required
                       maxlength="255"
                       value="<?php echo htmlspecialchars((string) $editable['subject'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="mb-3">
                <label for="bodyHtml" class="form-label">HTML body</label>
                <textarea class="form-control font-monospace" id="bodyHtml" name="bodyHtml"
                          rows="14" required style="font-size:0.85rem;"><?php echo htmlspecialchars((string) $editable['bodyHtml'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <?php if ($editable['availableTokens'] !== null && $editable['availableTokens'] !== ''): ?>
                <p class="small text-muted mb-0">
                    Available tokens:
                    <?php foreach (explode(',', (string) $editable['availableTokens']) as $tok): ?>
                        <code class="me-2">{{<?php echo htmlspecialchars(trim($tok), ENT_QUOTES, 'UTF-8'); ?>}}</code>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-success">
                <i class="fa-solid fa-save me-1"></i> Save (site override)
            </button>
            <button type="submit" name="action" value="preview" formaction="/admin/email-templates/preview"
                    formtarget="_blank" class="btn btn-outline-secondary">
                <i class="fa-solid fa-eye me-1"></i> Preview in new tab
            </button>
            <?php if ($site !== null): ?>
                <button type="submit" name="action" value="revert" class="btn btn-outline-danger ms-auto"
                        onclick="return confirm('Remove this site\'s override and revert to the global default?');">
                    <i class="fa-solid fa-rotate-left me-1"></i> Revert to global default
                </button>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if ($global !== null && $site !== null): ?>
    <details class="card shadow-sm mb-3">
        <summary class="card-header text-muted">Compare with global default (read-only)</summary>
        <div class="card-body">
            <h3 class="h6">Global subject</h3>
            <pre class="small bg-body-tertiary p-2 rounded"><?php echo htmlspecialchars((string) $global['subject'], ENT_QUOTES, 'UTF-8'); ?></pre>
            <h3 class="h6">Global HTML body</h3>
            <pre class="small bg-body-tertiary p-2 rounded" style="white-space: pre-wrap; word-break: break-word;"><?php echo htmlspecialchars((string) $global['bodyHtml'], ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>
    </details>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
