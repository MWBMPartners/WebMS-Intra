<?php
// Path: public_html/attendance/manage/index.php
/**
 * -----------------------------------------------------------------------------
 * Attendance Tracker — Manage Service Types ⚙️
 * -----------------------------------------------------------------------------
 * Admin page for managing attendance service types. Allows admins to:
 *   - View all service types in a hierarchical list
 *   - Create new service types (top-level or sub-types)
 *   - Edit existing service types
 *   - Deactivate/reactivate service types
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
use Portal\Core\Router;

// 📌 Page metadata
$pageTitle   = 'Manage Service Types';
$pageSection = 'attendance';
$breadcrumbs = ['Dashboard' => '/', 'Attendance' => '/attendance', 'Manage Types' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 📋 Flash message
$flashMsg  = $_SESSION['admin_flash_msg']  ?? '';
$flashType = $_SESSION['admin_flash_type'] ?? 'info';
unset($_SESSION['admin_flash_msg'], $_SESSION['admin_flash_type']);

// -----------------------------------------------------------------------------
// 📋 Fetch all service types (including inactive, for admin view)
// -----------------------------------------------------------------------------
$allTypes = [];
$result = $mysqli->query(
    'SELECT st.*, '
    . '(SELECT COUNT(*) FROM tblAttendanceSessions s WHERE s.serviceTypeID = st.serviceTypeID AND s.isDeleted = 0) AS sessionCount '
    . 'FROM tblAttendanceServiceTypes st '
    . 'ORDER BY st.sortOrder, st.typeName'
);
if ($result !== false) {
    while ($r = $result->fetch_assoc()) {
        $allTypes[] = $r;
    }
}

// 📊 Separate into top-level and children for hierarchical display
$topLevel = array_filter($allTypes, function ($t) {
    return $t['parentID'] === null;
});

// 📋 Get top-level types for the parent dropdown in create form
$parentOptions = array_filter($allTypes, function ($t) {
    return $t['parentID'] === null;
});

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
    <h1 class="mb-0"><i class="fa-solid fa-gear me-2"></i>Manage Service Types</h1>
    <div class="d-flex gap-2">
        <a href="/attendance" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back
        </a>
        <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#createTypeForm">
            <i class="fa-solid fa-plus me-1"></i> Add Type
        </button>
    </div>
</div>

<!-- ➕ Create service type form (collapsible) -->
<div class="collapse mb-4" id="createTypeForm">
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Add New Service Type</h5></div>
        <div class="card-body">
            <form method="post" action="/attendance/manage/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create">

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label for="typeName" class="form-label">Type Name <span class="text-danger">*</span></label>
                        <input type="text" name="typeName" id="typeName" class="form-control"
                               placeholder="e.g. Wednesday Bible Study" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label for="parentID" class="form-label">Parent Type <small class="text-muted">(optional)</small></label>
                        <select name="parentID" id="parentID" class="form-select">
                            <option value="">— Top-level type —</option>
                            <?php foreach ($parentOptions as $p): ?>
                                <option value="<?php echo (int) $p['serviceTypeID']; ?>">
                                    <?php echo htmlspecialchars($p['typeName'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label for="description" class="form-label">Description <small class="text-muted">(optional)</small></label>
                        <input type="text" name="description" id="description" class="form-control"
                               placeholder="Short description">
                    </div>
                    <div class="col-12 col-md-2">
                        <label for="sortOrder" class="form-label">Sort Order</label>
                        <input type="number" name="sortOrder" id="sortOrder" class="form-control"
                               value="0" min="0">
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>Add Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 📋 Service types list -->
<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-4">Type Name</div>
        <div class="col-md-3">Description</div>
        <div class="col-md-1 text-center">Order</div>
        <div class="col-md-1 text-center">Sessions</div>
        <div class="col-md-1 text-center">Status</div>
        <div class="col-md-2 text-end">Actions</div>
    </div>

    <?php if (count($allTypes) === 0): ?>
        <div class="portal-data-row">
            <div class="col-12 text-center text-muted py-3">No service types found.</div>
        </div>
    <?php endif; ?>

    <?php foreach ($topLevel as $parent): ?>
        <!-- 📌 Top-level type -->
        <div class="portal-data-row <?php echo $parent['isActive'] === '0' ? 'opacity-50' : ''; ?>">
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">Type: </span>
                <strong><i class="fa-solid fa-folder me-1 text-primary"></i><?php echo htmlspecialchars($parent['typeName'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Description: </span>
                <small><?php echo htmlspecialchars($parent['description'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-1 text-md-center">
                <span class="d-md-none fw-semibold">Order: </span>
                <?php echo (int) $parent['sortOrder']; ?>
            </div>
            <div class="col-12 col-md-1 text-md-center">
                <span class="d-md-none fw-semibold">Sessions: </span>
                <span class="badge bg-info"><?php echo (int) $parent['sessionCount']; ?></span>
            </div>
            <div class="col-12 col-md-1 text-md-center">
                <span class="d-md-none fw-semibold">Status: </span>
                <?php if ($parent['isActive'] === '1' || (int) $parent['isActive'] === 1): ?>
                    <span class="badge bg-success">Active</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <form method="post" action="/attendance/manage/save" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="serviceTypeID" value="<?php echo (int) $parent['serviceTypeID']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-<?php echo ($parent['isActive'] === '1' || (int) $parent['isActive'] === 1) ? 'warning' : 'success'; ?>"
                            title="<?php echo ($parent['isActive'] === '1' || (int) $parent['isActive'] === 1) ? 'Deactivate' : 'Activate'; ?>">
                        <i class="fa-solid fa-<?php echo ($parent['isActive'] === '1' || (int) $parent['isActive'] === 1) ? 'eye-slash' : 'eye'; ?>"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- 📋 Child types (indented) -->
        <?php
        $children = array_filter($allTypes, function ($c) use ($parent) {
            return $c['parentID'] !== null && (int) $c['parentID'] === (int) $parent['serviceTypeID'];
        });
        foreach ($children as $child):
        ?>
            <div class="portal-data-row <?php echo $child['isActive'] === '0' ? 'opacity-50' : ''; ?>" style="padding-left:2rem;">
                <div class="col-12 col-md-4">
                    <span class="d-md-none fw-semibold">Type: </span>
                    <i class="fa-solid fa-turn-up fa-rotate-90 me-1 text-muted"></i>
                    <?php echo htmlspecialchars($child['typeName'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-3">
                    <span class="d-md-none fw-semibold">Description: </span>
                    <small><?php echo htmlspecialchars($child['description'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <div class="col-12 col-md-1 text-md-center">
                    <span class="d-md-none fw-semibold">Order: </span>
                    <?php echo (int) $child['sortOrder']; ?>
                </div>
                <div class="col-12 col-md-1 text-md-center">
                    <span class="d-md-none fw-semibold">Sessions: </span>
                    <span class="badge bg-info"><?php echo (int) $child['sessionCount']; ?></span>
                </div>
                <div class="col-12 col-md-1 text-md-center">
                    <span class="d-md-none fw-semibold">Status: </span>
                    <?php if ($child['isActive'] === '1' || (int) $child['isActive'] === 1): ?>
                        <span class="badge bg-success">Active</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                    <form method="post" action="/attendance/manage/save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="serviceTypeID" value="<?php echo (int) $child['serviceTypeID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?php echo ($child['isActive'] === '1' || (int) $child['isActive'] === 1) ? 'warning' : 'success'; ?>"
                                title="<?php echo ($child['isActive'] === '1' || (int) $child['isActive'] === 1) ? 'Deactivate' : 'Activate'; ?>">
                            <i class="fa-solid fa-<?php echo ($child['isActive'] === '1' || (int) $child['isActive'] === 1) ? 'eye-slash' : 'eye'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
