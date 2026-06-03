<?php
// Path: public_html/attendance/index.php
/**
 * -----------------------------------------------------------------------------
 * Attendance Tracker — Dashboard & Session Listing 📋
 * -----------------------------------------------------------------------------
 * Main attendance page showing recent attendance sessions with headcount totals.
 * Supports filtering by service type and date range, with pagination.
 * Links to record new attendance and view reports.
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
$pageTitle   = 'Attendance';
$pageSection = 'attendance';
$breadcrumbs = ['Dashboard' => '/', 'Attendance' => ''];

// 🛡️ Ensure session
Auth::ensureSession();

// -----------------------------------------------------------------------------
// 🔍 Filter parameters
// -----------------------------------------------------------------------------
$filterType  = trim($_GET['type'] ?? '');
$filterFrom  = trim($_GET['from'] ?? '');
$filterTo    = trim($_GET['to'] ?? '');
$page        = max(1, (int) ($_GET['page'] ?? 1));
$perPage     = 25;
$offset      = ($page - 1) * $perPage;

// 🌐 Multi-site scope
$siteId = Site::id();

// 📊 Build WHERE clause
$conditions = ['s.isDeleted = 0', 's.siteID = ?'];
$params     = [$siteId];
$types      = 'i';

if ($filterType !== '') {
    $conditions[] = 's.serviceTypeID = ?';
    $params[]     = (int) $filterType;
    $types       .= 'i';
}
if ($filterFrom !== '') {
    $conditions[] = 's.sessionDate >= ?';
    $params[]     = $filterFrom;
    $types       .= 's';
}
if ($filterTo !== '') {
    $conditions[] = 's.sessionDate <= ?';
    $params[]     = $filterTo;
    $types       .= 's';
}

$where = 'WHERE ' . implode(' AND ', $conditions);

// 📋 Count total rows
$countSql  = 'SELECT COUNT(*) AS cnt FROM tblAttendanceSessions s ' . $where;
$totalRows = 0;
if (count($params) > 0) {
    $stmt = $mysqli->prepare($countSql);
    if ($stmt !== false) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalRows = (int) ($row['cnt'] ?? 0);
        $stmt->close();
    }
} else {
    $result = $mysqli->query($countSql);
    if ($result !== false) {
        $row = $result->fetch_assoc();
        $totalRows = (int) ($row['cnt'] ?? 0);
    }
}
$totalPages = max(1, (int) ceil($totalRows / $perPage));

// 📋 Fetch sessions with total headcount
$sql = 'SELECT s.sessionID, s.sessionDate, s.sessionTime, s.notes, '
     . 'st.typeName AS serviceTypeName, st.typeSlug, '
     . 'e.eventName, '
     . 'COALESCE(SUM(c.headcount), 0) AS totalCount, '
     . 'u.fullName AS recorderName '
     . 'FROM tblAttendanceSessions s '
     . 'INNER JOIN tblAttendanceServiceTypes st ON st.serviceTypeID = s.serviceTypeID '
     . 'LEFT JOIN tblEvents e ON e.eventID = s.eventID '
     . 'LEFT JOIN tblAttendanceCounts c ON c.sessionID = s.sessionID '
     . 'LEFT JOIN tblUsers u ON u.userID = s.createdByID '
     . $where . ' '
     . 'GROUP BY s.sessionID '
     . 'ORDER BY s.sessionDate DESC, s.sessionTime DESC '
     . 'LIMIT ? OFFSET ?';

$fetchTypes  = $types . 'ii';
$fetchParams = array_merge($params, [$perPage, $offset]);

$sessions = [];
$stmt = $mysqli->prepare($sql);
if ($stmt !== false) {
    $stmt->bind_param($fetchTypes, ...$fetchParams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $sessions[] = $r;
    }
    $stmt->close();
}

// 📊 Fetch service types for filter dropdown (top-level only)
$serviceTypes = [];
$stmtTypes = $mysqli->prepare(
    'SELECT serviceTypeID, typeName FROM tblAttendanceServiceTypes '
    . 'WHERE isActive = 1 AND parentID IS NULL AND siteID = ? ORDER BY sortOrder, typeName'
);
if ($stmtTypes !== false) {
    $stmtTypes->bind_param('i', $siteId);
    $stmtTypes->execute();
    $resultTypes = $stmtTypes->get_result();
    while ($r = $resultTypes->fetch_assoc()) {
        $serviceTypes[] = $r;
    }
    $stmtTypes->close();
}

// 📊 Quick stats — total sessions this month and overall headcount this month
$statsMonth = date('Y-m');
$monthStats = ['sessions' => 0, 'headcount' => 0];
$stmt = $mysqli->prepare(
    'SELECT COUNT(DISTINCT s.sessionID) AS sessions, COALESCE(SUM(c.headcount), 0) AS headcount '
    . 'FROM tblAttendanceSessions s '
    . 'LEFT JOIN tblAttendanceCounts c ON c.sessionID = s.sessionID '
    . 'WHERE s.isDeleted = 0 AND s.siteID = ? AND s.sessionDate >= ? AND s.sessionDate < DATE_ADD(?, INTERVAL 1 MONTH)'
);
if ($stmt !== false) {
    $monthStart = $statsMonth . '-01';
    $stmt->bind_param('iss', $siteId, $monthStart, $monthStart);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row !== null) {
        $monthStats['sessions']  = (int) $row['sessions'];
        $monthStats['headcount'] = (int) $row['headcount'];
    }
    $stmt->close();
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📋 Attendance Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-clipboard-list me-2"></i>Attendance</h1>
    <div class="d-flex gap-2">
        <?php if (App::isAdmin() === true): ?>
            <a href="/attendance/export?csrf_token=<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
               class="btn btn-outline-success" title="Export CSV">
                <i class="fa-solid fa-file-csv"></i>
            </a>
        <?php endif; ?>
        <a href="/attendance/record" class="btn btn-success">
            <i class="fa-solid fa-plus me-1"></i> Record Attendance
        </a>
        <?php if (App::isAdmin() === true): ?>
            <a href="/attendance/manage" class="btn btn-outline-primary">
                <i class="fa-solid fa-gear me-1"></i> Manage Types
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- 📊 Monthly Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <h3 class="mb-1"><?php echo (int) $monthStats['sessions']; ?></h3>
                <small class="text-muted">Sessions This Month</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <h3 class="mb-1"><?php echo number_format($monthStats['headcount']); ?></h3>
                <small class="text-muted">Total Headcount This Month</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <h3 class="mb-1"><?php echo (int) $totalRows; ?></h3>
                <small class="text-muted">Total Sessions (All Time)</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <a href="/attendance/report" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="fa-solid fa-chart-bar me-1"></i> View Reports
                </a>
                <small class="text-muted d-block mt-1">Trends &amp; Insights</small>
            </div>
        </div>
    </div>
</div>

<!-- 🔍 Filters -->
<form method="get" action="/attendance" class="row g-2 mb-4">
    <div class="col-12 col-md-3">
        <select name="type" class="form-select form-select-sm">
            <option value="">All Service Types</option>
            <?php foreach ($serviceTypes as $st): ?>
                <option value="<?php echo (int) $st['serviceTypeID']; ?>"
                    <?php echo ($filterType === (string) $st['serviceTypeID']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($st['typeName'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <input type="date" name="from" class="form-control form-control-sm"
               value="<?php echo htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8'); ?>" placeholder="From">
    </div>
    <div class="col-6 col-md-2">
        <input type="date" name="to" class="form-control form-control-sm"
               value="<?php echo htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8'); ?>" placeholder="To">
    </div>
    <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
            <i class="fa-solid fa-filter me-1"></i> Filter
        </button>
    </div>
    <?php if ($filterType !== '' || $filterFrom !== '' || $filterTo !== ''): ?>
        <div class="col-12 col-md-2">
            <a href="/attendance" class="btn btn-sm btn-outline-secondary w-100">
                <i class="fa-solid fa-xmark me-1"></i> Clear
            </a>
        </div>
    <?php endif; ?>
</form>

<!-- 📋 Session list -->
<?php if (count($sessions) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        No attendance sessions found. <a href="/attendance/record" class="alert-link">Record your first attendance</a>.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-2">Date</div>
            <div class="col-md-3">Service Type</div>
            <div class="col-md-2">Event</div>
            <div class="col-md-2 text-center">Headcount</div>
            <div class="col-md-1">Recorder</div>
            <div class="col-md-2 text-end">Actions</div>
        </div>

        <?php foreach ($sessions as $sess): ?>
            <?php
            $sessDate = new DateTime($sess['sessionDate']);
            $isToday  = $sessDate->format('Y-m-d') === date('Y-m-d');
            ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Date: </span>
                    <strong><?php echo htmlspecialchars($sessDate->format('D, j M Y'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ($sess['sessionTime'] !== null && $sess['sessionTime'] !== ''): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars(date('g:i A', strtotime($sess['sessionTime'])), ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                    <?php if ($isToday === true): ?>
                        <span class="badge bg-success ms-1">Today</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-3">
                    <span class="d-md-none fw-semibold">Service: </span>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($sess['serviceTypeName'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Event: </span>
                    <small><?php echo htmlspecialchars($sess['eventName'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <div class="col-12 col-md-2 text-md-center">
                    <span class="d-md-none fw-semibold">Headcount: </span>
                    <span class="badge bg-primary fs-6"><?php echo number_format((int) $sess['totalCount']); ?></span>
                </div>
                <div class="col-12 col-md-1">
                    <span class="d-md-none fw-semibold">Recorder: </span>
                    <small><?php echo htmlspecialchars($sess['recorderName'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                    <a href="/attendance/record?edit=<?php echo (int) $sess['sessionID']; ?>"
                       class="btn btn-sm btn-outline-primary me-1" title="Edit">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                    <form method="post" action="/attendance/record/delete" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="sessionID" value="<?php echo (int) $sess['sessionID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                                data-confirm="Delete this attendance record?" data-confirm-destructive="true">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 📖 Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav aria-label="Attendance pagination" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php
                $baseUrl = '/attendance?' . http_build_query(array_filter([
                    'type' => $filterType,
                    'from' => $filterFrom,
                    'to'   => $filterTo,
                ]));
                $sep = '&';
                ?>
                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . ($page - 1), ENT_QUOTES, 'UTF-8'); ?>">&laquo;</a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i <= 3 || $i >= $totalPages - 2 || abs($i - $page) <= 1): ?>
                        <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . $i, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php elseif ($i === 4 || $i === $totalPages - 3): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                <?php endfor; ?>
                <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . ($page + 1), ENT_QUOTES, 'UTF-8'); ?>">&raquo;</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
