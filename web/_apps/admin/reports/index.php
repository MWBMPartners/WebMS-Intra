<?php
// Path: public_html/admin/reports/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Reporting / Analytics Dashboard
 * -----------------------------------------------------------------------------
 * Displays key metrics and trends across all modules. Admin only.
 * Charts rendered client-side using data from data.php JSON endpoint.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/93
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\I18n;
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

$siteId = Site::id();

// 📊 Summary stats
$stats = [];

// 📊 Total users
$s1 = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUserSites WHERE siteID = ? AND isActive = 1');
if ($s1 !== false) {
    $s1->bind_param('i', $siteId);
    $s1->execute();
    $stats['totalUsers'] = (int) ($s1->get_result()->fetch_assoc()['cnt'] ?? 0);
    $s1->close();
}

// 📊 Total expense claims & amount
$s2 = $mysqli->prepare(
    'SELECT COUNT(*) AS cnt, COALESCE(SUM(totalAmount), 0) AS total FROM tblExpenseClaims WHERE siteID = ?'
);
if ($s2 !== false) {
    $s2->bind_param('i', $siteId);
    $s2->execute();
    $r2 = $s2->get_result()->fetch_assoc();
    $stats['totalClaims']  = (int) ($r2['cnt'] ?? 0);
    $stats['totalExpenses'] = (float) ($r2['total'] ?? 0);
    $s2->close();
}

// 📊 Total events
$s3 = $mysqli->prepare(
    'SELECT COUNT(*) AS cnt FROM tblEvents WHERE siteID = ? AND isDeleted = 0'
);
if ($s3 !== false) {
    $s3->bind_param('i', $siteId);
    $s3->execute();
    $stats['totalEvents'] = (int) ($s3->get_result()->fetch_assoc()['cnt'] ?? 0);
    $s3->close();
}

// 📊 Total attendance sessions
// The headcount isn't a column of tblAttendanceSessions itself — a session
// can have several counted groups (Adults, Children, Visitors, ...), each
// its own row in tblAttendanceCounts, linked back by sessionID. Reading
// "headcount" straight off tblAttendanceSessions has never worked; MySQL
// refused the query outright (unknown column), and because this app's
// database connection is set to throw on that kind of error
// (MYSQLI_REPORT_STRICT in bootstrap.php), the whole Reports page crashed
// with a 500 on every single visit (#501). The correct shape — LEFT JOIN
// to tblAttendanceCounts and SUM its headcount column — already exists
// elsewhere in the Attendance app (see web/_apps/attendance/index.php's
// "Quick stats" query).
// 🛡️ The first fix (round 1) copied that shape but left out its
// "s.isDeleted = 0" filter. Deleting a session in the Attendance app only
// marks it deleted; the row and its headcount rows stay in the database.
// Without this filter, a session someone deleted keeps inflating this card
// forever. Round 2 (review gap, #501) adds the filter so this really does
// mirror the Quick stats query, not just resemble it.
$s4 = $mysqli->prepare(
    'SELECT COUNT(DISTINCT s.sessionID) AS cnt, COALESCE(SUM(c.headcount), 0) AS heads '
    . 'FROM tblAttendanceSessions s '
    . 'LEFT JOIN tblAttendanceCounts c ON c.sessionID = s.sessionID '
    . 'WHERE s.siteID = ? AND s.isDeleted = 0'
);
if ($s4 !== false) {
    $s4->bind_param('i', $siteId);
    $s4->execute();
    $r4 = $s4->get_result()->fetch_assoc();
    $stats['totalSessions']    = (int) ($r4['cnt'] ?? 0);
    $stats['totalAttendance']  = (int) ($r4['heads'] ?? 0);
    $s4->close();
}

// 📊 Monthly activity (last 12 months)
$monthlyActivity = [];
// tblActivityLogs has no "createdAt" column — the audit-trail timestamp is
// called `timestamp` (see full_schema.sql). Backticked here because
// "timestamp" is also a MySQL keyword (a non-reserved one, so the backticks
// are not strictly required, but they make it unmistakably a column name;
// an earlier version of this comment wrongly called it reserved). Same crash as the attendance
// query above: the prepare() itself throws under MYSQLI_REPORT_STRICT, so
// this took the whole Reports page down with a 500 on every visit (#501).
// 🏢 Only this organisation's rows. This used to add "OR siteID IS NULL".
// A blank siteID is never written: Logger::activity() always stores
// Site::id(), which is never empty, and migration 015 filled in the old rows.
// A row only becomes blank when its organisation is deleted, because the
// column's link to tblSites is ON DELETE SET NULL. Such rows belong to no
// current organisation, but they were counted in every organisation's
// charts. The on-screen activity viewer (admin/activity/index.php) already
// leaves them out for organisation administrators. Nothing in the portal
// deletes an organisation today, so on current data this changes nothing.
$ma = $mysqli->prepare(
    'SELECT DATE_FORMAT(`timestamp`, \'%Y-%m\') AS month, COUNT(*) AS cnt '
    . 'FROM tblActivityLogs WHERE siteID = ? '
    . 'AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 12 MONTH) '
    . 'GROUP BY month ORDER BY month'
);
if ($ma !== false) {
    $ma->bind_param('i', $siteId);
    $ma->execute();
    $maResult = $ma->get_result();
    while ($maRow = $maResult->fetch_assoc()) {
        $monthlyActivity[] = $maRow;
    }
    $ma->close();
}

// 📊 Expense claims by status
$expenseByStatus = [];
$es = $mysqli->prepare(
    'SELECT status, COUNT(*) AS cnt FROM tblExpenseClaims WHERE siteID = ? GROUP BY status ORDER BY status'
);
if ($es !== false) {
    $es->bind_param('i', $siteId);
    $es->execute();
    $esResult = $es->get_result();
    while ($esRow = $esResult->fetch_assoc()) {
        $expenseByStatus[] = $esRow;
    }
    $es->close();
}

// 📊 Top activity types (last 30 days)
$topActivities = [];
// Same fix as the monthly-activity query above — tblActivityLogs's
// timestamp column is called `timestamp`, not createdAt (#501). Also only
// this organisation's rows, for the reason given above that query.
$ta = $mysqli->prepare(
    'SELECT activityType, COUNT(*) AS cnt FROM tblActivityLogs '
    . 'WHERE siteID = ? AND `timestamp` >= DATE_SUB(NOW(), INTERVAL 30 DAY) '
    . 'GROUP BY activityType ORDER BY cnt DESC LIMIT 10'
);
if ($ta !== false) {
    $ta->bind_param('i', $siteId);
    $ta->execute();
    $taResult = $ta->get_result();
    while ($taRow = $taResult->fetch_assoc()) {
        $topActivities[] = $taRow;
    }
    $ta->close();
}

// 📌 Page metadata
$pageTitle   = 'Reports & Analytics';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Reports' => ''];

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📊 Reports Dashboard -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-chart-bar me-2"></i>Reports & Analytics</h1>
    <a href="/admin/reports/builder" class="btn btn-primary">
        <i class="fa-solid fa-table-list me-1"></i>Custom reports
    </a>
</div>

<!-- 📊 Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center border-primary">
            <div class="card-body">
                <div class="h3 mb-0 text-primary"><?php echo number_format($stats['totalUsers'] ?? 0); ?></div>
                <small class="text-muted">Active Users</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-success">
            <div class="card-body">
                <div class="h3 mb-0 text-success"><?php echo number_format($stats['totalEvents'] ?? 0); ?></div>
                <small class="text-muted">Events</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-warning">
            <div class="card-body">
                <div class="h3 mb-0 text-warning"><?php echo number_format($stats['totalClaims'] ?? 0); ?></div>
                <small class="text-muted">Expense Claims</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-info">
            <div class="card-body">
                <div class="h3 mb-0 text-info"><?php echo number_format($stats['totalAttendance'] ?? 0); ?></div>
                <small class="text-muted">Total Attendance</small>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- 📊 Expense Claims by Status -->
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-receipt me-2"></i>Expense Claims by Status</h5></div>
            <div class="card-body">
                <?php if (count($expenseByStatus) === 0): ?>
                    <p class="text-muted">No expense claims data.</p>
                <?php else: ?>
                    <?php
                    $statusColors = ['Pending' => 'warning', 'Approved' => 'info', 'Rejected' => 'danger', 'Reimbursed' => 'success'];
                    $totalClaimsSum = array_sum(array_column($expenseByStatus, 'cnt'));
                    ?>
                    <?php foreach ($expenseByStatus as $es): ?>
                        <?php
                        $pct = $totalClaimsSum > 0 ? round(((int) $es['cnt'] / $totalClaimsSum) * 100) : 0;
                        $bsColor = $statusColors[$es['status']] ?? 'secondary';
                        ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small">
                                <span><?php echo htmlspecialchars($es['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span><?php echo (int) $es['cnt']; ?> (<?php echo $pct; ?>%)</span>
                            </div>
                            <div class="progress" style="height:8px;">
                                <div class="progress-bar bg-<?php echo $bsColor; ?>" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <p class="small text-muted mt-3 mb-0">
                        Total value: <strong>&pound;<?php echo number_format($stats['totalExpenses'] ?? 0, 2); ?></strong>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 📊 Top Activity Types (30 days) -->
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-chart-line me-2"></i>Top Activity (30 days)</h5></div>
            <div class="card-body">
                <?php if (count($topActivities) === 0): ?>
                    <p class="text-muted">No activity data.</p>
                <?php else: ?>
                    <?php $maxAct = max(array_column($topActivities, 'cnt')); ?>
                    <?php foreach ($topActivities as $ta): ?>
                        <?php $actPct = $maxAct > 0 ? round(((int) $ta['cnt'] / $maxAct) * 100) : 0; ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small">
                                <span><code><?php echo htmlspecialchars($ta['activityType'], ENT_QUOTES, 'UTF-8'); ?></code></span>
                                <span><?php echo number_format((int) $ta['cnt']); ?></span>
                            </div>
                            <div class="progress" style="height:6px;">
                                <div class="progress-bar bg-primary" style="width:<?php echo $actPct; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 📊 Monthly Activity Trend -->
<div class="card mt-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-chart-area me-2"></i>Monthly Activity (12 months)</h5></div>
    <div class="card-body">
        <?php if (count($monthlyActivity) === 0): ?>
            <p class="text-muted">No activity data available.</p>
        <?php else: ?>
            <?php $maxMonth = max(array_column($monthlyActivity, 'cnt')); ?>
            <div class="d-flex align-items-end gap-1" style="height:200px;">
                <?php foreach ($monthlyActivity as $m): ?>
                    <?php $barPct = $maxMonth > 0 ? round(((int) $m['cnt'] / $maxMonth) * 100) : 0; ?>
                    <div class="d-flex flex-column align-items-center flex-grow-1" title="<?php echo htmlspecialchars($m['month'] . ': ' . $m['cnt'] . ' actions', ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="bg-primary rounded-top w-100" style="height:<?php echo max(2, $barPct); ?>%;min-height:4px;"></div>
                        <small class="text-muted mt-1" style="font-size:.65rem;"><?php echo htmlspecialchars(substr($m['month'], 5), ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
