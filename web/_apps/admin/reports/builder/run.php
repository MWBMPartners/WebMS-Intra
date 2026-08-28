<?php
// Path: _apps/admin/reports/builder/run.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Reports Builder: run a saved report 📊 (#156)
 * -----------------------------------------------------------------------------
 * Loads via ReportBuilder::get() — cross-site is indistinguishable from
 * missing (404 flash). A non-shared report is visible only to its author
 * or a site admin (A5 — "shared" means every site admin of this site, not
 * every logged-in member). Re-compiles fresh from the STORED definition
 * JSON on every load — a DB-tampered row fails closed here, never at the
 * SQL layer. Paginated (LIMIT/OFFSET both bound 'i'). A grouped result set
 * additionally renders a bar/line chart via Asset::chartJs().
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
use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\ReportBuilder;
use Portal\Core\ReportRegistry;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = t('error.access_denied_inline');
    $_SESSION['flash_type'] = 'danger';
    header('Location: /dashboard');
    exit();
}

if (AppRegistry::isEnabled('reports') === false) {
    $_SESSION['flash_msg']  = 'Reports is disabled for this site.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/apps');
    exit();
}

$siteId   = Site::id();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$reportId = (int) ($_GET['id'] ?? 0);
$page     = max(1, (int) ($_GET['page'] ?? 1));

if ($reportId <= 0) {
    $_SESSION['flash_msg']  = 'Invalid report.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

$row = ReportBuilder::get($reportId, $siteId);
if ($row === null) {
    $_SESSION['flash_msg']  = 'Report not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

$isOwner  = ((int) ($row['createdByID'] ?? 0)) === $userId;
$isShared = (int) $row['isShared'] === 1;
if ($isShared === false && $isOwner === false && App::isSiteAdmin() === false) {
    $_SESSION['flash_msg']  = 'You do not have access to this report.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

$pageSize = (int) (App::settings('reports.builder.pageSize') ?? 50);
if ($pageSize <= 0) {
    $pageSize = 50;
}

$compileError = null;
$compiled     = null;
$definition   = null;
$isGrouped    = false;
try {
    $definition = ReportBuilder::decodeDefinitionJson((string) $row['definition']);
    $compiled   = ReportBuilder::compile($definition, $siteId);
    $isGrouped  = array_key_exists('group', $definition) === true && $definition['group'] !== null;
} catch (\InvalidArgumentException $e) {
    // 🛡️ A DB-tampered / stale-registry row fails closed here — the
    // report is simply refused to run, never partially executed.
    $compileError = $e->getMessage();
}

$result  = ['rows' => [], 'hasMore' => false];
$offset  = ($page - 1) * $pageSize;
if ($compiled !== null) {
    $result = ReportBuilder::run($compiled, $pageSize, $offset);
    ReportBuilder::touchRun($reportId, $siteId);
    Logger::activity('ReportRun', 'reportID=' . $reportId . ' source=' . (string) $row['sourceKey'], $userId);

    // 🔐 Audit-log detail when the definition selects/aggregates any
    // pii-flagged column — the trail for PII leaving the system (§6.4).
    $srcMeta = ReportRegistry::source((string) $row['sourceKey']);
    $piiCols = [];
    if ($srcMeta !== null) {
        foreach ((array) $compiled['columns'] as $meta) {
            $colEntry = ReportRegistry::column((string) $row['sourceKey'], (string) $meta['key']);
            if ($colEntry !== null && ($colEntry['pii'] ?? false) === true) {
                $piiCols[] = (string) $meta['key'];
            }
        }
    }
    if (count($piiCols) > 0) {
        Logger::activity('ReportRunPii', 'reportID=' . $reportId . ' piiColumns=' . implode(',', $piiCols), $userId);
    }
}

$sourceLabel = ReportRegistry::source((string) $row['sourceKey'])['label'] ?? (string) $row['sourceKey'];

$pageTitle   = htmlspecialchars($row['reportName'], ENT_QUOTES, 'UTF-8');
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Reports' => '/admin/reports', 'Builder' => '/admin/reports/builder', (string) $row['reportName'] => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-play me-2"></i><?php echo htmlspecialchars($row['reportName'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-secondary mb-0">
            <span class="badge bg-secondary"><?php echo htmlspecialchars((string) $sourceLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if (($row['description'] ?? '') !== ''): ?>
                <?php echo htmlspecialchars((string) $row['description'], ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="/admin/reports/builder/export?id=<?php echo (int) $reportId; ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-file-csv me-1"></i>Export CSV
        </a>
        <a href="/admin/reports/builder/edit?id=<?php echo (int) $reportId; ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-pen me-1"></i>Edit
        </a>
        <a href="/admin/reports/builder" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<?php if ($compileError !== null): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        This report definition is no longer valid and could not be run: <?php echo htmlspecialchars($compileError, ENT_QUOTES, 'UTF-8'); ?>.
        Please edit and re-save it.
    </div>
<?php else: ?>

    <?php if ($isGrouped === true && count($result['rows']) > 0): ?>
        <!-- 📊 Chart (A1) — grouped result set only, re-encodes the already-fetched
             rows; never triggers a second query path. -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fa-solid fa-chart-column me-2"></i>Chart</h6>
                <div class="btn-group btn-group-sm" role="group" aria-label="Chart type">
                    <button type="button" class="btn btn-outline-secondary active" data-chart-type="bar">Bar</button>
                    <button type="button" class="btn btn-outline-secondary" data-chart-type="line">Line</button>
                </div>
            </div>
            <div class="card-body">
                <canvas id="reportChart" height="90" aria-label="Report chart" role="img"></canvas>
                <p class="small text-muted mb-0 mt-2" id="reportChartFallback" style="display:none;">
                    Chart library did not load — showing data table below only.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- 📋 Result grid — the ONE legitimate raw-<table>-adjacent case is
         still avoided here: dynamic column count via Bootstrap's flexible
         `.col` (no fixed col-N), same portal-data-list component as every
         other list in the app. -->
    <div class="portal-data-list mb-3">
        <div class="portal-data-header">
            <?php foreach ((array) $compiled['columns'] as $meta): ?>
                <div class="col"><?php echo htmlspecialchars((string) $meta['label'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
        </div>
        <?php if (count($result['rows']) === 0): ?>
            <div class="portal-data-row"><div class="col text-muted">No rows match this report's filters.</div></div>
        <?php else: ?>
            <?php foreach ($result['rows'] as $dataRow): ?>
                <div class="portal-data-row">
                    <?php foreach ((array) $compiled['columns'] as $meta): ?>
                        <div class="col"><?php echo htmlspecialchars((string) ($dataRow[(string) $meta['key']] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ⏮️⏭️ Prev/Next pagination — hasMore-driven, no COUNT(*) query needed. -->
    <nav aria-label="Report pagination" class="d-flex justify-content-between align-items-center">
        <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>"
           href="/admin/reports/builder/run?id=<?php echo (int) $reportId; ?>&amp;page=<?php echo max(1, $page - 1); ?>">
            <i class="fa-solid fa-chevron-left me-1"></i>Previous
        </a>
        <span class="text-muted small">Page <?php echo (int) $page; ?></span>
        <a class="btn btn-sm btn-outline-secondary <?php echo $result['hasMore'] !== true ? 'disabled' : ''; ?>"
           href="/admin/reports/builder/run?id=<?php echo (int) $reportId; ?>&amp;page=<?php echo $page + 1; ?>">
            Next<i class="fa-solid fa-chevron-right ms-1"></i>
        </a>
    </nav>

    <?php if ($isGrouped === true && count($result['rows']) > 0): ?>
        <?php echo Asset::chartJs(); ?>
        <script nonce="<?php echo htmlspecialchars(App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
        (function () {
            'use strict';
            // 🛡️ JSON_HEX_TAG (never JSON_UNESCAPED_SLASHES here) — `rows`
            // is real DATABASE content (event names, task titles, …), so a
            // value containing a literal script-closing tag must not be
            // able to break out of this inline <script> block. HEX_TAG
            // rewrites every angle bracket to a \uXXXX escape, which
            // neutralises that regardless of slash-escaping.
            var dataIsland = <?php echo json_encode([
                'labelKey' => (string) $compiled['columns'][0]['key'],
                'valueKeys' => array_slice(array_column($compiled['columns'], 'key'), 1),
                'valueLabels' => array_slice(array_column($compiled['columns'], 'label'), 1),
                'rows' => $result['rows'],
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;

            function render(type) {
                if (typeof window.Chart === 'undefined') {
                    var fb = document.getElementById('reportChartFallback');
                    if (fb !== null) { fb.style.display = 'block'; }
                    return;
                }
                var canvas = document.getElementById('reportChart');
                if (canvas === null) { return; }
                var labels = dataIsland.rows.map(function (r) { return String(r[dataIsland.labelKey]); });
                var datasets = dataIsland.valueKeys.map(function (key, i) {
                    return {
                        label: dataIsland.valueLabels[i],
                        data: dataIsland.rows.map(function (r) { return Number(r[key]) || 0; }),
                        borderWidth: 1
                    };
                });
                if (window.__reportChartInstance) {
                    window.__reportChartInstance.destroy();
                }
                window.__reportChartInstance = new window.Chart(canvas, {
                    type: type,
                    data: { labels: labels, datasets: datasets },
                    options: { responsive: true, scales: { y: { beginAtZero: true } } }
                });
            }

            render('bar');
            var buttons = document.querySelectorAll('[data-chart-type]');
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].addEventListener('click', function (ev) {
                    for (var j = 0; j < buttons.length; j++) { buttons[j].classList.remove('active'); }
                    ev.currentTarget.classList.add('active');
                    render(ev.currentTarget.getAttribute('data-chart-type'));
                });
            }
        })();
        </script>
    <?php endif; ?>

<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
