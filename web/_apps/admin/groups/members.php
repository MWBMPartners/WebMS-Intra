<?php
// Path: _apps/admin/groups/members.php
/**
 * -----------------------------------------------------------------------------
 * Who is in a user group 👥 (#517)
 * -----------------------------------------------------------------------------
 * The roster of ONE group of the organisation currently open: everyone
 * recorded in it, a Remove button for each, and an "Add member" form whose
 * list offers only this organisation's active members who are not in the
 * group yet.
 *
 * WHO MAY USE THIS PAGE: any administrator of the organisation currently
 * open (`App::isAdmin()`). The save handler then asks `AccountGuard`
 * whether that administrator may change THIS particular account.
 *
 * WHY AN UNKNOWN GROUP AND ANOTHER ORGANISATION'S GROUP LOOK THE SAME: the
 * group is looked up by (groupID, siteID), so both cost one lookup that
 * finds nothing and both get the same "page not found" answer — this page
 * never confirms that another organisation has a group with that number.
 *
 * WHAT THIS PAGE CANNOT DO: it does not hide somebody whose membership of
 * the organisation has ended; it shows them with "(membership ended)",
 * because the row still exists. Such a person counts for nothing while it is
 * ended. An organisation's administrator cannot remove that row, though: the
 * account-change guard treats an ended membership as "not in this
 * organisation" (the owner's rule for every change to a person here), so
 * only a global administrator can. Each row therefore asks the guard's own
 * `verdict()` (which records nothing) whether the person viewing may change
 * that account, and shows a plain note instead of a Remove button that could
 * only fail. (The first version drew the button anyway; pressing it could
 * only ever answer "That account could not be found.") The same note appears
 * for a global administrator's account. A RETIRED group shows its roster but
 * offers no "Add member" form.
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
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\UserGroups;

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId  = Site::id();
$groupId = (int) ($_GET['id'] ?? 0);
$group   = UserGroups::get($mysqli, $groupId, $siteId);
if ($group === null) {
    Router::renderError(404);
    return;
}

// 📌 Page metadata
$pageTitle   = 'Group members — ' . $group['groupName'];
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Groups' => Site::url('admin/groups'), 'Members' => ''];

$members = UserGroups::membersOf($mysqli, $groupId, $siteId);

// 👤 Who can be added: this organisation's ACTIVE members, with an active
//    account, not already in this group. (A person with no active
//    membership here is refused by the save handler and by the database's
//    own composite key anyway; leaving them out of the list just avoids
//    offering something that cannot work.)
// 🛡️ Hide the accounts a site administrator can never add (#517 check,
//    21 September 2026). The save handler asks AccountGuard with
//    REACH_THIS_ORG, and for anyone who is not a global administrator the
//    guard refuses an account that is itself a global administrator
//    (isRootAdmin) or holds the older portal-wide isAdmin flag (rows 6 and 7
//    of AccountGuard::decide()). The first version of this list still
//    offered those accounts, so pressing Add could only ever end in "Only a
//    global administrator can change this account". The same method the
//    guard uses decides who is global here, so the list and the refusal
//    cannot disagree. A global administrator still sees everyone. This only
//    tidies the list: the save handler's own check is what protects those
//    accounts, and it is unchanged.
$hideProtected = (AccountGuard::actorIsGlobal() === false)
    ? 'AND COALESCE(u.isRootAdmin, 0) = 0 AND COALESCE(u.isAdmin, 0) = 0 '
    : '';
$candidates = [];
if ($group['isActive'] === true) {
    $candStmt = $mysqli->prepare(
        'SELECT u.userID, u.fullName, u.emailAddress FROM tblUsers u '
        . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'WHERE u.isActive = 1 '
        . $hideProtected
        . 'AND u.userID NOT IN (SELECT userID FROM tblUserGroups WHERE groupID = ? AND siteID = ?) '
        . 'ORDER BY u.fullName'
    );
    if ($candStmt !== false) {
        $candStmt->bind_param('iii', $siteId, $groupId, $siteId);
        $candStmt->execute();
        $candResult = $candStmt->get_result();
        while (($row = $candResult->fetch_assoc()) !== null) {
            $candidates[] = $row;
        }
        $candStmt->close();
    }
}

// 📋 Flash message from the save handler
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf    = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$saveUrl = htmlspecialchars(Site::url('admin/groups/members/save'), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 👥 Group members -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">
        <i class="fa-solid fa-people-group me-2"></i><?php echo htmlspecialchars($group['groupName'], ENT_QUOTES, 'UTF-8'); ?>
        <small class="text-muted fs-6">#<?php echo (int) $group['groupID']; ?></small>
        <?php if ($group['isActive'] === false): ?>
            <span class="badge bg-secondary fs-6 align-middle">Retired</span>
        <?php endif; ?>
    </h1>
    <a href="<?php echo htmlspecialchars(Site::url('admin/groups'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Groups
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($group['isActive'] === false): ?>
    <div class="alert alert-secondary">
        <i class="fa-solid fa-box-archive me-1"></i>This group is retired: it counts for nothing (no workflow step, no
        asset) until it is reinstated on the Groups page, and nobody new can be added meanwhile.
    </div>
<?php endif; ?>

<?php if (count($members) === 0): ?>
    <p class="text-muted">Nobody is in this group yet.</p>
<?php else: ?>
<div class="portal-data-list mb-4">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-4">Name</div>
        <div class="col-md-4">Email</div>
        <div class="col-md-2">Added</div>
        <div class="col-md-2 text-end">Actions</div>
    </div>
    <?php foreach ($members as $member): ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">Name: </span>
                <strong><?php echo htmlspecialchars($member['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($member['memberActive'] === false): ?>
                    <small class="text-muted">(membership ended)</small>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">Email: </span>
                <small><?php echo htmlspecialchars($member['emailAddress'], ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Added: </span>
                <small><?php echo htmlspecialchars($member['addedAt'], ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <?php if (AccountGuard::verdict((int) $member['userID'], AccountGuard::REACH_THIS_ORG) === AccountGuard::ALLOW): ?>
                <form method="post" action="<?php echo $saveUrl; ?>" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="groupID" value="<?php echo (int) $group['groupID']; ?>">
                    <input type="hidden" name="userID" value="<?php echo (int) $member['userID']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Remove <?php echo htmlspecialchars($member['fullName'], ENT_QUOTES, 'UTF-8'); ?> from this group?">
                        <i class="fa-solid fa-user-minus me-1"></i>Remove
                    </button>
                </form>
                <?php else: ?>
                    <small class="text-muted">Only a global administrator can change this.</small>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($group['isActive'] === true): ?>
    <h2 class="h5">Add member</h2>
    <?php if (count($candidates) === 0): ?>
        <p class="text-muted">Every active member of this organisation is already in this group.</p>
    <?php else: ?>
    <form method="post" action="<?php echo $saveUrl; ?>" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="groupID" value="<?php echo (int) $group['groupID']; ?>">
        <div class="col-12 col-md-6">
            <label class="form-label" for="add-userID">Person</label>
            <select class="form-select" id="add-userID" name="userID" required>
                <option value="">Choose&hellip;</option>
                <?php foreach ($candidates as $candidate): ?>
                    <option value="<?php echo (int) $candidate['userID']; ?>">
                        <?php echo htmlspecialchars((string) ($candidate['fullName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        (<?php echo htmlspecialchars((string) ($candidate['emailAddress'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <button type="submit" class="btn btn-success"><i class="fa-solid fa-user-plus me-1"></i>Add</button>
        </div>
    </form>
    <?php endif; ?>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
