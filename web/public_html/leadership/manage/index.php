<?php
// Path: public_html/leadership/manage/index.php
/**
 * -----------------------------------------------------------------------------
 * Leadership — Manage Roles ⚙️
 * -----------------------------------------------------------------------------
 * Admin page for managing leadership role definitions. Allows admins to:
 *   - View all roles with assignment counts
 *   - Create new roles
 *   - Activate/deactivate roles
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
$pageTitle   = 'Manage Leadership Roles';
$pageSection = 'leadership';
$breadcrumbs = ['Dashboard' => '/', 'Leadership' => '/leadership', 'Manage Roles' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 📋 Flash message
$flashMsg  = $_SESSION['admin_flash_msg']  ?? '';
$flashType = $_SESSION['admin_flash_type'] ?? 'info';
unset($_SESSION['admin_flash_msg'], $_SESSION['admin_flash_type']);

// 🌐 Multi-site scope
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 📋 Fetch all roles (including inactive, for admin view)
// -----------------------------------------------------------------------------
$allRoles = [];
$stmtRoles = $mysqli->prepare(
    'SELECT r.*, '
    . '(SELECT COUNT(*) FROM tblLeadershipAssignments a '
    . '  WHERE a.roleID = r.roleID AND a.isActive = 1 AND a.siteID = ?) AS assignmentCount, '
    . '(SELECT COUNT(*) FROM tblLeadershipAssignments a '
    . '  WHERE a.roleID = r.roleID AND a.isActive = 1 AND a.siteID = ? '
    . '  AND (a.endDate IS NULL OR a.endDate >= CURDATE())) AS currentCount '
    . 'FROM tblLeadershipRoles r '
    . 'WHERE r.siteID = ? '
    . 'ORDER BY r.sortOrder, r.roleName'
);
if ($stmtRoles !== false) {
    $stmtRoles->bind_param('iii', $siteId, $siteId, $siteId);
    $stmtRoles->execute();
    $resultRoles = $stmtRoles->get_result();
    while ($r = $resultRoles->fetch_assoc()) {
        $allRoles[] = $r;
    }
    $stmtRoles->close();
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- ⚙️ Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-gear me-2"></i>Manage Leadership Roles</h1>
    <div class="d-flex gap-2">
        <a href="/leadership" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back
        </a>
        <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#createRoleForm">
            <i class="fa-solid fa-plus me-1"></i> Add Role
        </button>
    </div>
</div>

<!-- ➕ Create role form (collapsible) -->
<div class="collapse mb-4" id="createRoleForm">
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Add New Leadership Role</h5></div>
        <div class="card-body">
            <form method="post" action="/leadership/manage/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create">

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label for="roleName" class="form-label">Role Name <span class="text-danger">*</span></label>
                        <input type="text" name="roleName" id="roleName" class="form-control"
                               placeholder="e.g. Associate Pastor" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="description" class="form-label">Description <small class="text-muted">(optional)</small></label>
                        <input type="text" name="description" id="description" class="form-control"
                               placeholder="Short description of this role">
                    </div>
                    <div class="col-12 col-md-2">
                        <label for="sortOrder" class="form-label">Sort Order</label>
                        <input type="number" name="sortOrder" id="sortOrder" class="form-control"
                               value="0" min="0">
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>Add Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 📋 Roles list -->
<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-3">Role Name</div>
        <div class="col-md-3">Description</div>
        <div class="col-md-1 text-center">Order</div>
        <div class="col-md-1 text-center">Current</div>
        <div class="col-md-1 text-center">Total</div>
        <div class="col-md-1 text-center">Status</div>
        <div class="col-md-2 text-end">Actions</div>
    </div>

    <?php if (count($allRoles) === 0): ?>
        <div class="portal-data-row">
            <div class="col-12 text-center text-muted py-3">No leadership roles defined yet.</div>
        </div>
    <?php endif; ?>

    <?php foreach ($allRoles as $role): ?>
        <?php $isActive = ($role['isActive'] === '1' || (int) $role['isActive'] === 1); ?>
        <div class="portal-data-row <?php echo $isActive === false ? 'opacity-50' : ''; ?>">
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Role: </span>
                <strong><i class="fa-solid fa-crown me-1" style="color:#d4af37;"></i><?php echo htmlspecialchars($role['roleName'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Description: </span>
                <small><?php echo htmlspecialchars($role['description'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-1 text-md-center">
                <span class="d-md-none fw-semibold">Order: </span>
                <?php echo (int) $role['sortOrder']; ?>
            </div>
            <div class="col-12 col-md-1 text-md-center">
                <span class="d-md-none fw-semibold">Current: </span>
                <span class="badge bg-success"><?php echo (int) $role['currentCount']; ?></span>
            </div>
            <div class="col-12 col-md-1 text-md-center">
                <span class="d-md-none fw-semibold">Total: </span>
                <span class="badge bg-info"><?php echo (int) $role['assignmentCount']; ?></span>
            </div>
            <div class="col-12 col-md-1 text-md-center">
                <span class="d-md-none fw-semibold">Status: </span>
                <?php if ($isActive === true): ?>
                    <span class="badge bg-success">Active</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <form method="post" action="/leadership/manage/save" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="roleID" value="<?php echo (int) $role['roleID']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-<?php echo $isActive === true ? 'warning' : 'success'; ?>"
                            title="<?php echo $isActive === true ? 'Deactivate' : 'Activate'; ?>">
                        <i class="fa-solid fa-<?php echo $isActive === true ? 'eye-slash' : 'eye'; ?>"></i>
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
