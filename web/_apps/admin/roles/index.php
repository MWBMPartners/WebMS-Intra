<?php
// Path: _apps/admin/roles/index.php
/**
 * -----------------------------------------------------------------------------
 * Roles 🏷️ (#516)
 * -----------------------------------------------------------------------------
 * Manage THIS organisation's own list of roles: rename the standard ones
 * (treasurer, expense approver, care team, and the rest of the fourteen
 * every organisation starts with), or add roles of the organisation's own.
 *
 * Granting a role to a specific person happens on the members page
 * (`/admin/users`, the "Roles" button on each row) — this page only
 * manages the LIST itself: what roles exist, what they are called, and
 * one sentence describing each.
 *
 * WHAT THIS PAGE DELIBERATELY DOES NOT SHOW
 * -------------------------------------------------------------------------
 * Leadership positions (elders, deacons, and so on) — those live under the
 * separate Leadership app, which is about who holds a POSITION in the
 * congregation, not who may open a confidential register or approve an
 * expense claim. The two are easy to confuse because both use the word
 * "role" in everyday speech; this page is only ever about ACCESS.
 *
 * WHAT THIS PAGE CANNOT DO
 * -------------------------------------------------------------------------
 * It cannot delete a STANDARD role (`isStandard = 1`) — every organisation
 * keeps the same fourteen keys the 66 `App::hasRole()` call sites (across
 * 61 files) rely on; rename one instead if the standard wording does not
 * fit. It
 * cannot delete any role, standard or not, while somebody still holds it —
 * remove it from them first, on the members page.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/516
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Roles;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Any administrator of the organisation currently open may manage its
//    own role list (owner decision, #516 plan Q7) — the same gate every
//    other admin/* page uses; a global administrator passes it everywhere
//    because App::isAdmin() already gives them that.
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$site   = Site::current();

// 📌 Page metadata
$pageTitle   = 'Roles';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Roles' => ''];

// 📋 Every role this organisation has, with how many people hold it.
$roles = Roles::forSite($mysqli, $siteId);
$holderCounts = [];
foreach ($roles as $role) {
    $holderCounts[$role['roleID']] = Roles::countHolders($mysqli, $role['roleID'], $siteId);
}

// 📋 Flash message from the save handler
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🏷️ Roles -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-user-tag me-2"></i>Roles</h1>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addRoleModal">
        <i class="fa-solid fa-plus me-1"></i>Add Role
    </button>
</div>

<p class="text-muted">
    Access roles held per organisation<?php echo $site !== null ? ' — ' . htmlspecialchars((string) $site['siteName'], ENT_QUOTES, 'UTF-8') : ''; ?>.
    Give somebody a role from the <a href="<?php echo htmlspecialchars(Site::url('admin/users'), ENT_QUOTES, 'UTF-8'); ?>">Users</a> page.
    Leadership positions (elders, deacons) live under
    <a href="<?php echo htmlspecialchars(Site::url('leadership'), ENT_QUOTES, 'UTF-8'); ?>">Leadership</a> instead.
</p>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-3">Label</div>
        <div class="col-md-2">Key</div>
        <div class="col-md-4">Description</div>
        <div class="col-md-1">Holders</div>
        <div class="col-md-2 text-end">Actions</div>
    </div>
    <?php foreach ($roles as $role): ?>
        <?php
        $rid     = (int) $role['roleID'];
        $holders = $holderCounts[$rid] ?? 0;
        ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Label: </span>
                <strong><?php echo htmlspecialchars($role['roleName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($role['isStandard'] === true): ?>
                    <span class="badge bg-secondary ms-1">Standard</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Key: </span>
                <code><?php echo htmlspecialchars($role['roleKey'], ENT_QUOTES, 'UTF-8'); ?></code>
            </div>
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">Description: </span>
                <small class="text-muted"><?php echo htmlspecialchars((string) ($role['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-1">
                <span class="d-md-none fw-semibold">Holders: </span>
                <?php echo (int) $holders; ?>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <button class="btn btn-sm btn-outline-primary portal-edit-role-btn"
                        data-bs-toggle="modal" data-bs-target="#editRoleModal"
                        data-roleid="<?php echo $rid; ?>"
                        data-rolekey="<?php echo htmlspecialchars($role['roleKey'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-rolename="<?php echo htmlspecialchars($role['roleName'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-description="<?php echo htmlspecialchars((string) ($role['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fa-solid fa-pen me-1"></i>Edit
                </button>
                <?php if ($role['isStandard'] === false && $holders === 0): ?>
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/roles/save'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="roleID" value="<?php echo $rid; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete the role &quot;<?php echo htmlspecialchars($role['roleName'], ENT_QUOTES, 'UTF-8'); ?>&quot;? This cannot be undone.">
                            <i class="fa-solid fa-trash me-1"></i>Delete
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ➕ Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1" aria-labelledby="addRoleLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/roles/save'), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title" id="addRoleLabel"><i class="fa-solid fa-plus me-1"></i>Add Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="roleKey" pattern="[a-z][a-z0-9_]{1,49}" required>
                        <div class="form-text">2-50 characters: lower-case letters, digits and underscores, starting with a letter. This never changes once created.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Label <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="roleName" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>Add Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ✏️ Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1" aria-labelledby="editRoleLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/roles/save'), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="roleID" id="edit-roleID">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRoleLabel"><i class="fa-solid fa-pen me-1"></i>Edit Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Key</label>
                        <input type="text" class="form-control" id="edit-roleKey" disabled>
                        <div class="form-text">Keys are fixed for life — the places in the code that check for a role compare against this, never the label below.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Label <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="roleName" id="edit-roleName" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" id="edit-description" maxlength="255">
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

<script>
var editRoleModal = document.getElementById('editRoleModal');
editRoleModal.addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    document.getElementById('edit-roleID').value      = btn.getAttribute('data-roleid');
    document.getElementById('edit-roleKey').value      = btn.getAttribute('data-rolekey');
    document.getElementById('edit-roleName').value     = btn.getAttribute('data-rolename');
    document.getElementById('edit-description').value  = btn.getAttribute('data-description');
});
</script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
