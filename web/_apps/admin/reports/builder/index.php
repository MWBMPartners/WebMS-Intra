<?php
// Path: _apps/admin/reports/builder/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Reports Builder: saved report list 📊 (#156)
 * -----------------------------------------------------------------------------
 * Lists every report definition visible to the caller on this site
 * (isShared=1 OR authored by the caller — Ambiguity A5: "public" means
 * every SITE ADMIN of this site, since the whole area is admin-gated, not
 * every logged-in member). Actions: Run / Edit / Delete (author or site
 * admin only — enforced by the individual handlers, not just hidden here).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/156
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\ReportBuilder;
use Portal\Core\ReportRegistry;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Admin only
if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = t('error.access_denied_inline');
    $_SESSION['flash_type'] = 'danger';
    header('Location: /dashboard');
    exit();
}

// 🛡️ Belt-and-braces app-enabled re-check (Router already gates every
// admin/reports/* path via AppRegistry::appForRoute() prefix match).
if (AppRegistry::isEnabled('reports') === false) {
    $_SESSION['flash_msg']  = 'Reports is disabled for this site.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/apps');
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$reports = ReportBuilder::listForSite($siteId, $userId);

// 📖 Registry labels for display — source key -> human label.
$sourceLabels = [];
foreach (ReportRegistry::availableSources() as $key => $meta) {
    $sourceLabels[$key] = (string) $meta['label'];
}

$pageTitle   = 'Report Builder';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Reports' => '/admin/reports', 'Builder' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📊 Reports Builder -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-table-list me-2"></i>Report Builder</h1>
        <p class="text-secondary mb-0">Custom reports built from a whitelist of safe data sources — never free-form SQL.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/admin/reports" class="btn btn-outline-secondary">
            <i class="fa-solid fa-chart-bar me-1"></i>Dashboards
        </a>
        <a href="/admin/reports/builder/edit" class="btn btn-primary">
            <i class="fa-solid fa-plus me-1"></i>New report
        </a>
    </div>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if (count($reports) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-info-circle me-2"></i>No saved reports yet. Click <strong>New report</strong> to build one.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-3">Name</div>
            <div class="col-2">Source</div>
            <div class="col-1">Shared</div>
            <div class="col-2">Last run</div>
            <div class="col-1">Runs</div>
            <div class="col-3 text-end">Actions</div>
        </div>
        <?php foreach ($reports as $r): ?>
            <?php
            $rid       = (int) $r['reportID'];
            $srcKey    = (string) $r['sourceKey'];
            $srcLabel  = $sourceLabels[$srcKey] ?? $srcKey;
            $isOwner   = ((int) ($r['createdByID'] ?? 0)) === $userId;
            $canManage = $isOwner === true || App::isSiteAdmin() === true;
            ?>
            <div class="portal-data-row">
                <div class="col-3">
                    <strong><?php echo htmlspecialchars($r['reportName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if (($r['description'] ?? '') !== ''): ?>
                        <div class="small text-muted"><?php echo htmlspecialchars((string) $r['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-2"><span class="badge bg-secondary"><?php echo htmlspecialchars($srcLabel, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="col-1">
                    <?php if ((int) $r['isShared'] === 1): ?>
                        <span class="badge bg-info"><i class="fa-solid fa-users me-1"></i>Shared</span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
                <div class="col-2 small text-muted">
                    <?php echo $r['lastRunAt'] !== null ? htmlspecialchars((string) $r['lastRunAt'], ENT_QUOTES, 'UTF-8') : 'Never'; ?>
                </div>
                <div class="col-1"><?php echo (int) $r['runCount']; ?></div>
                <div class="col-3 text-end">
                    <a href="/admin/reports/builder/run?id=<?php echo $rid; ?>" class="btn btn-sm btn-outline-primary" title="Run">
                        <i class="fa-solid fa-play"></i>
                    </a>
                    <?php if ($canManage === true): ?>
                        <a href="/admin/reports/builder/edit?id=<?php echo $rid; ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                            <i class="fa-solid fa-pen"></i>
                        </a>
                        <form method="post" action="/admin/reports/builder/delete" class="d-inline"
                              data-confirm="Delete the report &quot;<?php echo htmlspecialchars($r['reportName'], ENT_QUOTES, 'UTF-8'); ?>&quot;? This cannot be undone."
                              data-confirm-destructive="true">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="reportID" value="<?php echo $rid; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
