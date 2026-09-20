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

use Portal\Core\AnonymousCheckins;
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

// -----------------------------------------------------------------------------
// 🚪 Anonymous check-ins (#525)
// -----------------------------------------------------------------------------
// The page's own sign-in rule at the top of this file is UNCHANGED: it asks
// only that somebody is signed in. Who may see the anonymous section is decided
// separately, by the organisation's own setting, and can only ever narrow from
// there — `$passesPageGate` is true below because anybody still reading this
// line got past the rule at the top.
//
// TWO SAFEGUARDS THAT ARE NOT OPTIONAL HERE:
//
// 1. This section shows the organisation's TOTALS and a line per month. It
//    never shows an event name. That matters because an organisation may
//    choose "anyone who can already open the page", and today that means every
//    signed-in user on this installation, not only members of this organisation.
//    A total tells nobody which events took place; a list of names would — and
//    internal events collect anonymous check-ins too. Narrowing who may open
//    this page at all is issue #529, not this work.
//
// 2. There is no single event here, so "this event's coordinator" cannot be
//    tested at all. `$isEventScoped` is false, which makes the
//    administrators-and-coordinators choice mean administrators only on this
//    page. That is deliberate: showing a coordinator an organisation-wide total
//    that includes events they have nothing to do with is exactly what this
//    work set out to avoid.
$anonChoice  = AnonymousCheckins::readVisibilityChoice($siteId);
$mayViewAnon = AnonymousCheckins::mayView($anonChoice, true, App::isAdmin(), false, false);

$anon = null;
if ($mayViewAnon === true) {
    $anon = AnonymousCheckins::summaryForSite($mysqli, $siteId, $reportYear, $reportMonth);
}

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

<!-- 🚪 Anonymous check-ins (#525) — the organisation's totals only, never an
     event name. See the long note beside $mayViewAnon above for why. -->
<?php if ($mayViewAnon === true && $anon !== null): ?>
<div class="card mb-4 border-info">
    <div class="card-header bg-body-tertiary">
        <h5 class="mb-0">
            <i class="fa-solid fa-door-open me-2 text-info"></i>Anonymous check-ins at the door
            <?php if ($reportMonth > 0): ?>
                <!-- $reportMonth comes straight from the address (?month=) and is only ever checked
                     for being > 0, never for being <= 12. `?month=99` used to read a key that is not
                     in $monthNames at all, which PHP treats as a warning — and unlike the two older
                     copies of this same line (further up this file, inside "if there is data" blocks
                     that a made-up month never reaches because there is never data for month 99), this
                     card renders whenever the viewer may see the figures at all, data or none, so the
                     warning fired on every single request. Found by the #525 independent check: three
                     requests wrote 33 rows to tblErrors. Fixed the same way this file already prints an
                     unchecked month 66 lines below ($am['month']): fall back to the raw number rather
                     than trust the address to be 1-12. -->
                — <?php echo htmlspecialchars($monthNames[$reportMonth] ?? (string) $reportMonth, ENT_QUOTES, 'UTF-8'); ?> <?php echo $reportYear; ?>
            <?php else: ?>
                — <?php echo $reportYear; ?>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="alert alert-secondary small">
            <p class="mb-1">
                <strong>These are counted on a different basis from everything above, and must never
                be added to it or subtracted from it.</strong> The figures above are counted by the
                date of an attendance session. These are counted by the day a check-in arrived. They
                are not two views of one thing.
            </p>
            <p class="mb-0">
                An anonymous check-in is somebody pressing a button at the door or scanning a QR code
                without signing in. Nothing links one to a person, and nothing here can identify
                anybody.
            </p>
        </div>

        <?php if ($anon['checkins'] === 0): ?>
            <p class="mb-0 text-muted">No anonymous check-ins in this period.</p>
        <?php else: ?>
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100">
                        <div class="card-body py-2">
                            <h4 class="mb-0"><?php echo number_format($anon['checkins']); ?></h4>
                            <small class="text-muted">Check-ins</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100">
                        <div class="card-body py-2">
                            <h4 class="mb-0"><?php echo number_format($anon['people']); ?></h4>
                            <small class="text-muted">People claimed</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100">
                        <div class="card-body py-2">
                            <h4 class="mb-0"><?php echo number_format($anon['groups']); ?></h4>
                            <small class="text-muted">Were a group, not one person</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100">
                        <div class="card-body py-2">
                            <h4 class="mb-0"><?php echo number_format($anon['senders']); ?></h4>
                            <small class="text-muted">Probably different senders</small>
                        </div>
                    </div>
                </div>
            </div>

            <p class="small text-muted">
                How they arrived:
                <span class="badge bg-secondary">Their own phone: <?php echo (int) $anon['bySource']['self']; ?></span>
                <span class="badge bg-secondary">Kiosk at the door: <?php echo (int) $anon['bySource']['kiosk']; ?></span>
                <span class="badge bg-secondary">QR code: <?php echo (int) $anon['bySource']['qr']; ?></span>
            </p>

            <?php if ($reportMonth === 0 && count($anon['byMonth']) > 0): ?>
                <div class="portal-data-list">
                    <div class="portal-data-row portal-data-header d-none d-md-flex">
                        <div class="col-md-4">Month</div>
                        <div class="col-md-2 text-center">Check-ins</div>
                        <div class="col-md-3 text-center">People claimed</div>
                        <div class="col-md-3 text-center">Probably different senders</div>
                    </div>
                    <?php foreach ($anon['byMonth'] as $am): ?>
                        <div class="portal-data-row">
                            <div class="col-12 col-md-4">
                                <span class="d-md-none fw-semibold">Month: </span>
                                <strong><?php echo htmlspecialchars(
                                    $monthNames[(int) $am['month']] ?? (string) $am['month'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?></strong>
                            </div>
                            <div class="col-12 col-md-2 text-md-center">
                                <span class="d-md-none fw-semibold">Check-ins: </span>
                                <?php echo number_format((int) $am['checkins']); ?>
                            </div>
                            <div class="col-12 col-md-3 text-md-center">
                                <span class="d-md-none fw-semibold">People claimed: </span>
                                <?php echo number_format((int) $am['people']); ?>
                            </div>
                            <div class="col-12 col-md-3 text-md-center">
                                <span class="d-md-none fw-semibold">Probably different senders: </span>
                                <?php echo number_format((int) $am['senders']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="small text-muted mt-3 mb-0">
                &ldquo;Probably different senders&rdquo; counts different internet connections, not
                different people. Everybody on the building's own wifi looks like one sender, so the
                real number of people is usually higher. Somebody who comes back on another day is
                counted again on that day.
                <?php if ($anon['sendersIncludeLateArrivals'] === true): ?>
                    At least one day in this period gained check-ins after its figure had already been
                    worked out and stored, so that figure includes late arrivals and may count one
                    visitor twice.
                <?php endif; ?>
                <?php if (App::isAdmin() === true): ?>
                    A spreadsheet of these figures, broken down by event and day, is on the
                    <a href="/attendance">attendance page</a> — that download is for administrators
                    only, whatever this organisation's visibility setting says.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
