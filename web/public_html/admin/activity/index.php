<?php
// Path: public_html/admin/activity/index.php
/**
 * -----------------------------------------------------------------------------
 * Activity Log Viewer 📋
 * -----------------------------------------------------------------------------
 * Admin page displaying activity/audit records from tblActivityLogs with
 * filtering by activity type and user. Shows user actions across the portal.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Router;

// 📌 Page metadata
$pageTitle   = 'Activity Log';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Activity Log' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// -----------------------------------------------------------------------------
// 🔍 Filter parameters
// -----------------------------------------------------------------------------
$filterType = trim($_GET['type'] ?? '');
$filterUser = trim($_GET['user'] ?? '');
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 50;
$offset     = ($page - 1) * $perPage;

// 📊 Build dynamic WHERE clause
$conditions = [];
$params     = [];
$types      = '';

if ($filterType !== '') {
    $conditions[] = 'a.activityType = ?';
    $params[]     = $filterType;
    $types       .= 's';
}
if ($filterUser !== '') {
    $conditions[] = 'a.userID = ?';
    $params[]     = (int) $filterUser;
    $types       .= 'i';
}

$where = '';
if (count($conditions) > 0) {
    $where = 'WHERE ' . implode(' AND ', $conditions);
}

// 📋 Count total matching rows
$countSql  = 'SELECT COUNT(*) AS cnt FROM tblActivityLogs a ' . $where;
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

// 📋 Fetch activity rows
$sql = 'SELECT a.logID, a.userID, a.activityType, a.activityDescription, '
     . 'a.sessionID, a.visitorIP, a.userAgent, a.timestamp, '
     . 'u.fullName AS userName '
     . 'FROM tblActivityLogs a '
     . 'LEFT JOIN tblUsers u ON u.userID = a.userID '
     . $where . ' '
     . 'ORDER BY a.timestamp DESC '
     . 'LIMIT ? OFFSET ?';

$fetchTypes  = $types . 'ii';
$fetchParams = array_merge($params, [$perPage, $offset]);

$rows = [];
$stmt = $mysqli->prepare($sql);
if ($stmt !== false) {
    $stmt->bind_param($fetchTypes, ...$fetchParams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

// 📊 Get distinct activity types for filter dropdown
$activityTypes = [];
$result = $mysqli->query('SELECT DISTINCT activityType FROM tblActivityLogs ORDER BY activityType');
if ($result !== false) {
    while ($r = $result->fetch_assoc()) {
        $activityTypes[] = $r['activityType'];
    }
}

// 👥 Get user list for filter dropdown
$userList = [];
$result = $mysqli->query(
    'SELECT DISTINCT a.userID, u.fullName FROM tblActivityLogs a '
    . 'LEFT JOIN tblUsers u ON u.userID = a.userID '
    . 'WHERE a.userID IS NOT NULL ORDER BY u.fullName'
);
if ($result !== false) {
    while ($r = $result->fetch_assoc()) {
        $userList[] = $r;
    }
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📋 Activity Log Viewer -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-clock-rotate-left me-2"></i>Activity Log</h1>
    <span class="badge bg-secondary"><?php echo number_format($totalRows); ?> record<?php echo $totalRows !== 1 ? 's' : ''; ?></span>
</div>

<!-- 🔍 Filters -->
<form method="get" action="/admin/activity" class="row g-2 mb-4">
    <div class="col-12 col-md-4">
        <select name="type" class="form-select form-select-sm">
            <option value="">All Activity Types</option>
            <?php foreach ($activityTypes as $t): ?>
                <option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo ($filterType === $t) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-4">
        <select name="user" class="form-select form-select-sm">
            <option value="">All Users</option>
            <?php foreach ($userList as $u): ?>
                <option value="<?php echo (int) $u['userID']; ?>"
                    <?php echo ($filterUser === (string) $u['userID']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($u['fullName'] ?? ('User #' . $u['userID']), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-4">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
            <i class="fa-solid fa-filter me-1"></i> Filter
        </button>
    </div>
</form>

<!-- 📋 Activity list -->
<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i> No activity records found matching your filters.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <!-- 🏷️ Header row (visible on md+ screens) -->
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-2">Timestamp</div>
            <div class="col-md-2">Type</div>
            <div class="col-md-2">User</div>
            <div class="col-md-4">Description</div>
            <div class="col-md-2">IP Address</div>
        </div>

        <?php foreach ($rows as $row): ?>
            <?php
            $typeClass = match (true) {
                str_contains($row['activityType'] ?? '', 'Login')  => 'success',
                str_contains($row['activityType'] ?? '', 'Logout') => 'info',
                str_contains($row['activityType'] ?? '', 'Denied') => 'danger',
                str_contains($row['activityType'] ?? '', 'Error')  => 'warning',
                default                                            => 'primary',
            };
            ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Time: </span>
                    <small><?php echo htmlspecialchars($row['timestamp'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Type: </span>
                    <span class="badge bg-<?php echo $typeClass; ?>">
                        <?php echo htmlspecialchars($row['activityType'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">User: </span>
                    <?php if ($row['userName'] !== null): ?>
                        <?php echo htmlspecialchars($row['userName'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php elseif ($row['userID'] !== null): ?>
                        <span class="text-muted">User #<?php echo (int) $row['userID']; ?></span>
                    <?php else: ?>
                        <span class="text-muted">System</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-4">
                    <span class="d-md-none fw-semibold">Description: </span>
                    <?php echo htmlspecialchars($row['activityDescription'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">IP: </span>
                    <small class="text-muted"><?php echo htmlspecialchars($row['visitorIP'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 📖 Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav aria-label="Activity log pagination" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php
                $baseUrl = '/admin/activity?' . http_build_query(array_filter([
                    'type' => $filterType,
                    'user' => $filterUser,
                ]));
                $separator = (str_contains($baseUrl, '?') === true && str_contains($baseUrl, '=') === true) ? '&' : '?';
                ?>
                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $separator . 'page=' . ($page - 1), ENT_QUOTES, 'UTF-8'); ?>">
                        &laquo; Previous
                    </a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i <= 3 || $i >= $totalPages - 2 || abs($i - $page) <= 1): ?>
                        <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $separator . 'page=' . $i, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php elseif ($i === 4 || $i === $totalPages - 3): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                <?php endfor; ?>
                <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $separator . 'page=' . ($page + 1), ENT_QUOTES, 'UTF-8'); ?>">
                        Next &raquo;
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
