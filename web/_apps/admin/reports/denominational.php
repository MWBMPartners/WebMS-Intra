<?php
// _apps/admin/reports/denominational.php — Pre-built denominational reports (#305)
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }

$siteId = Site::id();
$report = (string) ($_GET['r'] ?? '');
$quarter = (string) ($_GET['q'] ?? date('Y') . '-Q' . (int) ceil(((int) date('n')) / 3));

// 📋 Resolve quarter to date range (YYYY-Qn)
$qStart = null; $qEnd = null;
if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $m) === 1) {
    $year = (int) $m[1]; $q = (int) $m[2];
    $qStartMonth = (($q - 1) * 3) + 1;
    $qStart = sprintf('%d-%02d-01 00:00:00', $year, $qStartMonth);
    $qEnd   = date('Y-m-t 23:59:59', strtotime(sprintf('%d-%02d-01', $year, $qStartMonth + 2)));
}

$data = ['rows' => [], 'columns' => [], 'title' => ''];

if ($report === 'attendance-by-quarter' && $qStart !== null) {
    $data['title']   = 'Attendance by quarter — ' . $quarter;
    $data['columns'] = ['Date', 'Service', 'Headcount'];
    $stmt = $mysqli->prepare('SELECT sessionDate, sessionType, headcount FROM tblAttendance WHERE siteID = ? AND sessionDate BETWEEN ? AND ? ORDER BY sessionDate');
    if ($stmt !== false) {
        $stmt->bind_param('iss', $siteId, $qStart, $qEnd);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) { $data['rows'][] = [$row['sessionDate'], $row['sessionType'], $row['headcount']]; }
        $stmt->close();
    }
} elseif ($report === 'decisions-by-quarter' && $qStart !== null) {
    $data['title']   = 'Decision cards by quarter — ' . $quarter;
    $data['columns'] = ['Decision', 'Count'];
    $stmt = $mysqli->prepare('SELECT decision, COUNT(*) c FROM tblSalvationCards WHERE siteID = ? AND createdAt BETWEEN ? AND ? GROUP BY decision ORDER BY c DESC');
    if ($stmt !== false) {
        $stmt->bind_param('iss', $siteId, $qStart, $qEnd);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) { $data['rows'][] = [$row['decision'], (int) $row['c']]; }
        $stmt->close();
    }
} elseif ($report === 'events-summary' && $qStart !== null) {
    $data['title']   = 'Events held — ' . $quarter;
    $data['columns'] = ['Date', 'Event', 'RSVPs'];
    $stmt = $mysqli->prepare(
        'SELECT e.startDateTime, e.eventName, COALESCE(c.cnt, 0) AS rsvps '
        . 'FROM tblEvents e LEFT JOIN ('
        . '  SELECT eventID, COUNT(*) cnt FROM tblEventRSVPs WHERE status = "confirmed" GROUP BY eventID'
        . ') c ON c.eventID = e.eventID '
        . 'WHERE e.siteID = ? AND e.isDeleted = 0 AND e.startDateTime BETWEEN ? AND ? '
        . 'ORDER BY e.startDateTime'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iss', $siteId, $qStart, $qEnd);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) { $data['rows'][] = [date('j M Y', strtotime((string) $row['startDateTime'])), $row['eventName'], (int) $row['rsvps']]; }
        $stmt->close();
    }
} elseif ($report === 'membership-snapshot') {
    $data['title']   = 'Membership snapshot';
    $data['columns'] = ['Metric', 'Count'];
    $r1 = $mysqli->query('SELECT COUNT(*) c FROM tblUsers WHERE isActive = 1');
    $r2 = $mysqli->query('SELECT COUNT(*) c FROM tblUsers WHERE isActive = 1 AND isAdmin = 1');
    $r3 = $mysqli->prepare('SELECT COUNT(*) c FROM tblEventCoordinators WHERE revokedAt IS NULL');
    $r3->execute();
    $data['rows'] = [
        ['Active users',  (int) ($r1->fetch_assoc()['c'] ?? 0)],
        ['Administrators',(int) ($r2->fetch_assoc()['c'] ?? 0)],
        ['Event coordinators', (int) ($r3->get_result()->fetch_assoc()['c'] ?? 0)],
    ];
    $r3->close();
}

// 📥 CSV export
if ($report !== '' && ($_GET['format'] ?? '') === 'csv' && count($data['rows']) > 0) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $report . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $data['columns']);
    foreach ($data['rows'] as $row) { fputcsv($out, $row); }
    fclose($out);
    exit();
}

$pageTitle = 'Denominational Reports';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <h1 class="h4 mb-3"><i class="fa-solid fa-file-invoice me-2 text-primary"></i>Denominational Reports</h1>

    <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-md-5">
            <label class="form-label small">Report</label>
            <select name="r" class="form-select form-select-sm">
                <option value="">— pick a report —</option>
                <option value="attendance-by-quarter" <?php echo $report === 'attendance-by-quarter' ? 'selected' : ''; ?>>Attendance by quarter</option>
                <option value="decisions-by-quarter"  <?php echo $report === 'decisions-by-quarter'  ? 'selected' : ''; ?>>Decision cards by quarter</option>
                <option value="events-summary"        <?php echo $report === 'events-summary'        ? 'selected' : ''; ?>>Events summary</option>
                <option value="membership-snapshot"   <?php echo $report === 'membership-snapshot'   ? 'selected' : ''; ?>>Membership snapshot</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small">Quarter</label>
            <input type="text" name="q" pattern="\d{4}-Q[1-4]" value="<?php echo htmlspecialchars($quarter, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm" placeholder="2026-Q2">
        </div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Run</button></div>
        <div class="col-md-2"><a class="btn btn-outline-success btn-sm w-100 <?php echo $report === '' ? 'disabled' : ''; ?>" href="?r=<?php echo urlencode($report); ?>&q=<?php echo urlencode($quarter); ?>&format=csv"><i class="fa-solid fa-file-csv me-1"></i>CSV</a></div>
    </form>

    <?php if ($report === ''): ?>
        <div class="alert alert-info small">Pick a report template to run. CSV download available for any non-empty result set.</div>
    <?php elseif (count($data['rows']) === 0): ?>
        <div class="alert alert-warning small">No data for this period.</div>
    <?php else: ?>
        <h2 class="h6"><?php echo htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="portal-data-list">
            <div class="portal-data-row fw-bold">
                <?php foreach ($data['columns'] as $c): ?>
                    <div class="portal-data-row-main"><?php echo htmlspecialchars((string) $c, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endforeach; ?>
            </div>
            <?php foreach ($data['rows'] as $row): ?>
                <div class="portal-data-row">
                    <?php foreach ($row as $cell): ?>
                        <div class="portal-data-row-main"><?php echo htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
