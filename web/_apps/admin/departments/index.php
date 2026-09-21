<?php
// Path: _apps/admin/departments/index.php
/**
 * -----------------------------------------------------------------------------
 * Departments 🏢 (#517)
 * -----------------------------------------------------------------------------
 * Manage THIS organisation's departments: add, rename, retire (switch off),
 * reinstate and delete. Who is IN each department, and with which flags, is
 * managed on that department's own page (the "Members" button).
 *
 * WHAT A DEPARTMENT IS FOR: expense claims are charged to a department, and
 * the lead, required-approver and approver flags on its members decide who
 * approves them. A department can also own an asset. Nothing could create a
 * department before #517, so department-based expense approval only ever
 * worked if somebody edited the database by hand — see
 * `Portal\Core\Departments`.
 *
 * WHAT "RETIRED" MEANS HERE (owner's answer Q3, 21 September 2026): no NEW
 * claim can be charged to a retired department, while claims already
 * submitted to it are finished by its own approvers as before. The intro
 * text below says exactly this.
 *
 * WHO MAY USE THIS PAGE: any administrator of the organisation currently
 * open (`App::isAdmin()`); a global administrator passes that gate
 * everywhere.
 *
 * WHAT THIS PAGE CANNOT DO: it draws Delete only for a department with no
 * members; the save handler checks the rest (assets it owns, expense claims
 * charged to it) and says what is in the way.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/517
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Departments;
use Portal\Core\Router;
use Portal\Core\Site;

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$site   = Site::current();

// 📌 Page metadata
$pageTitle   = 'Departments';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Departments' => ''];

// 📋 Every department of this organisation (retired ones too), with how
//    many people are in each.
$depts = Departments::forSite($mysqli, $siteId);
$memberCounts = [];
foreach ($depts as $dept) {
    $memberCounts[$dept['deptID']] = Departments::countMembers($mysqli, $dept['deptID'], $siteId);
}

// 📋 Flash message from the save handler
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf    = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$saveUrl = htmlspecialchars(Site::url('admin/departments/save'), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🏢 Departments -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-building me-2"></i>Departments</h1>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addDeptModal">
        <i class="fa-solid fa-plus me-1"></i>Add Department
    </button>
</div>

<div class="text-muted mb-3">
    <p class="mb-2">
        Departments<?php echo $site !== null ? ' of ' . htmlspecialchars((string) $site['siteName'], ENT_QUOTES, 'UTF-8') : ''; ?>
        are what expense claims are charged to. On each department's Members page, flags on each person decide who
        approves its claims:
    </p>
    <ul class="mb-2">
        <?php foreach (Departments::FLAGS as $flag): ?>
            <li><strong><?php echo htmlspecialchars($flag['label'], ENT_QUOTES, 'UTF-8'); ?></strong> —
                <?php echo htmlspecialchars($flag['description'], ENT_QUOTES, 'UTF-8'); ?>.</li>
        <?php endforeach; ?>
    </ul>
    <!-- #542: this used to say a non-administrator ALSO needed the Expense Approver role (given on the
         Users page). That was true, and it stranded claims: a lead or required approver without the role
         was refused while the claim still waited for them. The flags alone decide now. -->
    <p class="mb-0">
        These flags are enough on their own: a non-administrator does not also need the Expense Approver role
        to decide this department's claims. Retiring a department stops new claims being charged to it;
        claims already submitted to it are still finished by its own approvers.
    </p>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (count($depts) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>No departments yet, so nobody can submit an expense claim.
        Press &ldquo;Add Department&rdquo; to create the first one.
    </div>
<?php else: ?>
<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-1">Number</div>
        <div class="col-md-4">Name</div>
        <div class="col-md-2">Code</div>
        <div class="col-md-1">Members</div>
        <div class="col-md-4 text-end">Actions</div>
    </div>
    <?php foreach ($depts as $dept): ?>
        <?php
        $did     = (int) $dept['deptID'];
        $members = $memberCounts[$did] ?? 0;
        $nameEsc = htmlspecialchars($dept['deptName'], ENT_QUOTES, 'UTF-8');
        ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-1">
                <span class="d-md-none fw-semibold">Number: </span>
                <code>#<?php echo $did; ?></code>
            </div>
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">Name: </span>
                <strong><?php echo $nameEsc; ?></strong>
                <?php if ($dept['isActive'] === false): ?>
                    <span class="badge bg-secondary ms-1">Retired</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Code: </span>
                <code><?php echo htmlspecialchars((string) ($dept['deptCode'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>
            </div>
            <div class="col-12 col-md-1">
                <span class="d-md-none fw-semibold">Members: </span>
                <?php echo (int) $members; ?>
            </div>
            <div class="col-12 col-md-4 text-md-end mt-2 mt-md-0">
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(Site::url('admin/departments/members') . '?id=' . $did, ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fa-solid fa-users me-1"></i>Members
                </a>
                <button class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#editDeptModal"
                        data-deptid="<?php echo $did; ?>"
                        data-deptname="<?php echo $nameEsc; ?>"
                        data-deptcode="<?php echo htmlspecialchars((string) ($dept['deptCode'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fa-solid fa-pen me-1"></i>Edit
                </button>
                <form method="post" action="<?php echo $saveUrl; ?>" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="deptID" value="<?php echo $did; ?>">
                    <?php if ($dept['isActive'] === true): ?>
                        <input type="hidden" name="action" value="retire">
                        <button type="submit" class="btn btn-sm btn-outline-warning" data-confirm="Retire the department &quot;<?php echo $nameEsc; ?>&quot;? No new claims can be charged to it; claims already submitted are still finished by its approvers.">
                            <i class="fa-solid fa-box-archive me-1"></i>Retire
                        </button>
                    <?php else: ?>
                        <input type="hidden" name="action" value="reinstate">
                        <button type="submit" class="btn btn-sm btn-outline-success">
                            <i class="fa-solid fa-rotate-left me-1"></i>Reinstate
                        </button>
                    <?php endif; ?>
                </form>
                <?php if ($members === 0): ?>
                    <form method="post" action="<?php echo $saveUrl; ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="deptID" value="<?php echo $did; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete the department &quot;<?php echo $nameEsc; ?>&quot;? This cannot be undone.">
                            <i class="fa-solid fa-trash me-1"></i>Delete
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ➕ Add Department Modal -->
<div class="modal fade" id="addDeptModal" tabindex="-1" aria-labelledby="addDeptLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="<?php echo $saveUrl; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title" id="addDeptLabel"><i class="fa-solid fa-plus me-1"></i>Add Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="add-deptName">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add-deptName" name="deptName" maxlength="<?php echo Departments::NAME_MAX; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="add-deptCode">Short code</label>
                        <input type="text" class="form-control" id="add-deptCode" name="deptCode" maxlength="<?php echo Departments::CODE_MAX; ?>">
                        <div class="form-text">Optional, for your own reference (for example a budget code).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>Add Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ✏️ Edit Department Modal -->
<div class="modal fade" id="editDeptModal" tabindex="-1" aria-labelledby="editDeptLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="<?php echo $saveUrl; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="deptID" id="edit-deptID">
                <div class="modal-header">
                    <h5 class="modal-title" id="editDeptLabel"><i class="fa-solid fa-pen me-1"></i>Edit Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="edit-deptName">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="deptName" id="edit-deptName" maxlength="<?php echo Departments::NAME_MAX; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit-deptCode">Short code</label>
                        <input type="text" class="form-control" name="deptCode" id="edit-deptCode" maxlength="<?php echo Departments::CODE_MAX; ?>">
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
// ✏️ Fill the edit form from the button that opened it (see the same
//    script on the Groups page for why this is safe).
document.getElementById('editDeptModal').addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    document.getElementById('edit-deptID').value   = btn.getAttribute('data-deptid');
    document.getElementById('edit-deptName').value = btn.getAttribute('data-deptname');
    document.getElementById('edit-deptCode').value = btn.getAttribute('data-deptcode');
});
</script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
