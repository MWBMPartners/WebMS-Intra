<?php
// Path: _apps/admin/reports/ccli.php
/**
 * -----------------------------------------------------------------------------
 * Admin — CCLI Usage Report (#308 Phase 3)
 * -----------------------------------------------------------------------------
 * Aggregates tblCcliUsage rows per song over a date range. UK churches need
 * this for their CCLI quarterly report. CSV export reuses the pattern from
 * /admin/reports/denominational (#305).
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Settings;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }

$siteId  = Site::id();
$quarter = (string) ($_GET['q'] ?? date('Y') . '-Q' . (int) ceil(((int) date('n')) / 3));
$ccliAcct = (string) Settings::get('worship.ccli_account_number', '');

// 📋 Resolve quarter to date range.
$qStart = null; $qEnd = null;
if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $m) === 1) {
    $year = (int) $m[1]; $q = (int) $m[2];
    $sm = (($q - 1) * 3) + 1;
    $qStart = sprintf('%d-%02d-01 00:00:00', $year, $sm);
    $qEnd   = date('Y-m-t 23:59:59', strtotime(sprintf('%d-%02d-01', $year, $sm + 2)));
}

$rows = [];
if ($qStart !== null) {
    $stmt = $mysqli->prepare(
        'SELECT s.songID, s.title, s.author, s.ccliNumber, s.copyrightLine, COUNT(u.usageID) AS plays '
        . 'FROM tblCcliUsage u JOIN tblSongs s ON s.songID = u.songID '
        . 'WHERE u.siteID = ? AND u.playedAt BETWEEN ? AND ? '
        . 'GROUP BY s.songID ORDER BY plays DESC, s.title'
    );
    $stmt->bind_param('iss', $siteId, $qStart, $qEnd);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
}

// 📥 CSV export.
if (($_GET['format'] ?? '') === 'csv' && count($rows) > 0) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ccli-' . $quarter . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Song Title', 'Author', 'CCLI #', 'Copyright', 'Plays']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['title'], $r['author'] ?? '', $r['ccliNumber'] ?? '', $r['copyrightLine'] ?? '', (int) $r['plays']]);
    }
    fclose($out);
    exit();
}

$pageTitle = 'CCLI Usage Report';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <h1 class="h4 mb-3"><i class="fa-solid fa-music me-2 text-primary"></i>CCLI Usage Report</h1>

    <?php if ($ccliAcct !== ''): ?>
        <p class="text-muted small">Reported under CCLI account <strong><?php echo htmlspecialchars($ccliAcct, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
    <?php else: ?>
        <p class="text-muted small">Set <code>worship.ccli_account_number</code> in <a href="/admin/settings">Settings</a> to display your account on the report.</p>
    <?php endif; ?>

    <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-md-3">
            <label class="form-label small">Quarter</label>
            <input type="text" name="q" pattern="\d{4}-Q[1-4]" value="<?php echo htmlspecialchars($quarter, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm" placeholder="2026-Q2">
        </div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Run</button></div>
        <div class="col-md-2"><a class="btn btn-outline-success btn-sm w-100 <?php echo count($rows) === 0 ? 'disabled' : ''; ?>" href="?q=<?php echo urlencode($quarter); ?>&format=csv"><i class="fa-solid fa-file-csv me-1"></i>CSV</a></div>
    </form>

    <?php if (count($rows) === 0): ?>
        <div class="alert alert-info small">No song plays recorded for <?php echo htmlspecialchars($quarter, ENT_QUOTES, 'UTF-8'); ?>.</div>
    <?php else: ?>
        <p class="small text-muted">Total songs played: <strong><?php echo count($rows); ?></strong></p>
        <div class="portal-data-list">
            <div class="portal-data-row fw-bold small bg-light">
                <div class="portal-data-row-main">Title / Author</div>
                <div class="portal-data-row-aside">CCLI # &middot; Plays</div>
            </div>
            <?php foreach ($rows as $r): ?>
                <div class="portal-data-row">
                    <div class="portal-data-row-main">
                        <strong><?php echo htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if (!empty($r['author'])): ?>
                            <span class="text-muted small"> — <?php echo htmlspecialchars((string) $r['author'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($r['copyrightLine'])): ?>
                            <div class="small text-muted"><?php echo htmlspecialchars((string) $r['copyrightLine'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="portal-data-row-aside">
                        <?php if (!empty($r['ccliNumber'])): ?>
                            <span class="badge bg-secondary me-1">CCLI <?php echo htmlspecialchars((string) $r['ccliNumber'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <span class="badge bg-primary"><?php echo (int) $r['plays']; ?> plays</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
