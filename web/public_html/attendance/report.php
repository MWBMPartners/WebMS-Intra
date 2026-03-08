<?php
// Path: public_html/attendance/report.php
/**
 * -----------------------------------------------------------------------------
 * Attendance Tracker — Reports & Trends 📊
 * -----------------------------------------------------------------------------
 * Displays attendance reports with:
 *   - Monthly headcount totals by service type
 *   - Year-over-year comparison
 *   - Exportable summary data
 *
 * @package   Portal\Attendance
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Attendance Reports';
$pageSection = 'attendance';
$breadcrumbs = ['Dashboard' => '/', 'Attendance' => '/attendance', 'Reports' => ''];

// 🛡️ Ensure session
Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

// -----------------------------------------------------------------------------
// 📅 Report parameters
// -----------------------------------------------------------------------------
// 🌐 Multi-site scope
$siteId = Site::id();

$reportYear  = (int) ($_GET['year'] ?? (int) date('Y'));
$reportMonth = (int) ($_GET['month'] ?? 0); // 0 = full year

// 📊 Monthly totals for the selected year
$monthlyTotals = [];
$stmt = $mysqli->prepare(
    'SELECT MONTH(s.sessionDate) AS m, '
    . 'COUNT(DISTINCT s.sessionID) AS sessions, '
    . 'COALESCE(SUM(c.headcount), 0) AS headcount '
    . 'FROM tblAttendanceSessions s '
    . 'LEFT JOIN tblAttendanceCounts c ON c.sessionID = s.sessionID '
    . 'WHERE s.isDeleted = 0 AND s.siteID = ? AND YEAR(s.sessionDate) = ? '
    . 'GROUP BY MONTH(s.sessionDate) ORDER BY m'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $reportYear);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $monthlyTotals[(int) $r['m']] = $r;
    }
    $stmt->close();
}

// 📊 Breakdown by service type for the selected year (or month)
$typeBreakdown = [];
$typeSql = 'SELECT st.typeName, st.serviceTypeID, '
         . 'COUNT(DISTINCT s.sessionID) AS sessions, '
         . 'COALESCE(SUM(c.headcount), 0) AS headcount '
         . 'FROM tblAttendanceSessions s '
         . 'INNER JOIN tblAttendanceServiceTypes st ON st.serviceTypeID = s.serviceTypeID '
         . 'LEFT JOIN tblAttendanceCounts c ON c.sessionID = s.sessionID '
         . 'WHERE s.isDeleted = 0 AND s.siteID = ? AND YEAR(s.sessionDate) = ?';

if ($reportMonth > 0) {
    $typeSql .= ' AND MONTH(s.sessionDate) = ?';
}
$typeSql .= ' GROUP BY st.serviceTypeID ORDER BY headcount DESC';

$stmt = $mysqli->prepare($typeSql);
if ($stmt !== false) {
    if ($reportMonth > 0) {
        $stmt->bind_param('iii', $siteId, $reportYear, $reportMonth);
    } else {
        $stmt->bind_param('ii', $siteId, $reportYear);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $typeBreakdown[] = $r;
    }
    $stmt->close();
}

// 📊 Group-level breakdown (Adults, Children, Visitors etc) for the period
$groupBreakdown = [];
$groupSql = 'SELECT c.groupLabel, SUM(c.headcount) AS totalCount '
          . 'FROM tblAttendanceCounts c '
          . 'INNER JOIN tblAttendanceSessions s ON s.sessionID = c.sessionID '
          . 'WHERE s.isDeleted = 0 AND s.siteID = ? AND YEAR(s.sessionDate) = ?';

if ($reportMonth > 0) {
    $groupSql .= ' AND MONTH(s.sessionDate) = ?';
}
$groupSql .= ' GROUP BY c.groupLabel ORDER BY totalCount DESC';

$stmt = $mysqli->prepare($groupSql);
if ($stmt !== false) {
    if ($reportMonth > 0) {
        $stmt->bind_param('iii', $siteId, $reportYear, $reportMonth);
    } else {
        $stmt->bind_param('ii', $siteId, $reportYear);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $groupBreakdown[] = $r;
    }
    $stmt->close();
}

// 📊 Year totals
$yearTotal = ['sessions' => 0, 'headcount' => 0];
foreach ($monthlyTotals as $mt) {
    $yearTotal['sessions']  += (int) $mt['sessions'];
    $yearTotal['headcount'] += (int) $mt['headcount'];
}

// 📊 Available years for dropdown
$availableYears = [];
$stmtYears = $mysqli->prepare(
    'SELECT DISTINCT YEAR(sessionDate) AS y FROM tblAttendanceSessions WHERE isDeleted = 0 AND siteID = ? ORDER BY y DESC'
);
if ($stmtYears !== false) {
    $stmtYears->bind_param('i', $siteId);
    $stmtYears->execute();
    $resultYears = $stmtYears->get_result();
    while ($r = $resultYears->fetch_assoc()) {
        $availableYears[] = (int) $r['y'];
    }
    $stmtYears->close();
}
if (in_array($reportYear, $availableYears, true) === false) {
    $availableYears[] = $reportYear;
    rsort($availableYears);
}

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📊 Reports Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-chart-bar me-2"></i>Attendance Reports</h1>
    <a href="/attendance" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back
    </a>
</div>

<!-- 📅 Report Period Selector -->
<form method="get" action="/attendance/report" class="row g-2 mb-4">
    <div class="col-6 col-md-3">
        <select name="year" class="form-select form-select-sm">
            <?php foreach ($availableYears as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo ($y === $reportYear) ? 'selected' : ''; ?>>
                    <?php echo $y; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <select name="month" class="form-select form-select-sm">
            <option value="0">Full Year</option>
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?php echo $m; ?>" <?php echo ($m === $reportMonth) ? 'selected' : ''; ?>>
                    <?php echo $monthNames[$m]; ?>
                </option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
            <i class="fa-solid fa-magnifying-glass me-1"></i> View
        </button>
    </div>
</form>

<!-- 📊 Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <h3 class="mb-1"><?php echo number_format($yearTotal['sessions']); ?></h3>
                <small class="text-muted">Total Sessions</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <h3 class="mb-1"><?php echo number_format($yearTotal['headcount']); ?></h3>
                <small class="text-muted">Total Headcount</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <h3 class="mb-1">
                    <?php echo $yearTotal['sessions'] > 0
                        ? number_format((int) round($yearTotal['headcount'] / $yearTotal['sessions']))
                        : '0'; ?>
                </h3>
                <small class="text-muted">Avg per Session</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <h3 class="mb-1"><?php echo count($typeBreakdown); ?></h3>
                <small class="text-muted">Service Types Used</small>
            </div>
        </div>
    </div>
</div>

<?php if ($reportMonth === 0): ?>
<!-- 📊 Monthly Breakdown (full year view) -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">Monthly Breakdown — <?php echo $reportYear; ?></h5></div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-3">Month</div>
                <div class="col-md-2 text-center">Sessions</div>
                <div class="col-md-3 text-center">Headcount</div>
                <div class="col-md-2 text-center">Avg / Session</div>
                <div class="col-md-2 text-end">Detail</div>
            </div>

            <?php for ($m = 1; $m <= 12; $m++): ?>
                <?php
                $mt = $monthlyTotals[$m] ?? null;
                $mSessions  = $mt !== null ? (int) $mt['sessions'] : 0;
                $mHeadcount = $mt !== null ? (int) $mt['headcount'] : 0;
                $mAvg = $mSessions > 0 ? (int) round($mHeadcount / $mSessions) : 0;
                ?>
                <div class="portal-data-row <?php echo $mSessions === 0 ? 'opacity-50' : ''; ?>">
                    <div class="col-12 col-md-3">
                        <span class="d-md-none fw-semibold">Month: </span>
                        <strong><?php echo $monthNames[$m]; ?></strong>
                    </div>
                    <div class="col-12 col-md-2 text-md-center">
                        <span class="d-md-none fw-semibold">Sessions: </span>
                        <?php echo $mSessions; ?>
                    </div>
                    <div class="col-12 col-md-3 text-md-center">
                        <span class="d-md-none fw-semibold">Headcount: </span>
                        <strong><?php echo number_format($mHeadcount); ?></strong>
                    </div>
                    <div class="col-12 col-md-2 text-md-center">
                        <span class="d-md-none fw-semibold">Avg: </span>
                        <?php echo number_format($mAvg); ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                        <?php if ($mSessions > 0): ?>
                            <a href="/attendance/report?year=<?php echo $reportYear; ?>&month=<?php echo $m; ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 📊 By Service Type -->
<?php if (count($typeBreakdown) > 0): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">By Service Type
            <?php if ($reportMonth > 0): ?>
                — <?php echo htmlspecialchars($monthNames[$reportMonth], ENT_QUOTES, 'UTF-8'); ?> <?php echo $reportYear; ?>
            <?php else: ?>
                — <?php echo $reportYear; ?>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-4">Service Type</div>
                <div class="col-md-3 text-center">Sessions</div>
                <div class="col-md-3 text-center">Total Headcount</div>
                <div class="col-md-2 text-center">Avg / Session</div>
            </div>

            <?php foreach ($typeBreakdown as $tb): ?>
                <?php $tbAvg = (int) $tb['sessions'] > 0 ? (int) round((int) $tb['headcount'] / (int) $tb['sessions']) : 0; ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-4">
                        <span class="d-md-none fw-semibold">Type: </span>
                        <strong><?php echo htmlspecialchars($tb['typeName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="col-12 col-md-3 text-md-center">
                        <span class="d-md-none fw-semibold">Sessions: </span>
                        <?php echo (int) $tb['sessions']; ?>
                    </div>
                    <div class="col-12 col-md-3 text-md-center">
                        <span class="d-md-none fw-semibold">Headcount: </span>
                        <strong><?php echo number_format((int) $tb['headcount']); ?></strong>
                    </div>
                    <div class="col-12 col-md-2 text-md-center">
                        <span class="d-md-none fw-semibold">Avg: </span>
                        <?php echo number_format($tbAvg); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 📊 By Group Label -->
<?php if (count($groupBreakdown) > 0): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">By Headcount Group
            <?php if ($reportMonth > 0): ?>
                — <?php echo htmlspecialchars($monthNames[$reportMonth], ENT_QUOTES, 'UTF-8'); ?> <?php echo $reportYear; ?>
            <?php else: ?>
                — <?php echo $reportYear; ?>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($groupBreakdown as $gb): ?>
                <div class="col-6 col-md-3">
                    <div class="card text-center">
                        <div class="card-body py-2">
                            <h4 class="mb-0"><?php echo number_format((int) $gb['totalCount']); ?></h4>
                            <small class="text-muted"><?php echo htmlspecialchars($gb['groupLabel'], ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
