<?php
// Path: _apps/admin/users/memberships-unplaced.php
/**
 * -----------------------------------------------------------------------------
 * User groups and departments awaiting placement 🖊️ (#517)
 * -----------------------------------------------------------------------------
 * A list, for a GLOBAL administrator only, of everything migration 203 could
 * not carry over on its own — the same shape `roles-unplaced.php` (#516) and
 * `unplaced.php` (#533) already use. Three lists:
 *   1. Groups with no organisation (`tblGroupsUnplaced`) — place each into
 *      an organisation, or discard it.
 *   2. Group memberships (`tblUserGroupsUnplaced`) — wait for their group to
 *      be placed, then place each person, or discard the entry.
 *   3. Department memberships (`tblUserDeptsUnplaced`) — place each into
 *      the department's own organisation, flags included, or discard it.
 *
 * HOW A ROW GETS HERE
 * -------------------------------------------------------------------------
 * Before #517 a user group belonged to no organisation, and neither
 * membership table said which organisation a membership was in. Migration
 * 203 places a group automatically only where that is not a guess (a
 * single-organisation portal, or exactly one organisation row); a
 * membership only where the person has a membership row in that
 * organisation. Everything else is copied here and removed from the live
 * tables, so it grants nothing in the meantime. Nothing could create any of
 * these rows before #517 except a hand edit, so on an ordinary installation
 * all three lists are empty.
 *
 * WHAT THIS PAGE CANNOT DO
 * -------------------------------------------------------------------------
 * It cannot guess where a group belongs; a person decides. It never creates
 * an organisation membership as a side effect: placing a person needs them
 * to be an ACTIVE member of that organisation already (add them first at
 * Admin → Sites → Users). A parked group's asset ownership was not kept by
 * the migration (see migration 203's header); it has to be added again from
 * the asset's own page after placing the group.
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

use Portal\Core\AccountGuard;
use Portal\Core\Auth;
use Portal\Core\Departments;
use Portal\Core\Site;

// 🛡️ Global administrator only. `AccountGuard::` (not a bare
//    App::isRootAdmin() call) is the spelling
//    tools/audit-checks/check_account_writes_guarded.py looks for, the same
//    as roles-unplaced.php.
if (AccountGuard::actorIsGlobal() === false) {
    http_response_code(403);
    echo t('error.umbrella_admin_only');
    exit();
}

// 📌 Page metadata
$pageTitle   = 'Groups and departments awaiting placement';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Users' => Site::url('admin/users'), 'Groups and departments awaiting placement' => ''];

// -----------------------------------------------------------------------------
// 📋 The three lists, each capped at 500 — the cap #533 and #516 settled on,
//    for the same reason: a "things have already gone slightly wrong" page
//    should never risk becoming an unbounded query.
// -----------------------------------------------------------------------------
$fetchAll = static function (string $sql) use ($mysqli): array {
    $result = $mysqli->query($sql);
    return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
};

// ⚠️ tblUserGroupsUnplaced is only ever reached through a JOIN below, never
//    as `FROM tblUserGroupsUnplaced <short name>` or followed by GROUP BY:
//    tools/audit-checks/check_sql_columns.py misreads both of those shapes
//    as an unknown table (its header, blind spot 15 — the word "Group"
//    inside the table's name). A JOIN is the shape it reads correctly.

// 1. Parked groups, with how many parked memberships name each one's number.
$parkedGroups = $fetchAll(
    'SELECT gu.unplacedID, gu.originalGroupID, gu.groupName, gu.description, gu.createdAt, '
    . 'COUNT(p.unplacedID) AS parkedMembers '
    . 'FROM tblGroupsUnplaced gu '
    . 'LEFT JOIN tblUserGroupsUnplaced p ON p.groupID = gu.originalGroupID '
    . 'GROUP BY gu.unplacedID, gu.originalGroupID, gu.groupName, gu.description, gu.createdAt '
    . 'ORDER BY gu.groupName ASC, gu.originalGroupID ASC LIMIT 500'
);

// 2. Parked group memberships. `parkedGroupRow` is set when the group itself
//    is still in list 1; otherwise `groupSiteID` is the organisation the
//    group now belongs to (NULL if no such group exists any more).
$groupMembers = $fetchAll(
    'SELECT p.unplacedID, p.userID, u.fullName, u.emailAddress, p.groupID, p.groupName, '
    . 'g.siteID AS groupSiteID, s.siteName, gu.unplacedID AS parkedGroupRow '
    . 'FROM tblUsers u '
    . 'JOIN tblUserGroupsUnplaced p ON p.userID = u.userID '
    . 'LEFT JOIN tblGroupsUnplaced gu ON gu.originalGroupID = p.groupID '
    . 'LEFT JOIN tblGroups g ON g.groupID = p.groupID '
    . 'LEFT JOIN tblSites s ON s.siteID = g.siteID '
    . 'ORDER BY u.fullName ASC, p.groupID ASC LIMIT 500'
);

// 3. Parked department memberships, with the department's own organisation.
$deptMembers = $fetchAll(
    'SELECT p.unplacedID, p.userID, u.fullName, u.emailAddress, p.deptID, d.deptName, s.siteName, '
    . 'p.isDeptLead, p.isMandatoryApprover, p.isApprover, p.isDeptAssistant, p.isDeptSecretary '
    . 'FROM tblUserDeptsUnplaced p '
    . 'JOIN tblUsers u ON u.userID = p.userID '
    . 'JOIN tblDepts d ON d.deptID = p.deptID '
    . 'JOIN tblSites s ON s.siteID = d.siteID '
    . 'ORDER BY u.fullName ASC, d.deptName ASC LIMIT 500'
);

// 🌐 Every active organisation, for the "place this group into" picker.
$organisations = Site::allActive($mysqli);

// 📋 Flash message from the save handler
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf    = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$saveUrl = htmlspecialchars(Site::url('admin/users/memberships-unplaced/save'), ENT_QUOTES, 'UTF-8');

// 🧩 One small Discard form, used by all three lists.
$discardForm = static function (string $action, int $unplacedId, string $confirm) use ($csrf, $saveUrl): string {
    return '<form method="post" action="' . $saveUrl . '" class="d-inline">'
        . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="unplacedID" value="' . $unplacedId . '">'
        . '<button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="fa-solid fa-trash me-1"></i>Discard</button></form>';
};

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🖊️ Groups and departments awaiting placement -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-people-group me-2"></i>Groups and departments awaiting placement</h1>
    <a href="<?php echo htmlspecialchars(Site::url('admin/users'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Users
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <p class="mb-2">
        <strong>What this is.</strong> User groups and departments now belong to one organisation each (#517). When
        this portal was upgraded, anything the portal could not place on its own, without guessing, was set aside
        here. Until it is placed it counts for nothing: a parked group approves no workflow step and owns no asset,
        and a parked membership gives nobody anything.
    </p>
    <p class="mb-2">
        <strong>How this happens.</strong> Only if groups, departments or memberships were written into the database
        by hand before this feature existed, on a portal with more than one organisation (or for somebody who was
        not a member of the organisation concerned). On an ordinary installation all three lists are empty.
    </p>
    <p class="mb-0">
        <strong>What to do.</strong> Place each group into the organisation it belongs to; its members who are
        active members of that organisation are added straight away, and anyone else stays listed below. Placing a
        person needs them to be an active member of that organisation already — add them first at
        Admin &rarr; Sites &rarr; Users. Any asset a parked group owned was not kept; add it again from the asset's
        page once the group is placed.
    </p>
</div>

<!-- 1️⃣ Groups with no organisation -->
<h2 class="h5 mt-4">Groups with no organisation</h2>
<?php if (count($parkedGroups) === 0): ?>
    <p class="text-muted"><i class="fa-solid fa-circle-check me-1"></i>None.</p>
<?php else: ?>
<div class="portal-data-list mb-4">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-1">Number</div>
        <div class="col-md-3">Name</div>
        <div class="col-md-3">Description</div>
        <div class="col-md-1">Parked members</div>
        <div class="col-md-4 text-end">Place into</div>
    </div>
    <?php foreach ($parkedGroups as $pg): ?>
        <?php $originalId = (int) $pg['originalGroupID']; ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-1"><span class="d-md-none fw-semibold">Number: </span><code>#<?php echo $originalId; ?></code></div>
            <div class="col-12 col-md-3"><span class="d-md-none fw-semibold">Name: </span><strong><?php echo htmlspecialchars((string) ($pg['groupName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="col-12 col-md-3"><span class="d-md-none fw-semibold">Description: </span><small class="text-muted"><?php echo htmlspecialchars((string) ($pg['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small></div>
            <div class="col-12 col-md-1"><span class="d-md-none fw-semibold">Parked members: </span><?php echo (int) $pg['parkedMembers']; ?></div>
            <div class="col-12 col-md-4 text-md-end mt-2 mt-md-0">
                <div class="d-flex gap-1 justify-content-md-end flex-wrap">
                    <?php if (count($organisations) === 0): ?>
                        <span class="text-muted small">No active organisations</span>
                    <?php else: ?>
                    <form method="post" action="<?php echo $saveUrl; ?>" class="d-flex gap-1">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="place_group">
                        <input type="hidden" name="unplacedID" value="<?php echo (int) $pg['unplacedID']; ?>">
                        <select name="siteID" class="form-select form-select-sm" style="max-width: 12rem;" required aria-label="Organisation">
                            <option value="">Choose&hellip;</option>
                            <?php foreach ($organisations as $org): ?>
                                <option value="<?php echo (int) $org['siteID']; ?>"><?php echo htmlspecialchars((string) $org['siteName'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-success"><i class="fa-solid fa-plus me-1"></i>Place</button>
                    </form>
                    <?php endif; ?>
                    <?php echo $discardForm('discard_group', (int) $pg['unplacedID'], 'Discard this parked group and every parked membership of it? This cannot be undone.'); ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 2️⃣ Group memberships -->
<h2 class="h5 mt-4">Group memberships</h2>
<?php if (count($groupMembers) === 0): ?>
    <p class="text-muted"><i class="fa-solid fa-circle-check me-1"></i>None.</p>
<?php else: ?>
<div class="portal-data-list mb-4">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-3">Name</div>
        <div class="col-md-3">Email</div>
        <div class="col-md-3">Group</div>
        <div class="col-md-3 text-end">Actions</div>
    </div>
    <?php foreach ($groupMembers as $gm): ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><span class="d-md-none fw-semibold">Name: </span><strong><?php echo htmlspecialchars((string) ($gm['fullName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="col-12 col-md-3"><span class="d-md-none fw-semibold">Email: </span><small><?php echo htmlspecialchars((string) ($gm['emailAddress'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small></div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Group: </span>
                <code>#<?php echo (int) $gm['groupID']; ?></code> <?php echo htmlspecialchars((string) ($gm['groupName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($gm['parkedGroupRow'] === null && $gm['siteName'] !== null): ?>
                    <br><small class="text-muted">in <?php echo htmlspecialchars((string) $gm['siteName'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-3 text-md-end mt-2 mt-md-0">
                <?php if ($gm['parkedGroupRow'] !== null): ?>
                    <small class="text-muted d-block">(waiting for the group itself to be placed)</small>
                <?php elseif ($gm['groupSiteID'] === null): ?>
                    <small class="text-muted d-block">(that group no longer exists)</small>
                <?php else: ?>
                    <form method="post" action="<?php echo $saveUrl; ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="place_member">
                        <input type="hidden" name="unplacedID" value="<?php echo (int) $gm['unplacedID']; ?>">
                        <button type="submit" class="btn btn-sm btn-success"><i class="fa-solid fa-plus me-1"></i>Place</button>
                    </form>
                <?php endif; ?>
                <?php echo $discardForm('discard_member', (int) $gm['unplacedID'], 'Discard this parked group membership? This cannot be undone.'); ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 3️⃣ Department memberships -->
<h2 class="h5 mt-4">Department memberships</h2>
<?php if (count($deptMembers) === 0): ?>
    <p class="text-muted"><i class="fa-solid fa-circle-check me-1"></i>None.</p>
<?php else: ?>
<div class="portal-data-list mb-4">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-3">Name</div>
        <div class="col-md-3">Department</div>
        <div class="col-md-3">Flags</div>
        <div class="col-md-3 text-end">Actions</div>
    </div>
    <?php foreach ($deptMembers as $dm): ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Name: </span>
                <strong><?php echo htmlspecialchars((string) ($dm['fullName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                <br><small><?php echo htmlspecialchars((string) ($dm['emailAddress'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Department: </span>
                <?php echo htmlspecialchars((string) ($dm['deptName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                <br><small class="text-muted">in <?php echo htmlspecialchars((string) ($dm['siteName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Flags: </span>
                <?php foreach (Departments::FLAGS as $flagKey => $flag): ?>
                    <?php if ((int) $dm[$flagKey] === 1): ?>
                        <span class="badge bg-info text-dark"><?php echo htmlspecialchars($flag['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="col-12 col-md-3 text-md-end mt-2 mt-md-0">
                <form method="post" action="<?php echo $saveUrl; ?>" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="place_dept_member">
                    <input type="hidden" name="unplacedID" value="<?php echo (int) $dm['unplacedID']; ?>">
                    <button type="submit" class="btn btn-sm btn-success"><i class="fa-solid fa-plus me-1"></i>Place</button>
                </form>
                <?php echo $discardForm('discard_dept_member', (int) $dm['unplacedID'], 'Discard this parked department membership? This cannot be undone.'); ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
