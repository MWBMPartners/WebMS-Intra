<?php
// Path: _apps/assets/value-report.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Asset Value Report 📦📊 (#408, Phase 2 Pass 3)
 * -----------------------------------------------------------------------------
 * Register-wide value dashboard: headline purchase/current/insured totals,
 * by-category and by-status breakdowns, an insurance section (policies
 * renewing soon + an under-insured flag), and a full per-asset depreciation
 * table with a CSV export. Manager-gated (admin/asset_manager) — same gate
 * as `edit.php`/`save.php`/`found-reports.php` — this is financial reporting
 * across the WHOLE register, including confidential assets, so it is never
 * extended to a plain `isResponsibleFor()` viewer the way some per-asset
 * panels on `item.php` are.
 *
 * "Current value" throughout this page PREFERS each asset's persisted
 * `tblAssets.currentValuePence` (written DAILY by the `#405` cron's
 * `AssetRegister::persistCurrentValues()` call — widened by `#412` Phase 3
 * Pass 2 to auto-recalculate BOTH straight-line AND reducing-balance
 * assets, not straight-line only) and falls back to a live
 * `computeCurrentValue()` estimate (same #412 dispatcher — straight-line
 * or reducing-balance, by the asset's own `depreciationMethod`) only when
 * nothing has been persisted yet — an asset that isn't computable by
 * EITHER method (missing an input; for reducing-balance that includes a
 * missing/zero salvage value, see `AssetRegister::
 * computeReducingBalanceValue()`'s own doc) is counted separately
 * (`notValuedCount`) rather than folded into a total as if it were zero.
 * See `AssetRegister::valueSummaryForSite()`/`depreciationReportRows()`'s
 * own docs (class header point 12) for the full rule. Per-asset value
 * TRENDS over time live on each asset's own `item.php` "Value history"
 * panel (`#412`), fed by the same cron's daily `tblAssetValueHistory`
 * snapshots — this register-wide report stays a point-in-time dashboard.
 *
 * CHARTS: server-rendered CSS-flex bars only (mirrors
 * `_apps/admin/reports/index.php`'s own convention) — no JS chart library,
 * no CDN, CSP-safe.
 *
 * CSV EXPORT (`?export=csv`) — mirrors `index.php`'s own export branch
 * exactly: GET-CSRF token check BEFORE any query, an explicit column
 * allow-list (NEVER `licenseKey`, `publicToken`, or `insurancePolicyNumber`
 * — a policy reference number is exactly the kind of quasi-secret this
 * codebase avoids putting in a downloadable file), `CsvExporter::download()`,
 * and a `Logger::activity()` entry recording who exported and how many rows.
 *
 * CURRENCY: every pence total on this page is summed as-is across every
 * asset — no currency conversion. Matches the register's existing
 * single-reporting-currency assumption (`index.php`'s own CSV export
 * hard-codes "(GBP)" regardless of any individual asset's `currency`
 * column) — a genuinely multi-currency register would need real FX
 * handling this pass does not attempt.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/408
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/412
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\CsvExporter;
use Portal\Core\Logger;
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

// -----------------------------------------------------------------------------
// 📤 CSV export branch (checked BEFORE the (identical either way) report
// queries below, so a failed CSRF check fails fast without ever touching
// the database — mirrors index.php's own export branch).
// -----------------------------------------------------------------------------
$isCsvExport = ($_GET['export'] ?? '') === 'csv';
if ($isCsvExport === true) {
    if (Auth::verifyCsrf($_GET['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /assets/value-report');
        exit();
    }
}

$depreciationRows = AssetRegister::depreciationReportRows($siteId);

if ($isCsvExport === true) {
    // 📊 Column allow-list — deliberately explicit, never a dump of the
    // raw row. NEVER includes licenseKey, publicToken, or
    // insurancePolicyNumber — see file header note.
    $csvHeaders = [
        'Name', 'Asset Tag', 'Category', 'Purchase Cost (GBP)', 'Depreciation Method',
        'Current Value (GBP)', 'Valuation Basis', 'Percent Depreciated', 'Insured Value (GBP)',
    ];
    $csvRows = [];
    foreach ($depreciationRows as $r) {
        $valuationBasis = 'Not valued';
        if ($r['currentValuePence'] !== null) {
            $valuationBasis = $r['isEstimate'] === true ? 'Live estimate' : 'Persisted valuation';
        }
        $csvRows[] = [
            'Name'                 => (string) $r['name'],
            'Asset Tag'            => $r['assetTagCode'] !== null ? (string) $r['assetTagCode'] : '',
            'Category'             => $r['categoryName'] !== null ? (string) $r['categoryName'] : '',
            'Purchase Cost (GBP)'  => $r['purchaseCostPence'] !== null ? number_format(((int) $r['purchaseCostPence']) / 100, 2, '.', '') : '',
            'Depreciation Method'  => ucwords(str_replace('-', ' ', (string) $r['depreciationMethod'])),
            'Current Value (GBP)'  => $r['currentValuePence'] !== null ? number_format(((int) $r['currentValuePence']) / 100, 2, '.', '') : '',
            'Valuation Basis'      => $valuationBasis,
            'Percent Depreciated'  => $r['pctDepreciated'] !== null ? (string) $r['pctDepreciated'] : '',
            'Insured Value (GBP)'  => $r['insuredValuePence'] !== null ? number_format(((int) $r['insuredValuePence']) / 100, 2, '.', '') : '',
        ];
    }

    $exportUserId = (int) ($_SESSION['user_id'] ?? 0);
    Logger::activity(
        'AssetValueReportExported',
        'Exported ' . count($csvRows) . ' asset value row(s) to CSV',
        $exportUserId > 0 ? $exportUserId : null
    );

    // 📥 CsvExporter::download() sets headers, streams, and exit()s itself.
    CsvExporter::download('assets-value-report-' . date('Y-m-d') . '.csv', $csvRows, $csvHeaders);
}

// -----------------------------------------------------------------------------
// 📊 Report data.
// -----------------------------------------------------------------------------
$summary = AssetRegister::valueSummaryForSite($siteId);
$totals  = $summary['totals'];

// 🧾 Policies renewing within 30 days — a fixed 30-day highlight window for
// THIS page's display, independent of the (admin-configurable)
// assets.reminder_lead_days_insurance the #405 cron actually mails on.
$renewingInsurance = AssetRegister::listExpiringInsurance($siteId, 30);

// ⚠️ Under-insured — insuredValuePence recorded but LOWER than the current
// (persisted-or-estimated) value. Derived here from the already-fetched
// depreciation rows (view-level filter — no extra query), mirrors
// item.php's own $newFoundReportCount array_filter() convention.
$underInsured = array_values(array_filter(
    $depreciationRows,
    static fn (array $r): bool => $r['insuredValuePence'] !== null
        && $r['currentValuePence'] !== null
        && (int) $r['insuredValuePence'] < (int) $r['currentValuePence']
));

// 💷 Money formatter — "GBP 123.45", matching item.php's own convention for
// this app (currency CODE prefix, not a symbol).
$money = static function (?int $pence): string {
    return $pence !== null ? 'GBP ' . number_format($pence / 100, 2) : '—';
};

$csrf = Auth::csrfToken();
$exportCsvUrl = '/assets/value-report?export=csv&csrf_token=' . urlencode($csrf);

$pageTitle   = 'Asset Value Report';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Value Report' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-chart-column me-2"></i>Asset Value Report</h1>
        <p class="text-secondary mb-0">Purchase, current and insured value across the whole register.</p>
    </div>
    <div class="d-flex gap-2 mt-2 mt-md-0">
        <a href="<?php echo htmlspecialchars($exportCsvUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-success btn-sm" title="Export the depreciation table as CSV">
            <i class="fa-solid fa-file-csv me-1"></i>Export CSV
        </a>
        <a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>
    </div>
</div>

<?php if ((int) $totals['assetCount'] === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>No assets on the register yet.
    </div>
<?php else: ?>

    <!-- 📊 Headline totals -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center border-primary">
                <div class="card-body">
                    <div class="h4 mb-0 text-primary"><?php echo htmlspecialchars($money((int) $totals['purchaseCostPence']), ENT_QUOTES, 'UTF-8'); ?></div>
                    <small class="text-muted">Total purchase cost</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center border-success">
                <div class="card-body">
                    <div class="h4 mb-0 text-success"><?php echo htmlspecialchars($money((int) $totals['currentValuePence']), ENT_QUOTES, 'UTF-8'); ?></div>
                    <small class="text-muted">Total current value</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center border-info">
                <div class="card-body">
                    <div class="h4 mb-0 text-info"><?php echo htmlspecialchars($money((int) $totals['insuredValuePence']), ENT_QUOTES, 'UTF-8'); ?></div>
                    <small class="text-muted">Total insured value (<?php echo (int) $totals['insuredAssetCount']; ?> asset<?php echo (int) $totals['insuredAssetCount'] === 1 ? '' : 's'; ?>)</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center border-warning">
                <div class="card-body">
                    <div class="h4 mb-0 text-warning"><?php echo (int) $totals['notValuedCount']; ?></div>
                    <small class="text-muted">Not valued (no computable estimate)</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 📊 Purchase vs current vs insured — CSS-flex bar chart (no JS lib),
         mirrors admin/reports/index.php's "Monthly Activity" convention. -->
    <div class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0"><i class="fa-solid fa-scale-balanced me-2"></i>Purchase vs current vs insured</h2></div>
        <div class="card-body">
            <?php
            $chartMax = max((int) $totals['purchaseCostPence'], (int) $totals['currentValuePence'], (int) $totals['insuredValuePence'], 1);
            $chartBars = [
                ['label' => 'Purchase cost', 'value' => (int) $totals['purchaseCostPence'], 'color' => 'primary'],
                ['label' => 'Current value', 'value' => (int) $totals['currentValuePence'], 'color' => 'success'],
                ['label' => 'Insured value', 'value' => (int) $totals['insuredValuePence'], 'color' => 'info'],
            ];
            ?>
            <div class="d-flex align-items-end gap-4" style="height:160px;">
                <?php foreach ($chartBars as $bar): ?>
                    <?php $barPct = $chartMax > 0 ? round(($bar['value'] / $chartMax) * 100) : 0; ?>
                    <div class="d-flex flex-column align-items-center flex-grow-1 h-100 justify-content-end">
                        <small class="text-muted mb-1"><?php echo htmlspecialchars($money($bar['value']), ENT_QUOTES, 'UTF-8'); ?></small>
                        <div class="bg-<?php echo htmlspecialchars($bar['color'], ENT_QUOTES, 'UTF-8'); ?> rounded-top w-100" style="height:<?php echo max(2, $barPct); ?>%;min-height:4px;"></div>
                        <small class="text-muted mt-1"><?php echo htmlspecialchars($bar['label'], ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ((int) $totals['insuredAssetCount'] > 0): ?>
                <p class="small text-muted mt-3 mb-0">
                    Insurance gap (insured &minus; current, over the <?php echo (int) $totals['insuredAssetCount']; ?> insured asset(s) with a known current value):
                    <strong class="<?php echo (int) $totals['insuranceGapPence'] < 0 ? 'text-danger' : 'text-success'; ?>">
                        <?php echo htmlspecialchars($money((int) $totals['insuranceGapPence']), ENT_QUOTES, 'UTF-8'); ?>
                    </strong>
                    <?php if ((int) $totals['underInsuredCount'] > 0): ?>
                        &mdash; <?php echo (int) $totals['underInsuredCount']; ?> asset(s) under-insured (see below).
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- 📊 By category -->
        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="h5 mb-0"><i class="fa-solid fa-tags me-2"></i>By category</h2></div>
                <div class="card-body">
                    <?php if (count($summary['byCategory']) === 0): ?>
                        <p class="text-muted mb-0">No category data.</p>
                    <?php else: ?>
                        <div class="portal-data-list">
                            <?php foreach ($summary['byCategory'] as $cat): ?>
                                <?php $catPct = (int) $totals['currentValuePence'] > 0 ? round(((int) $cat['currentValuePence'] / (int) $totals['currentValuePence']) * 100) : 0; ?>
                                <div class="portal-data-row flex-column align-items-stretch">
                                    <div class="d-flex justify-content-between small">
                                        <span><?php echo htmlspecialchars((string) $cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?> <span class="text-muted">(<?php echo (int) $cat['assetCount']; ?>)</span></span>
                                        <span><?php echo htmlspecialchars($money((int) $cat['currentValuePence']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="progress" style="height:6px;">
                                        <div class="progress-bar bg-success" style="width:<?php echo $catPct; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 📊 By status -->
        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="h5 mb-0"><i class="fa-solid fa-list-check me-2"></i>By status</h2></div>
                <div class="card-body">
                    <?php if (count($summary['byStatus']) === 0): ?>
                        <p class="text-muted mb-0">No status data.</p>
                    <?php else: ?>
                        <div class="portal-data-list">
                            <?php foreach ($summary['byStatus'] as $stat): ?>
                                <?php $statPct = (int) $totals['currentValuePence'] > 0 ? round(((int) $stat['currentValuePence'] / (int) $totals['currentValuePence']) * 100) : 0; ?>
                                <div class="portal-data-row flex-column align-items-stretch">
                                    <div class="d-flex justify-content-between small">
                                        <span><?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string) $stat['status'])), ENT_QUOTES, 'UTF-8'); ?> <span class="text-muted">(<?php echo (int) $stat['assetCount']; ?>)</span></span>
                                        <span><?php echo htmlspecialchars($money((int) $stat['currentValuePence']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="progress" style="height:6px;">
                                        <div class="progress-bar bg-primary" style="width:<?php echo $statPct; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 🧾 Insurance -->
    <div class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0"><i class="fa-solid fa-shield-halved me-2"></i>Insurance</h2></div>
        <div class="card-body">
            <h3 class="h6">Renewing within 30 days</h3>
            <?php if (count($renewingInsurance) === 0): ?>
                <p class="text-muted">No policies renewing in the next 30 days.</p>
            <?php else: ?>
                <div class="portal-data-list mb-4">
                    <?php foreach ($renewingInsurance as $ins): ?>
                        <div class="portal-data-row align-items-center">
                            <div class="col-7 col-md-6">
                                <a href="/assets/item?id=<?php echo (int) $ins['assetID']; ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars((string) $ins['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php if ($ins['insurerName'] !== null): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars((string) $ins['insurerName'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-5 col-md-6 text-end">
                                <span class="badge bg-info">Renews <?php echo htmlspecialchars((string) $ins['insuranceRenewalDate'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3 class="h6">Under-insured (insured value below current value)</h3>
            <?php if (count($underInsured) === 0): ?>
                <p class="text-muted mb-0">No under-insured assets found.</p>
            <?php else: ?>
                <div class="portal-data-list">
                    <?php foreach ($underInsured as $r): ?>
                        <div class="portal-data-row align-items-center">
                            <div class="col-6 col-md-5">
                                <a href="/assets/item?id=<?php echo (int) $r['assetID']; ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </div>
                            <div class="col-3 col-md-3 small text-muted">Current: <?php echo htmlspecialchars($money((int) $r['currentValuePence']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-3 col-md-4 text-end">
                                <span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Insured: <?php echo htmlspecialchars($money((int) $r['insuredValuePence']), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 📋 Full depreciation table -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h5 mb-0"><i class="fa-solid fa-chart-line me-2"></i>Depreciation — every asset</h2></div>
        <div class="card-body">
            <?php if (count($depreciationRows) === 0): ?>
                <p class="text-muted mb-0">No assets to show.</p>
            <?php else: ?>
                <div class="portal-data-list">
                    <?php foreach ($depreciationRows as $r): ?>
                        <div class="portal-data-row align-items-center">
                            <div class="col-12 col-md-4">
                                <a href="/assets/item?id=<?php echo (int) $r['assetID']; ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php if ($r['categoryName'] !== null): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars((string) $r['categoryName'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-4 col-md-2 small text-muted">
                                <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string) $r['depreciationMethod'])), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="col-4 col-md-2 small">
                                <?php echo htmlspecialchars($money($r['purchaseCostPence'] !== null ? (int) $r['purchaseCostPence'] : null), ENT_QUOTES, 'UTF-8'); ?>
                                <br><span class="text-muted">purchase</span>
                            </div>
                            <div class="col-4 col-md-2 small">
                                <?php echo htmlspecialchars($money($r['currentValuePence'] !== null ? (int) $r['currentValuePence'] : null), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($r['currentValuePence'] !== null): ?>
                                    <br><span class="text-muted"><?php echo $r['isEstimate'] === true ? 'live estimate' : 'valued ' . htmlspecialchars((string) $r['valuationDate'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="col-12 col-md-2 text-md-end small">
                                <?php if ($r['pctDepreciated'] !== null): ?>
                                    <?php echo htmlspecialchars((string) $r['pctDepreciated'], ENT_QUOTES, 'UTF-8'); ?>% depreciated
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
