<?php
// Path: _apps/assets/index.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Register Index 📦
 * -----------------------------------------------------------------------------
 * Lists non-deleted assets for the active site via
 * Portal\Core\AssetRegister::listForSite(). The "New asset" button and every
 * row link now point at real handlers (#394 — _apps/assets/edit.php,
 * _apps/assets/item.php); ownership/loans/maintenance/identifiers/licence
 * seats/labels still arrive in later sub-issues (see item.php's placeholder
 * cards).
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\I18n;
use Portal\Core\Site;

// 🔐 Every Asset Tracker page requires an authenticated session — this app
// has no anonymous view (the ONLY public surface is the per-asset lost-and-
// found page at /a/{token}, handled entirely by _apps/assets/tag.php).
Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();

// 🛡️ "New asset" + management affordances are gated to admins or the
// asset_manager role — mirrors the app/settingKey convention used across
// every other app (see .claude/CLAUDE.md → Code Style).
$canManage = App::isAdmin() === true || App::hasRole('asset_manager') === true;

// 📋 Optional status filter via querystring — kept intentionally simple for
// the foundation pass; AssetRegister::listForSite() supports more filters
// for later sub-issues to build on.
$statusFilter = (string) ($_GET['status'] ?? '');
$filters = [];
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}

$assets = AssetRegister::listForSite($siteId, $filters, $canManage);

// 📌 Page metadata
$pageTitle   = 'Asset Tracker';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

// 🎨 Status → badge colour, condition → badge colour. Kept local to this
// page — a shared helper can move into AssetRegister once more pages need
// the same mapping.
$statusBadge = [
    'in-service' => 'success',
    'in-repair'  => 'warning',
    'on-loan'    => 'info',
    'borrowed'   => 'info',
    'in-storage' => 'secondary',
    'retired'    => 'secondary',
    'disposed'   => 'dark',
    'lost'       => 'danger',
    'stolen'     => 'danger',
];
$kindIcon = ['physical' => 'fa-box', 'digital' => 'fa-cloud'];
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-boxes-stacked me-2"></i><?php echo htmlspecialchars(I18n::t('assets.title'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-secondary mb-0"><?php echo htmlspecialchars(I18n::t('assets.subtitle'), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php if ($canManage === true): ?>
        <div>
            <a href="/assets/edit" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-plus me-1"></i><?php echo htmlspecialchars(I18n::t('assets.new_asset'), ENT_QUOTES, 'UTF-8'); ?>
            </a>
        </div>
    <?php endif; ?>
</div>

<?php if (count($assets) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>
        <?php echo htmlspecialchars(I18n::t('assets.empty_state'), ENT_QUOTES, 'UTF-8'); ?>
        <?php if ($canManage === true): ?>
            <a href="/assets/edit">Add the first one &rarr;</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <?php foreach ($assets as $asset): ?>
            <?php
            $status      = (string) $asset['status'];
            $badgeColor  = $statusBadge[$status] ?? 'secondary';
            $kind        = (string) $asset['assetKind'];
            $icon        = $kindIcon[$kind] ?? 'fa-box';
            $categoryName = $asset['categoryName'] !== null ? (string) $asset['categoryName'] : null;
            $locationName = $asset['locationName'] !== null ? (string) $asset['locationName'] : null;
            ?>
            <div class="portal-data-row align-items-center">
                <div class="col-8 col-md-5">
                    <i class="fa-solid <?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?> me-2 text-muted"></i>
                    <a href="/assets/item?id=<?php echo (int) $asset['assetID']; ?>" class="text-decoration-none">
                        <strong><?php echo htmlspecialchars((string) $asset['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </a>
                    <?php if ((int) $asset['isConfidential'] === 1): ?>
                        <span class="badge bg-secondary ms-1" title="Hidden from the public lost-and-found page">
                            <i class="fa-solid fa-lock"></i>
                        </span>
                    <?php endif; ?>
                    <?php if ($categoryName !== null || $locationName !== null): ?>
                        <br><small class="text-muted">
                            <?php echo htmlspecialchars(trim(($categoryName ?? '') . ($categoryName !== null && $locationName !== null ? ' · ' : '') . ($locationName ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div class="col-4 col-md-2 text-md-start">
                    <span class="badge bg-<?php echo htmlspecialchars($badgeColor, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $status)), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-6 col-md-3 small text-muted d-none d-md-block">
                    <?php echo $asset['serialNumber'] !== null ? htmlspecialchars((string) $asset['serialNumber'], ENT_QUOTES, 'UTF-8') : '&mdash;'; ?>
                </div>
                <div class="col-6 col-md-2 text-end">
                    <a href="/assets/item?id=<?php echo (int) $asset['assetID']; ?>" class="btn btn-sm btn-outline-primary" title="View">
                        <i class="fa-solid fa-eye"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
