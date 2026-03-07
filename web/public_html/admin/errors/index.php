<?php
// Path: public_html/admin/errors/index.php
/**
 * -----------------------------------------------------------------------------
 * Error Log Viewer 🔴
 * -----------------------------------------------------------------------------
 * Admin page displaying error records from tblErrors with filtering by
 * platform, severity, and date range. Supports marking errors as resolved.
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
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

// 📌 Page metadata
$pageTitle   = 'Error Log';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Error Log' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// -----------------------------------------------------------------------------
// 🔧 Handle resolve/unresolve actions (POST)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) === true) {
    Auth::verifyCsrf($_POST['csrf_token'] ?? '');

    $errorId = (int) ($_POST['error_id'] ?? 0);
    $action  = $_POST['action'];

    if ($action === 'resolve' && $errorId > 0) {
        $userId = $_SESSION['user_id'] ?? null;
        $stmt = $mysqli->prepare(
            'UPDATE tblErrors SET isResolved = 1, resolvedAt = NOW(), resolvedByID = ? WHERE errorID = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $userId, $errorId);
            $stmt->execute();
            $stmt->close();
        }
        Logger::activity('ErrorResolved', 'Resolved error #' . $errorId, $userId);
    }

    if ($action === 'unresolve' && $errorId > 0) {
        $stmt = $mysqli->prepare(
            'UPDATE tblErrors SET isResolved = 0, resolvedAt = NULL, resolvedByID = NULL WHERE errorID = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $errorId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // 🔄 Redirect to prevent form resubmission (PRG pattern)
    $redirectUrl = '/admin/errors';
    $qs = http_build_query(array_filter([
        'platform' => $_POST['filter_platform'] ?? '',
        'severity' => $_POST['filter_severity'] ?? '',
        'resolved' => $_POST['filter_resolved'] ?? '',
    ]));
    if ($qs !== '') {
        $redirectUrl .= '?' . $qs;
    }
    header('Location: ' . $redirectUrl);
    exit();
}

// -----------------------------------------------------------------------------
// 🔍 Filter parameters
// -----------------------------------------------------------------------------
$filterPlatform = trim($_GET['platform'] ?? '');
$filterSeverity = trim($_GET['severity'] ?? '');
$filterResolved = trim($_GET['resolved'] ?? '');
$page           = max(1, (int) ($_GET['page'] ?? 1));
$perPage        = 50;
$offset         = ($page - 1) * $perPage;

// 📊 Build dynamic WHERE clause
$conditions = [];
$params     = [];
$types      = '';

if ($filterPlatform !== '') {
    $conditions[] = 'e.errorPlatform = ?';
    $params[]     = $filterPlatform;
    $types       .= 's';
}
if ($filterSeverity !== '') {
    $conditions[] = 'e.errorSeverity = ?';
    $params[]     = $filterSeverity;
    $types       .= 's';
}
if ($filterResolved === '0' || $filterResolved === '1') {
    $conditions[] = 'e.isResolved = ?';
    $params[]     = (int) $filterResolved;
    $types       .= 'i';
}

$where = '';
if (count($conditions) > 0) {
    $where = 'WHERE ' . implode(' AND ', $conditions);
}

// 📋 Count total matching rows
$countSql = 'SELECT COUNT(*) AS cnt FROM tblErrors e ' . $where;
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

// 📋 Fetch error rows
$sql = 'SELECT e.errorID, e.errorPlatform, e.errorSeverity, e.errorCode, e.errorTitle, '
     . 'e.errorDetail, e.userID, e.visitorIP, e.requestURL, e.isResolved, e.resolvedAt, '
     . 'e.resolvedByID, e.createdAt, u.fullName AS userName '
     . 'FROM tblErrors e '
     . 'LEFT JOIN tblUsers u ON u.userID = e.userID '
     . $where . ' '
     . 'ORDER BY e.createdAt DESC '
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

// 📊 Get distinct platforms and severities for filter dropdowns
$platforms  = [];
$severities = [];
$result = $mysqli->query('SELECT DISTINCT errorPlatform FROM tblErrors ORDER BY errorPlatform');
if ($result !== false) {
    while ($r = $result->fetch_assoc()) {
        $platforms[] = $r['errorPlatform'];
    }
}
$result = $mysqli->query('SELECT DISTINCT errorSeverity FROM tblErrors ORDER BY errorSeverity');
if ($result !== false) {
    while ($r = $result->fetch_assoc()) {
        $severities[] = $r['errorSeverity'];
    }
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🔴 Error Log Viewer -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-triangle-exclamation me-2"></i>Error Log</h1>
    <span class="badge bg-secondary"><?php echo number_format($totalRows); ?> record<?php echo $totalRows !== 1 ? 's' : ''; ?></span>
</div>

<!-- 🔍 Filters -->
<form method="get" action="/admin/errors" class="row g-2 mb-4">
    <div class="col-12 col-md-3">
        <select name="platform" class="form-select form-select-sm">
            <option value="">All Platforms</option>
            <?php foreach ($platforms as $p): ?>
                <option value="<?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo ($filterPlatform === $p) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <select name="severity" class="form-select form-select-sm">
            <option value="">All Severities</option>
            <?php foreach ($severities as $s): ?>
                <option value="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo ($filterSeverity === $s) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <select name="resolved" class="form-select form-select-sm">
            <option value="">All Status</option>
            <option value="0" <?php echo ($filterResolved === '0') ? 'selected' : ''; ?>>Unresolved</option>
            <option value="1" <?php echo ($filterResolved === '1') ? 'selected' : ''; ?>>Resolved</option>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
            <i class="fa-solid fa-filter me-1"></i> Filter
        </button>
    </div>
</form>

<!-- 📋 Error list -->
<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i> No errors found matching your filters.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <?php foreach ($rows as $row): ?>
            <?php
            $severityClass = match ($row['errorSeverity']) {
                'Fatal'        => 'danger',
                'Error'        => 'danger',
                'Warning'      => 'warning',
                'Notification' => 'info',
                default        => 'secondary',
            };
            $isResolved = $row['isResolved'] === '1' || (int) $row['isResolved'] === 1;
            ?>
            <div class="portal-data-row <?php echo $isResolved ? 'opacity-50' : ''; ?>">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="badge bg-<?php echo $severityClass; ?> me-1">
                                <?php echo htmlspecialchars($row['errorSeverity'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="badge bg-secondary me-1">
                                <?php echo htmlspecialchars($row['errorPlatform'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php if ($row['errorCode'] !== null && $row['errorCode'] !== ''): ?>
                                <span class="badge bg-outline-secondary border me-1">
                                    Code: <?php echo htmlspecialchars($row['errorCode'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($isResolved === true): ?>
                                <span class="badge bg-success">
                                    <i class="fa-solid fa-check me-1"></i>Resolved
                                </span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted text-nowrap ms-2">
                            #<?php echo (int) $row['errorID']; ?>
                            &middot;
                            <?php echo htmlspecialchars($row['createdAt'], ENT_QUOTES, 'UTF-8'); ?>
                        </small>
                    </div>

                    <!-- 📝 Error title -->
                    <div class="fw-semibold mb-1">
                        <?php echo htmlspecialchars($row['errorTitle'] ?? '(no title)', ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <!-- 📝 Error detail (collapsible) -->
                    <?php if ($row['errorDetail'] !== null && $row['errorDetail'] !== ''): ?>
                        <div class="mb-2">
                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#detail-<?php echo (int) $row['errorID']; ?>">
                                <i class="fa-solid fa-code me-1"></i> Detail
                            </button>
                            <div class="collapse mt-2" id="detail-<?php echo (int) $row['errorID']; ?>">
                                <pre class="bg-body-secondary p-3 rounded small" style="max-height:300px;overflow:auto;"><?php echo htmlspecialchars($row['errorDetail'], ENT_QUOTES, 'UTF-8'); ?></pre>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 📌 Metadata row -->
                    <div class="d-flex flex-wrap gap-3 small text-muted">
                        <?php if ($row['userName'] !== null): ?>
                            <span><i class="fa-solid fa-user me-1"></i><?php echo htmlspecialchars($row['userName'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if ($row['visitorIP'] !== null && $row['visitorIP'] !== ''): ?>
                            <span><i class="fa-solid fa-globe me-1"></i><?php echo htmlspecialchars($row['visitorIP'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if ($row['requestURL'] !== null && $row['requestURL'] !== ''): ?>
                            <span><i class="fa-solid fa-link me-1"></i><?php echo htmlspecialchars($row['requestURL'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- 🔧 Actions -->
                    <div class="mt-2">
                        <form method="post" action="/admin/errors" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="error_id" value="<?php echo (int) $row['errorID']; ?>">
                            <input type="hidden" name="filter_platform" value="<?php echo htmlspecialchars($filterPlatform, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="filter_severity" value="<?php echo htmlspecialchars($filterSeverity, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="filter_resolved" value="<?php echo htmlspecialchars($filterResolved, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if ($isResolved === false): ?>
                                <button type="submit" name="action" value="resolve" class="btn btn-sm btn-outline-success">
                                    <i class="fa-solid fa-check me-1"></i> Mark Resolved
                                </button>
                            <?php else: ?>
                                <button type="submit" name="action" value="unresolve" class="btn btn-sm btn-outline-warning">
                                    <i class="fa-solid fa-rotate-left me-1"></i> Unresolve
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 📖 Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav aria-label="Error log pagination" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php
                $baseUrl = '/admin/errors?' . http_build_query(array_filter([
                    'platform' => $filterPlatform,
                    'severity' => $filterSeverity,
                    'resolved' => $filterResolved,
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
