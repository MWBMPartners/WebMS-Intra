<?php
// Path: public_html/admin/users/index.php
/**
 * -----------------------------------------------------------------------------
 * User Management 👥
 * -----------------------------------------------------------------------------
 * Admin page for viewing, creating, editing, and deactivating portal users.
 * Displays user list with roles, local account status, and admin flags.
 * Uses Bootstrap modals for add/edit forms.
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
use Portal\Core\Router;

// 📌 Page metadata
$pageTitle   = 'User Management';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Users' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// -----------------------------------------------------------------------------
// 📋 Fetch users with their roles and local account status
// -----------------------------------------------------------------------------
$users = [];
$stmt = $mysqli->prepare(
    'SELECT u.userID, u.fullName, u.emailAddress, u.phoneNumber, '
    . 'u.isActive, u.isAdmin, u.isRootAdmin, u.createdAt, '
    . 'la.username AS localUsername, la.lastLogin '
    . 'FROM tblUsers u '
    . 'LEFT JOIN tblLocalAccounts la ON la.userID = u.userID '
    . 'ORDER BY u.fullName ASC'
);
if ($stmt !== false) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $users[] = $r;
    }
    $stmt->close();
}

// 📋 Fetch all roles for the role assignment checkboxes
$allRoles = [];
$result = $mysqli->query('SELECT roleID, roleKey, roleName FROM tblRoles ORDER BY roleName');
if ($result !== false) {
    while ($r = $result->fetch_assoc()) {
        $allRoles[] = $r;
    }
}

// 📋 Fetch user→role mappings
$userRoles = [];
$result = $mysqli->query(
    'SELECT ur.userID, r.roleKey, r.roleName FROM tblUserRoles ur '
    . 'JOIN tblRoles r ON r.roleID = ur.roleID'
);
if ($result !== false) {
    while ($r = $result->fetch_assoc()) {
        $uid = (int) $r['userID'];
        if (isset($userRoles[$uid]) === false) {
            $userRoles[$uid] = [];
        }
        $userRoles[$uid][] = $r['roleName'];
    }
}

// 📋 Flash message from save handler
$flashMsg  = $_SESSION['admin_flash_msg']  ?? '';
$flashType = $_SESSION['admin_flash_type'] ?? 'info';
unset($_SESSION['admin_flash_msg'], $_SESSION['admin_flash_type']);

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 👥 User Management -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-users me-2"></i>User Management</h1>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="fa-solid fa-user-plus me-1"></i> Add User
    </button>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 📋 User list -->
<div class="portal-data-list">
    <!-- 🏷️ Header row -->
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-3">Name</div>
        <div class="col-md-3">Email / Username</div>
        <div class="col-md-2">Roles</div>
        <div class="col-md-2">Status</div>
        <div class="col-md-2 text-end">Actions</div>
    </div>

    <?php foreach ($users as $user): ?>
        <?php
        $uid      = (int) $user['userID'];
        $isActive = $user['isActive'] === '1' || (int) $user['isActive'] === 1;
        $isAdmin  = $user['isAdmin'] === '1' || (int) $user['isAdmin'] === 1;
        $isRoot   = $user['isRootAdmin'] === '1' || (int) $user['isRootAdmin'] === 1;
        $roles    = $userRoles[$uid] ?? [];
        ?>
        <div class="portal-data-row <?php echo $isActive === false ? 'opacity-50' : ''; ?>">
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Name: </span>
                <strong><?php echo htmlspecialchars($user['fullName'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($isRoot === true): ?>
                    <span class="badge bg-danger ms-1" title="Root Admin">Root</span>
                <?php elseif ($isAdmin === true): ?>
                    <span class="badge bg-primary ms-1" title="Admin">Admin</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Email: </span>
                <small><?php echo htmlspecialchars($user['emailAddress'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php if ($user['localUsername'] !== null): ?>
                    <br><span class="d-md-none fw-semibold">Username: </span>
                    <small class="text-muted"><i class="fa-solid fa-key me-1"></i><?php echo htmlspecialchars($user['localUsername'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Roles: </span>
                <?php if (count($roles) > 0): ?>
                    <?php foreach ($roles as $role): ?>
                        <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="text-muted small">No roles</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Status: </span>
                <?php if ($isActive === true): ?>
                    <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Active</span>
                <?php else: ?>
                    <span class="badge bg-secondary"><i class="fa-solid fa-ban me-1"></i>Inactive</span>
                <?php endif; ?>
                <?php if ($user['lastLogin'] !== null): ?>
                    <br><small class="text-muted">Last login: <?php echo htmlspecialchars($user['lastLogin'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <button class="btn btn-sm btn-outline-primary portal-edit-user-btn"
                        data-bs-toggle="modal" data-bs-target="#editUserModal"
                        data-uid="<?php echo $uid; ?>"
                        data-fullname="<?php echo htmlspecialchars($user['fullName'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        data-email="<?php echo htmlspecialchars($user['emailAddress'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-phone="<?php echo htmlspecialchars($user['phoneNumber'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        data-active="<?php echo $isActive ? '1' : '0'; ?>"
                        data-admin="<?php echo $isAdmin ? '1' : '0'; ?>"
                        data-rootadmin="<?php echo $isRoot ? '1' : '0'; ?>">
                    <i class="fa-solid fa-pen me-1"></i>Edit
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<p class="text-muted small mt-3">
    <?php echo count($users); ?> user<?php echo count($users) !== 1 ? 's' : ''; ?> total
</p>

<!-- ➕ Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="/admin/users/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title" id="addUserLabel"><i class="fa-solid fa-user-plus me-1"></i> Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="fullName" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="emailAddress" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phoneNumber">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Username (for local login)</label>
                            <input type="text" class="form-control" name="username" placeholder="Leave blank to skip local account">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" placeholder="Required if username is set">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" name="password_confirm">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="isActive" value="1" id="add-active" checked>
                                <label class="form-check-label" for="add-active">Active</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="isAdmin" value="1" id="add-admin">
                                <label class="form-check-label" for="add-admin">Admin</label>
                            </div>
                            <?php if (App::isRootAdmin() === true): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="isRootAdmin" value="1" id="add-root">
                                <label class="form-check-label" for="add-root">Root Admin</label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-user-plus me-1"></i>Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ✏️ Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="/admin/users/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="userID" id="edit-userID">
                <div class="modal-header">
                    <h5 class="modal-title" id="editUserLabel"><i class="fa-solid fa-user-pen me-1"></i> Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="fullName" id="edit-fullName" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="emailAddress" id="edit-emailAddress" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phoneNumber" id="edit-phoneNumber">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="password" placeholder="Leave blank to keep current">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="isActive" value="1" id="edit-active">
                                <label class="form-check-label" for="edit-active">Active</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="isAdmin" value="1" id="edit-admin">
                                <label class="form-check-label" for="edit-admin">Admin</label>
                            </div>
                            <?php if (App::isRootAdmin() === true): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="isRootAdmin" value="1" id="edit-root">
                                <label class="form-check-label" for="edit-root">Root Admin</label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 📦 Edit modal population script -->
<script>
var editUserModal = document.getElementById('editUserModal');
editUserModal.addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    document.getElementById('edit-userID').value       = btn.getAttribute('data-uid');
    document.getElementById('edit-fullName').value      = btn.getAttribute('data-fullname');
    document.getElementById('edit-emailAddress').value  = btn.getAttribute('data-email');
    document.getElementById('edit-phoneNumber').value   = btn.getAttribute('data-phone');
    document.getElementById('edit-active').checked      = btn.getAttribute('data-active') === '1';
    document.getElementById('edit-admin').checked       = btn.getAttribute('data-admin') === '1';
    var rootEl = document.getElementById('edit-root');
    if (rootEl !== null) {
        rootEl.checked = btn.getAttribute('data-rootadmin') === '1';
    }
});
</script>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
