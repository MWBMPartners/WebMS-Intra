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
 * CSV EXPORT (#403) — `?export=csv` streams the SAME filtered register as a
 * CSV download instead of rendering the HTML page. Deliberately NOT a new
 * route: it's a query-string switch on this existing handler, matching the
 * house "no route sprawl for a superset of an existing view" convention
 * already used by labels.php's own filter-form. The export reuses the exact
 * same `AssetRegister::listForSite($siteId, $filters, $canManage)` call the
 * HTML branch already makes — the SAME $canManage flag both decides whether
 * the HTML page renders manager-only affordances AND whether the export
 * includes confidential assets, so a non-manager's CSV can never contain
 * more than that same viewer already sees in the browser. The CSV never
 * includes `licenseKey`, `publicToken`, or any other secret column — only
 * the plain descriptive/inventory fields listed in $csvHeaders below.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/403
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\CsvExporter;
use Portal\Core\I18n;
use Portal\Core\Logger;
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

// 📤 CSV export branch (#403) — checked BEFORE the (identical either way)
// register query below, so a failed CSRF check fails fast without ever
// touching the database. See the file header CSV EXPORT note above for the
// confidential-filter/no-secrets guarantees this branch relies on.
$isCsvExport = ($_GET['export'] ?? '') === 'csv';
if ($isCsvExport === true) {
    // 🛡️ CSRF verification via GET token — mirrors every other CSV export
    // endpoint in the codebase (leadership/export.php, attendance/export.php,
    // admin/users/export.php, admin/activity/export.php).
    if (Auth::verifyCsrf($_GET['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /assets' . ($statusFilter !== '' ? '?status=' . urlencode($statusFilter) : ''));
        exit();
    }
}

// 🔒 SAME confidential filter for both the HTML view and the CSV export —
// $canManage is the ONLY thing that decides whether confidential assets are
// included (see AssetRegister::listForSite()'s own $includeConfidential
// param), so a non-manager's export can never leak an item they couldn't
// already see on the register page itself.
$assets = AssetRegister::listForSite($siteId, $filters, $canManage);

if ($isCsvExport === true) {
    // 📊 Column allow-list — deliberately explicit rather than dumping the
    // whole $assets row, so a future column added to listForSite() never
    // silently starts appearing in an export without a conscious decision
    // here. NEVER includes licenseKey, publicToken, or any other secret —
    // see the file header note.
    $csvHeaders = [
        'Name', 'Kind', 'Category', 'Location', 'Manufacturer', 'Model',
        'Serial Number', 'Asset Tag', 'Condition', 'Status',
        'Purchase Date', 'Purchase Cost (GBP)',
    ];
    $csvRows = [];
    foreach ($assets as $asset) {
        $purchaseCostPence = $asset['purchaseCostPence'] ?? null;
        $csvRows[] = [
            'Name'                 => (string) $asset['name'],
            'Kind'                 => ucfirst((string) $asset['assetKind']),
            'Category'             => $asset['categoryName'] !== null ? (string) $asset['categoryName'] : '',
            'Location'             => $asset['locationName'] !== null ? (string) $asset['locationName'] : '',
            'Manufacturer'         => $asset['manufacturer'] !== null ? (string) $asset['manufacturer'] : '',
            'Model'                => $asset['model'] !== null ? (string) $asset['model'] : '',
            'Serial Number'        => $asset['serialNumber'] !== null ? (string) $asset['serialNumber'] : '',
            'Asset Tag'            => $asset['assetTagCode'] !== null ? (string) $asset['assetTagCode'] : '',
            'Condition'            => ucwords(str_replace('-', ' ', (string) $asset['conditionState'])),
            'Status'               => ucwords(str_replace('-', ' ', (string) $asset['status'])),
            'Purchase Date'        => $asset['purchaseDate'] !== null ? (string) $asset['purchaseDate'] : '',
            'Purchase Cost (GBP)'  => $purchaseCostPence !== null ? number_format(((int) $purchaseCostPence) / 100, 2, '.', '') : '',
        ];
    }

    // 📓 Audit trail — who exported, how many rows, under which filter.
    // Mirrors AssetRegister::audit()'s own "resolve the session user or
    // record none" convention rather than a raw $_SESSION read at the call
    // site.
    $exportUserId = (int) ($_SESSION['user_id'] ?? 0);
    Logger::activity(
        'AssetRegisterExported',
        'Exported ' . count($csvRows) . ' asset(s) to CSV'
            . ($statusFilter !== '' ? ' (status filter: ' . $statusFilter . ')' : ''),
        $exportUserId > 0 ? $exportUserId : null
    );

    // 📥 CsvExporter::download() sets Content-Type/Content-Disposition,
    // streams the file, and calls exit() itself — nothing below this
    // branch ever runs for an export request.
    CsvExporter::download('assets-export-' . date('Y-m-d') . '.csv', $csvRows, $csvHeaders);
}

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

// 📤 CSV export link (#403) — carries the SAME status filter as the current
// view plus a fresh CSRF token, so clicking it exports exactly what's on
// screen (see the CSRF check in the export branch above, and
// AssetRegister::listForSite()'s $canManage-gated confidential filter).
$exportCsvUrl = '/assets?export=csv&csrf_token=' . urlencode(Auth::csrfToken());
if ($statusFilter !== '') {
    $exportCsvUrl .= '&status=' . urlencode($statusFilter);
}
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-boxes-stacked me-2"></i><?php echo htmlspecialchars(I18n::t('assets.title'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-secondary mb-0"><?php echo htmlspecialchars(I18n::t('assets.subtitle'), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <div class="d-flex gap-2 mt-2 mt-md-0">
        <a href="<?php echo htmlspecialchars($exportCsvUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-success btn-sm" title="Export the current list as CSV">
            <i class="fa-solid fa-file-csv me-1"></i><?php echo htmlspecialchars(I18n::t('assets.export_csv'), ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <?php if ($canManage === true): ?>
            <!-- 📊 Value dashboard (#408) — manager-only, mirrors this
                 page's own $canManage gate; value-report.php re-checks
                 independently server-side regardless. -->
            <a href="/assets/value-report" class="btn btn-outline-secondary btn-sm" title="Purchase, current & insured value report">
                <i class="fa-solid fa-chart-column me-1"></i>Value report
            </a>
            <a href="/assets/edit" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-plus me-1"></i><?php echo htmlspecialchars(I18n::t('assets.new_asset'), ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endif; ?>
    </div>
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
