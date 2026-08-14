<?php
// Path: public_html/admin/audit/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Audit Trail Viewer
 * -----------------------------------------------------------------------------
 * Displays before/after change history for database records. Admin only.
 * Gained a `source` (session/apikey) badge + bearer key-prefix lookup in
 * #323 Phase 2 — LEFT JOINs tblApiKeys on tblAuditTrail.apiKeyID.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/91
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/323
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

// 📌 Page metadata
$pageTitle   = 'Audit Trail';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Audit Trail' => ''];

$siteId = Site::id();

// 📊 Filters
$filterTable  = trim($_GET['table'] ?? '');
$filterAction = trim($_GET['action'] ?? '');
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

// 📋 Build query
$where  = 'WHERE (a.siteID = ? OR a.siteID IS NULL)';
$params = [$siteId];
$types  = 'i';

if ($filterTable !== '') {
    $where  .= ' AND a.tableName = ?';
    $params[] = $filterTable;
    $types   .= 's';
}
if ($filterAction !== '') {
    $where  .= ' AND a.action = ?';
    $params[] = $filterAction;
    $types   .= 's';
}

// 📊 Count total
$cntSql = 'SELECT COUNT(*) AS cnt FROM tblAuditTrail a ' . $where;
$cntStmt = $mysqli->prepare($cntSql);
if ($cntStmt !== false) {
    $cntStmt->bind_param($types, ...$params);
    $cntStmt->execute();
    $totalItems = (int) ($cntStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $cntStmt->close();
} else {
    $totalItems = 0;
}
$totalPages = max(1, (int) ceil($totalItems / $perPage));

// 📋 Fetch audit entries — LEFT JOIN tblApiKeys so a bearer-authenticated row
//    (a.apiKeyID IS NOT NULL) can show the key's visible prefix (#323 Phase 2).
//    Deliberately NOT filtered by siteID (keys are never hard-deleted, but a
//    key can outlive/precede the audit row's own site scoping); the join is
//    purely cosmetic display, the audit row's own siteID gate is unchanged.
$entries = [];
$sql = 'SELECT a.*, u.fullName AS userName, k.keyPrefix AS apiKeyPrefix '
    . 'FROM tblAuditTrail a '
    . 'LEFT JOIN tblUsers u ON u.userID = a.userID '
    . 'LEFT JOIN tblApiKeys k ON k.keyID = a.apiKeyID '
    . $where . ' ORDER BY a.createdAt DESC LIMIT ? OFFSET ?';
$allParams = $params;
$allParams[] = $perPage;
$allParams[] = $offset;
$allTypes    = $types . 'ii';

$stmt = $mysqli->prepare($sql);
if ($stmt !== false) {
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $entries[] = $row;
    }
    $stmt->close();
}

// 📋 Get distinct table names for filter
$tables = [];
$tStmt = $mysqli->prepare(
    'SELECT DISTINCT tableName FROM tblAuditTrail WHERE siteID = ? OR siteID IS NULL ORDER BY tableName'
);
if ($tStmt !== false) {
    $tStmt->bind_param('i', $siteId);
    $tStmt->execute();
    $tResult = $tStmt->get_result();
    while ($tRow = $tResult->fetch_assoc()) {
        $tables[] = $tRow['tableName'];
    }
    $tStmt->close();
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📋 Audit Trail -->
<h1 class="mb-4"><i class="fa-solid fa-shield-halved me-2"></i>Audit Trail</h1>

<!-- 🔍 Filters -->
<form method="get" action="/admin/audit" class="row g-3 mb-4">
    <div class="col-md-4">
        <select class="form-select" name="table">
            <option value="">All Tables</option>
            <?php foreach ($tables as $tbl): ?>
                <option value="<?php echo htmlspecialchars($tbl, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo ($filterTable === $tbl ? 'selected' : ''); ?>>
                    <?php echo htmlspecialchars($tbl, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select class="form-select" name="action">
            <option value="">All Actions</option>
            <?php foreach (['create', 'update', 'delete'] as $act): ?>
                <option value="<?php echo $act; ?>" <?php echo ($filterAction === $act ? 'selected' : ''); ?>>
                    <?php echo htmlspecialchars(ucfirst($act), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-primary w-100">
            <i class="fa-solid fa-filter me-1"></i>Filter
        </button>
    </div>
</form>

<p class="text-muted mb-3"><?php echo number_format($totalItems); ?> entries found</p>

<?php if (count($entries) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-info-circle me-2"></i>No audit trail entries found.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-2">Date</div>
            <div class="col-2">User</div>
            <div class="col-2">Table</div>
            <div class="col-1">Action</div>
            <div class="col-1">Source</div>
            <div class="col-4">Changes</div>
        </div>
        <?php foreach ($entries as $entry): ?>
            <?php
            $actionColors = ['create' => 'success', 'update' => 'warning', 'delete' => 'danger'];
            $aColor = $actionColors[$entry['action']] ?? 'secondary';
            // 🔐 #323 Phase 2 — auth channel the change arrived through. Older
            //    rows predate the column and fall back to the 'session' default
            //    baked into tblAuditTrail.source (migration 147).
            $entrySource = (string) ($entry['source'] ?? 'session');
            $isApiKey    = $entrySource === 'apikey';
            ?>
            <div class="portal-data-row">
                <div class="col-2 small">
                    <?php echo htmlspecialchars(I18n::formatDate($entry['createdAt'], 'short'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-2 small">
                    <?php echo htmlspecialchars($entry['userName'] ?? 'System', ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-2 small">
                    <code><?php echo htmlspecialchars($entry['tableName'], ENT_QUOTES, 'UTF-8'); ?></code>
                    <span class="text-muted">#<?php echo (int) $entry['recordID']; ?></span>
                </div>
                <div class="col-1">
                    <span class="badge bg-<?php echo $aColor; ?><?php echo ($entry['action'] === 'update' ? ' text-dark' : ''); ?>">
                        <?php echo htmlspecialchars(ucfirst($entry['action']), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-1 small">
                    <?php if ($isApiKey === true): ?>
                        <span class="badge bg-info text-dark" title="Change made via a bearer API key"><i class="fa-solid fa-key me-1"></i>API key</span>
                        <?php if (!empty($entry['apiKeyPrefix'])): ?>
                            <div class="text-muted"><code><?php echo htmlspecialchars((string) $entry['apiKeyPrefix'], ENT_QUOTES, 'UTF-8'); ?>…</code></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-secondary" title="Change made via an authenticated portal session"><i class="fa-solid fa-user me-1"></i>Session</span>
                    <?php endif; ?>
                </div>
                <div class="col-4 small">
                    <?php if ($entry['changeSet'] !== null): ?>
                        <?php
                        $changes = json_decode($entry['changeSet'], true);
                        if (is_array($changes) === true):
                            foreach (array_slice($changes, 0, 3) as $field => $vals): ?>
                                <div>
                                    <strong><?php echo htmlspecialchars($field, ENT_QUOTES, 'UTF-8'); ?>:</strong>
                                    <span class="text-danger"><?php echo htmlspecialchars(mb_strimwidth((string) ($vals['old'] ?? ''), 0, 40, '...'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <i class="fa-solid fa-arrow-right mx-1 text-muted"></i>
                                    <span class="text-success"><?php echo htmlspecialchars(mb_strimwidth((string) ($vals['new'] ?? ''), 0, 40, '...'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            <?php endforeach;
                            if (count($changes) > 3): ?>
                                <small class="text-muted">+<?php echo count($changes) - 3; ?> more fields</small>
                            <?php endif;
                        endif; ?>
                    <?php elseif ($entry['fieldName'] !== null): ?>
                        <strong><?php echo htmlspecialchars($entry['fieldName'], ENT_QUOTES, 'UTF-8'); ?>:</strong>
                        <?php if ($entry['oldValue'] !== null): ?>
                            <span class="text-danger"><?php echo htmlspecialchars(mb_strimwidth($entry['oldValue'], 0, 50, '...'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if ($entry['newValue'] !== null): ?>
                            <i class="fa-solid fa-arrow-right mx-1 text-muted"></i>
                            <span class="text-success"><?php echo htmlspecialchars(mb_strimwidth($entry['newValue'], 0, 50, '...'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    <?php elseif ($entry['action'] === 'create' && $entry['newValue'] !== null): ?>
                        <span class="text-muted">Record created</span>
                    <?php elseif ($entry['action'] === 'delete' && $entry['oldValue'] !== null): ?>
                        <span class="text-muted">Record deleted</span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 📊 Pagination -->
    <?php if ($totalPages > 1): ?>
        <?php
        $qParams = [];
        if ($filterTable !== '') {
            $qParams['table'] = $filterTable;
        }
        if ($filterAction !== '') {
            $qParams['action'] = $filterAction;
        }
        ?>
        <nav aria-label="Audit trail pagination" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo ($page <= 1 ? 'disabled' : ''); ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($qParams, ['page' => $page - 1])); ?>">Previous</a>
                </li>
                <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
                    <li class="page-item <?php echo ($p === $page ? 'active' : ''); ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($qParams, ['page' => $p])); ?>"><?php echo $p; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php echo ($page >= $totalPages ? 'disabled' : ''); ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($qParams, ['page' => $page + 1])); ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
