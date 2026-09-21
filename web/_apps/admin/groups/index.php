<?php
// Path: _apps/admin/groups/index.php
/**
 * -----------------------------------------------------------------------------
 * User groups 👥 (#517)
 * -----------------------------------------------------------------------------
 * Manage THIS organisation's user groups — committees and working groups
 * such as a finance committee or a building committee: add, rename, retire
 * (switch off), reinstate and delete. Who is IN each group is managed on
 * that group's own page (the "Members" button).
 *
 * WHAT A USER GROUP IS FOR: it can be named as the approver of a workflow
 * step (by its number, shown here), it can own an asset in the asset
 * register, and — from #514 — it can be the audience of a shared calendar.
 * Home groups and classes with meeting rolls are a different thing: the
 * Small Groups app.
 *
 * WHAT WAS WRONG BEFORE: nothing anywhere could create a group or add a
 * person, and groups were not tied to an organisation at all. See
 * `Portal\Core\UserGroups` for the full story.
 *
 * WHO MAY USE THIS PAGE: any administrator of the organisation currently
 * open (`App::isAdmin()`), the owner's "managed by that organisation's
 * administrators" (21 September 2026). A global administrator passes that
 * gate everywhere.
 *
 * WHAT THIS PAGE CANNOT DO: it only ever shows the open organisation's
 * groups. It draws a Delete button only for a group with no members; the
 * save handler still checks everything else that blocks a delete (assets it
 * owns, workflow steps naming it) and says what is in the way.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/517
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\UserGroups;

// 🛡️ The same gate every admin/* page uses first.
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$site   = Site::current();

// 📌 Page metadata
$pageTitle   = 'Groups';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Groups' => ''];

// 📋 Every group of this organisation (retired ones too, so they can be
//    reinstated), with how many people are in each.
$groups = UserGroups::forSite($mysqli, $siteId);
$memberCounts = [];
foreach ($groups as $group) {
    $memberCounts[$group['groupID']] = UserGroups::countMembers($mysqli, $group['groupID'], $siteId);
}

// 📋 Flash message from the save handler
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf    = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$saveUrl = htmlspecialchars(Site::url('admin/groups/save'), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 👥 Groups -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-people-group me-2"></i>Groups</h1>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addGroupModal">
        <i class="fa-solid fa-plus me-1"></i>Add Group
    </button>
</div>

<p class="text-muted">
    Committees and working groups<?php echo $site !== null ? ' of ' . htmlspecialchars((string) $site['siteName'], ENT_QUOTES, 'UTF-8') : ''; ?>.
    A group can be named as the approver of a workflow step (type the group's number, shown below, at
    <a href="<?php echo htmlspecialchars(Site::url('admin/workflows'), ENT_QUOTES, 'UTF-8'); ?>">Workflows</a>),
    and it can own an asset in the asset register. Home groups and classes with meeting rolls are the separate
    <a href="<?php echo htmlspecialchars(Site::url('small-groups'), ENT_QUOTES, 'UTF-8'); ?>">Small Groups</a> app.
    A retired group keeps its history but counts for nothing until it is reinstated.
</p>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (count($groups) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>No groups yet. Press &ldquo;Add Group&rdquo; to create the first one.
    </div>
<?php else: ?>
<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-1">Number</div>
        <div class="col-md-3">Name</div>
        <div class="col-md-3">Description</div>
        <div class="col-md-1">Members</div>
        <div class="col-md-4 text-end">Actions</div>
    </div>
    <?php foreach ($groups as $group): ?>
        <?php
        $gid     = (int) $group['groupID'];
        $members = $memberCounts[$gid] ?? 0;
        $nameEsc = htmlspecialchars($group['groupName'], ENT_QUOTES, 'UTF-8');
        ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-1">
                <span class="d-md-none fw-semibold">Number: </span>
                <code>#<?php echo $gid; ?></code>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Name: </span>
                <strong><?php echo $nameEsc; ?></strong>
                <?php if ($group['isActive'] === false): ?>
                    <span class="badge bg-secondary ms-1">Retired</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Description: </span>
                <small class="text-muted"><?php echo htmlspecialchars((string) ($group['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-1">
                <span class="d-md-none fw-semibold">Members: </span>
                <?php echo (int) $members; ?>
            </div>
            <div class="col-12 col-md-4 text-md-end mt-2 mt-md-0">
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(Site::url('admin/groups/members') . '?id=' . $gid, ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fa-solid fa-users me-1"></i>Members
                </a>
                <button class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#editGroupModal"
                        data-groupid="<?php echo $gid; ?>"
                        data-groupname="<?php echo $nameEsc; ?>"
                        data-description="<?php echo htmlspecialchars((string) ($group['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fa-solid fa-pen me-1"></i>Edit
                </button>
                <form method="post" action="<?php echo $saveUrl; ?>" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="groupID" value="<?php echo $gid; ?>">
                    <?php if ($group['isActive'] === true): ?>
                        <input type="hidden" name="action" value="retire">
                        <button type="submit" class="btn btn-sm btn-outline-warning" data-confirm="Retire the group &quot;<?php echo $nameEsc; ?>&quot;? It keeps its members but counts for nothing until reinstated.">
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
                        <input type="hidden" name="groupID" value="<?php echo $gid; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete the group &quot;<?php echo $nameEsc; ?>&quot;? This cannot be undone.">
                            <i class="fa-solid fa-trash me-1"></i>Delete
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ➕ Add Group Modal -->
<div class="modal fade" id="addGroupModal" tabindex="-1" aria-labelledby="addGroupLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="<?php echo $saveUrl; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title" id="addGroupLabel"><i class="fa-solid fa-plus me-1"></i>Add Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="add-groupName">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add-groupName" name="groupName" maxlength="<?php echo UserGroups::NAME_MAX; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="add-description">Description</label>
                        <textarea class="form-control" id="add-description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>Add Group</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ✏️ Edit Group Modal -->
<div class="modal fade" id="editGroupModal" tabindex="-1" aria-labelledby="editGroupLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="<?php echo $saveUrl; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="groupID" id="edit-groupID">
                <div class="modal-header">
                    <h5 class="modal-title" id="editGroupLabel"><i class="fa-solid fa-pen me-1"></i>Edit Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="edit-groupName">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="groupName" id="edit-groupName" maxlength="<?php echo UserGroups::NAME_MAX; ?>" required>
                        <div class="form-text">Renaming keeps the group's number, so workflow steps that name it keep working.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit-description">Description</label>
                        <textarea class="form-control" name="description" id="edit-description" rows="3"></textarea>
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
// ✏️ Fill the edit form from the button that opened it. The values were
//    already escaped into the data- attributes by PHP; reading them back
//    with getAttribute() gives the plain text, and assigning it to .value
//    never interprets it as markup.
document.getElementById('editGroupModal').addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    document.getElementById('edit-groupID').value     = btn.getAttribute('data-groupid');
    document.getElementById('edit-groupName').value   = btn.getAttribute('data-groupname');
    document.getElementById('edit-description').value = btn.getAttribute('data-description');
});
</script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
