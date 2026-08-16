<?php
// Path: _apps/assets/found-reports.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Lost-and-Found Reports (admin triage) 📦🔍
 * -----------------------------------------------------------------------------
 * Manager-only triage queue for public "I found this" submissions
 * (`_apps/assets/tag.php` → `_apps/assets/found-save.php` →
 * `AssetRegister::createFoundReport()`). Self-posting (GET renders + filters,
 * POST mutates status), mirroring `categories.php`'s/`orgs.php`'s established
 * house pattern for this shape of small list+action screen — see that file's
 * own header for the precedent.
 *
 * GATE: `Auth::requireLogin()` PLUS admin/asset_manager — same manager gate
 * as every other mutating Asset Tracker screen (`save.php`, `owners-save.php`,
 * …). Unlike item.php's Loans/Maintenance panels, this queue has NO
 * `isResponsibleFor()` bypass — a found-report can name reporter contact
 * details that were never meant for a wider audience than the people this
 * site trusts to run the Asset Tracker programme, so it's manager-only, full
 * stop (mirrors `owners-save.php`'s own "management-only, not extended to
 * owner-parties" rationale).
 *
 * XSS: every attacker-supplied field this queue renders — reporterName,
 * reporterContact, message — is `htmlspecialchars(ENT_QUOTES, 'UTF-8')`'d on
 * output, same as every other admin view in this codebase that renders
 * public-submission content (mirrors `prayer-requests/moderate.php`'s own
 * treatment of anonymous submitter fields).
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/401
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — admins or the asset_manager role only. See file header.
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// -----------------------------------------------------------------------------
// 🔁 POST — status-change actions only. GET below handles the list + filters.
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 CSRF FIRST — before any side-effect, per house convention.
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /assets/found-reports');
        exit();
    }

    $reportId = (int) ($_POST['reportID'] ?? 0);
    $newStatus = (string) ($_POST['status'] ?? '');

    $ok = $reportId > 0
        ? AssetRegister::setFoundReportStatus($reportId, $siteId, $newStatus, $userId)
        : false;

    $_SESSION['flash_msg']  = $ok === true
        ? 'Report updated.'
        : 'Could not update that report — it may no longer exist, or the status was invalid.';
    $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';

    header('Location: /assets/found-reports');
    exit();
}

// -----------------------------------------------------------------------------
// 📋 GET — list + optional filters (status, assetID — the latter used by
// item.php's "Found-item reports" link so a manager can jump straight to
// one asset's own history).
// -----------------------------------------------------------------------------
$statusFilter  = (string) ($_GET['status'] ?? '');
$assetIdFilter = (int) ($_GET['assetID'] ?? 0);

$filters = [];
if (in_array($statusFilter, ['new', 'actioned', 'closed'], true) === true) {
    $filters['status'] = $statusFilter;
}
if ($assetIdFilter > 0) {
    $filters['assetID'] = $assetIdFilter;
}

$reports = AssetRegister::listFoundReports($siteId, $filters);

$csrf = Auth::csrfToken();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$statusBadge = [
    'new'      => 'danger',
    'actioned' => 'warning',
    'closed'   => 'success',
];

$pageTitle   = 'Lost-and-Found Reports';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Lost-and-Found Reports' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-1"><i class="fa-solid fa-magnifying-glass-location me-2"></i>Lost-and-found reports</h1>
<p class="text-secondary mb-4">
    Public "I found this" submissions from asset lost-and-found pages (<code>/a/&hellip;</code>).
    Reporter details below were volunteered by an anonymous member of the public — handle with the
    same care as any other unsolicited personal data.
</p>

<!-- 🔍 Filters -->
<form method="get" action="/assets/found-reports" class="row g-2 mb-4 align-items-end">
    <?php if ($assetIdFilter > 0): ?>
        <input type="hidden" name="assetID" value="<?php echo $assetIdFilter; ?>">
    <?php endif; ?>
    <div class="col-auto">
        <label class="form-label small" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status">
            <option value="">All</option>
            <option value="new"<?php echo $statusFilter === 'new' ? ' selected' : ''; ?>>New</option>
            <option value="actioned"<?php echo $statusFilter === 'actioned' ? ' selected' : ''; ?>>Actioned</option>
            <option value="closed"<?php echo $statusFilter === 'closed' ? ' selected' : ''; ?>>Closed</option>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-filter me-1"></i>Filter</button>
        <a href="/assets/found-reports" class="btn btn-outline-secondary btn-sm">Clear</a>
    </div>
</form>

<?php if (count($reports) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>No found-item reports match these filters.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <?php foreach ($reports as $report): ?>
            <?php
            $reportAssetId = (int) $report['assetID'];
            $status        = (string) $report['status'];
            // 🛡️ Every attacker-supplied field below is escaped at the
            // point of output (see file header's XSS note) — never trust
            // a public submission's reporterName/reporterContact/message.
            ?>
            <div class="portal-data-row align-items-start">
                <div class="col-12 col-md-3">
                    <a href="/assets/item?id=<?php echo $reportAssetId; ?>" class="text-decoration-none">
                        <strong><?php echo htmlspecialchars((string) $report['assetName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </a>
                    <br><small class="text-muted"><?php echo htmlspecialchars((string) $report['createdAt'], ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <div class="col-6 col-md-3">
                    <?php echo $report['reporterName'] !== null
                        ? htmlspecialchars((string) $report['reporterName'], ENT_QUOTES, 'UTF-8')
                        : '<span class="text-muted">No name given</span>'; ?>
                    <?php if ($report['reporterContact'] !== null && (string) $report['reporterContact'] !== ''): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars((string) $report['reporterContact'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-6 col-md-4">
                    <?php if ($report['message'] !== null && (string) $report['message'] !== ''): ?>
                        <?php echo nl2br(htmlspecialchars((string) $report['message'], ENT_QUOTES, 'UTF-8')); ?>
                    <?php else: ?>
                        <span class="text-muted">No message.</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-2 text-md-end">
                    <span class="badge bg-<?php echo htmlspecialchars($statusBadge[$status] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?> mb-1">
                        <?php echo htmlspecialchars(ucwords($status), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <br>
                    <?php if ($status !== 'actioned'): ?>
                        <form method="post" action="/assets/found-reports" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="reportID" value="<?php echo (int) $report['reportID']; ?>">
                            <input type="hidden" name="status" value="actioned">
                            <button type="submit" class="btn btn-sm btn-outline-warning mb-1" title="Mark actioned">
                                <i class="fa-solid fa-clipboard-check"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($status !== 'closed'): ?>
                        <form method="post" action="/assets/found-reports" class="d-inline"
                              data-confirm="Close this report? It stays in the log, but drops off the default queue view." >
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="reportID" value="<?php echo (int) $report['reportID']; ?>">
                            <input type="hidden" name="status" value="closed">
                            <button type="submit" class="btn btn-sm btn-outline-success mb-1" title="Close">
                                <i class="fa-solid fa-check-double"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="mt-3"><a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a></p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
