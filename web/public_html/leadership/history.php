<?php
// Path: public_html/leadership/history.php
/**
 * -----------------------------------------------------------------------------
 * Leadership — Historical Records 📜
 * -----------------------------------------------------------------------------
 * Shows all leadership assignments including past (ended) and deactivated ones.
 * Allows admins to see who held roles historically, filter by role, and
 * edit or remove assignments. Admin-only page.
 *
 * @package   Portal\Leadership
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/38
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Leadership History';
$pageSection = 'leadership';
$breadcrumbs = ['Dashboard' => '/', 'Leadership' => '/leadership', 'History' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 📋 Flash message
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 🌐 Multi-site scope
$siteId = Site::id();

// 📋 Optional role filter
$filterRoleID = (int) ($_GET['roleID'] ?? 0);

// 📋 Fetch all roles for filter dropdown
$roles = [];
$stmtRoles = $mysqli->prepare(
    'SELECT roleID, roleName FROM tblLeadershipRoles WHERE siteID = ? ORDER BY sortOrder, roleName'
);
if ($stmtRoles !== false) {
    $stmtRoles->bind_param('i', $siteId);
    $stmtRoles->execute();
    $resultRoles = $stmtRoles->get_result();
    while ($r = $resultRoles->fetch_assoc()) {
        $roles[] = $r;
    }
    $stmtRoles->close();
}

// -----------------------------------------------------------------------------
// 📋 Fetch all assignments (current + historical)
// -----------------------------------------------------------------------------
$assignments = [];
$sql = 'SELECT a.assignmentID, a.roleID, a.userID, a.personName, a.personEmail, '
    . 'a.startDate, a.endDate, a.notes, a.isActive, a.createdAt, '
    . 'r.roleName, u.fullName AS userName, u.emailAddress AS userEmail '
    . 'FROM tblLeadershipAssignments a '
    . 'INNER JOIN tblLeadershipRoles r ON r.roleID = a.roleID '
    . 'LEFT JOIN tblUsers u ON u.userID = a.userID '
    . 'WHERE a.siteID = ?';

$params = [$siteId];
$types  = 'i';

if ($filterRoleID > 0) {
    $sql   .= ' AND a.roleID = ?';
    $params[] = $filterRoleID;
    $types  .= 'i';
}

$sql .= ' ORDER BY a.isActive DESC, a.endDate DESC, a.startDate DESC';

$stmtAll = $mysqli->prepare($sql);
if ($stmtAll !== false) {
    $stmtAll->bind_param($types, ...$params);
    $stmtAll->execute();
    $resultAll = $stmtAll->get_result();
    while ($a = $resultAll->fetch_assoc()) {
        $a['displayName']  = $a['userName'] ?? $a['personName'] ?? 'Unknown';
        $a['displayEmail'] = $a['userEmail'] ?? $a['personEmail'] ?? '';
        $assignments[] = $a;
    }
    $stmtAll->close();
}

$today = date('Y-m-d');

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 📜 Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-clock-rotate-left me-2"></i>Leadership History</h1>
    <a href="/leadership" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back
    </a>
</div>

<!-- 🔍 Filter by role -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="get" action="/leadership/history" class="row g-2 align-items-end">
            <div class="col-auto">
                <label for="filterRole" class="form-label mb-0 small">Filter by role:</label>
            </div>
            <div class="col-auto">
                <select name="roleID" id="filterRole" class="form-select form-select-sm">
                    <option value="0">— All roles —</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo (int) $role['roleID']; ?>"
                            <?php echo ($filterRoleID === (int) $role['roleID']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($role['roleName'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <?php if ($filterRoleID > 0): ?>
                    <a href="/leadership/history" class="btn btn-sm btn-outline-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- 📋 Assignments list -->
<?php if (count($assignments) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        No leadership assignments found<?php echo $filterRoleID > 0 ? ' for this role' : ''; ?>.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-2">Role</div>
            <div class="col-md-3">Person</div>
            <div class="col-md-2">Start</div>
            <div class="col-md-2">End</div>
            <div class="col-md-1 text-center">Status</div>
            <div class="col-md-2 text-end">Actions</div>
        </div>

        <?php foreach ($assignments as $assign): ?>
            <?php
            // 📌 Determine status
            $isCurrent = (int) $assign['isActive'] === 1
                && ($assign['endDate'] === null || $assign['endDate'] >= $today);
            $isPast = (int) $assign['isActive'] === 1
                && $assign['endDate'] !== null && $assign['endDate'] < $today;
            $isRemoved = (int) $assign['isActive'] === 0;
            ?>
            <div class="portal-data-row <?php echo $isRemoved === true ? 'opacity-50' : ''; ?>">
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Role: </span>
                    <strong><?php echo htmlspecialchars($assign['roleName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="col-12 col-md-3">
                    <span class="d-md-none fw-semibold">Person: </span>
                    <?php echo htmlspecialchars($assign['displayName'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($assign['displayEmail'] !== ''): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($assign['displayEmail'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Start: </span>
                    <?php echo $assign['startDate'] !== null ? htmlspecialchars(\Portal\Core\I18n::formatDate($assign['startDate']), ENT_QUOTES, 'UTF-8') : '—'; ?>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">End: </span>
                    <?php echo $assign['endDate'] !== null ? htmlspecialchars(\Portal\Core\I18n::formatDate($assign['endDate']), ENT_QUOTES, 'UTF-8') : '<span class="text-muted">Ongoing</span>'; ?>
                </div>
                <div class="col-12 col-md-1 text-md-center">
                    <span class="d-md-none fw-semibold">Status: </span>
                    <?php if ($isCurrent === true): ?>
                        <span class="badge bg-success">Current</span>
                    <?php elseif ($isPast === true): ?>
                        <span class="badge bg-secondary">Past</span>
                    <?php else: ?>
                        <span class="badge bg-danger">Removed</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                    <a href="/leadership/assign?id=<?php echo (int) $assign['assignmentID']; ?>"
                       class="btn btn-sm btn-outline-primary me-1" title="Edit">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                    <?php if ((int) $assign['isActive'] === 1): ?>
                        <form method="post" action="/leadership/delete" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="assignmentID" value="<?php echo (int) $assign['assignmentID']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('Remove this assignment?');" title="Remove">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
