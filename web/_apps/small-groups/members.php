<?php
// Path: _apps/small-groups/members.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Roster Management 🧑‍🤝‍🧑
 * -----------------------------------------------------------------------------
 * Three `portal-data-list` sections (pending requests, active members,
 * ended members) plus a site-scoped active-user picker (leadership
 * `assign.php:87-93` join against `tblUserSites`) excluding existing
 * active/pending members. All mutations POST to `/small-groups/member-save`.
 *
 * @package   Portal\SmallGroups
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/150
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\SmallGroups;

Auth::ensureSession();
Auth::requireLogin();

if (SmallGroups::isEnabled() === false) {
    Router::renderError(404);
    return;
}

$siteId  = Site::id();
$groupId = (int) ($_GET['id'] ?? 0);
$group   = SmallGroups::getGroup($siteId, $groupId);
if ($group === null) {
    Router::renderError(404);
    return;
}
if (SmallGroups::canManage($siteId, $groupId) === false) {
    Router::renderError(403);
    return;
}

$pending = SmallGroups::membersOf($siteId, $groupId, 'pending');
$active  = SmallGroups::membersOf($siteId, $groupId, 'active');
$ended   = SmallGroups::membersOf($siteId, $groupId, 'ended');

// 👤 Site-scoped active-user picker, excluding existing active/pending
// members (leadership assign.php:87-93 precedent).
$existingUserIds = [];
foreach ([$pending, $active] as $set) {
    foreach ($set as $m) {
        $existingUserIds[(int) $m['userID']] = true;
    }
}
$pickerUsers = [];
$db = App::db();
$stmtUsers = $db->prepare(
    'SELECT u.userID, u.fullName, u.emailAddress '
    . 'FROM tblUsers u '
    . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
    . 'WHERE u.isActive = 1 '
    . 'ORDER BY u.fullName'
);
if ($stmtUsers !== false) {
    $stmtUsers->bind_param('i', $siteId);
    $stmtUsers->execute();
    $result = $stmtUsers->get_result();
    while ($u = $result->fetch_assoc()) {
        if (isset($existingUserIds[(int) $u['userID']]) === false) {
            $pickerUsers[] = $u;
        }
    }
    $stmtUsers->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'Members — ' . $group['groupName'];
$pageSection = 'small-groups';
$breadcrumbs = ['Dashboard' => '/', 'Small Groups' => '/small-groups', (string) $group['groupName'] => '/small-groups/group?id=' . $groupId, 'Members' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = $esc(Auth::csrfToken());
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $esc($flashType); ?> alert-dismissible fade show">
        <?php echo $esc($flashMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-users-gear me-2"></i>Members — <?php echo $esc($group['groupName']); ?></h1>
    <a href="/small-groups/group?id=<?php echo $groupId; ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back to group</a>
</div>

<!-- ➕ Add member -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">Add a member</h5></div>
    <div class="card-body">
        <form method="post" action="/small-groups/member-save" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
            <div class="col-12 col-md-6">
                <label for="userID" class="form-label">Person</label>
                <select name="userID" id="userID" class="form-select" required>
                    <option value="">— Select —</option>
                    <?php foreach ($pickerUsers as $pu): ?>
                        <option value="<?php echo (int) $pu['userID']; ?>">
                            <?php echo $esc(($pu['fullName'] ?? '') . ' (' . $pu['emailAddress'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label for="memberRole" class="form-label">Role</label>
                <select name="memberRole" id="memberRole" class="form-select">
                    <option value="member">Member</option>
                    <option value="co-leader">Co-leader</option>
                    <option value="leader">Leader</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-plus me-1"></i>Add</button>
            </div>
        </form>
    </div>
</div>

<!-- ⏳ Pending requests -->
<?php if (count($pending) > 0): ?>
    <h2 class="h5 mb-2">Pending requests <span class="badge bg-warning text-dark"><?php echo count($pending); ?></span></h2>
    <div class="portal-data-list mb-4">
        <?php foreach ($pending as $m): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-5">
                    <strong><?php echo $esc($m['fullName']); ?></strong>
                    <?php if (($m['requestNote'] ?? '') !== ''): ?>
                        <br><small class="text-muted"><?php echo $esc($m['requestNote']); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-7 text-md-end mt-2 mt-md-0">
                    <form method="post" action="/small-groups/member-save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
                        <input type="hidden" name="membershipID" value="<?php echo (int) $m['membershipID']; ?>">
                        <button type="submit" class="btn btn-sm btn-success"><i class="fa-solid fa-check me-1"></i>Approve</button>
                    </form>
                    <form method="post" action="/small-groups/member-save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="decline">
                        <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
                        <input type="hidden" name="membershipID" value="<?php echo (int) $m['membershipID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Decline this join request?">Decline</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ✅ Active members -->
<h2 class="h5 mb-2">Active members <span class="badge bg-primary"><?php echo count($active); ?></span></h2>
<div class="portal-data-list mb-4">
    <?php if (count($active) === 0): ?>
        <div class="portal-data-row"><div class="col-12 text-muted">No active members yet.</div></div>
    <?php endif; ?>
    <?php foreach ($active as $m): ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-4">
                <strong><?php echo $esc($m['fullName']); ?></strong><br>
                <small class="text-muted"><?php echo $esc($m['emailAddress']); ?></small>
            </div>
            <div class="col-6 col-md-3">
                <span class="d-md-none fw-semibold">Since: </span>
                <small><?php echo $m['joinedAt'] !== null ? $esc(date('j M Y', strtotime((string) $m['joinedAt']))) : '—'; ?></small>
            </div>
            <div class="col-6 col-md-3">
                <form method="post" action="/small-groups/member-save" class="d-flex gap-1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="change-role">
                    <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
                    <input type="hidden" name="membershipID" value="<?php echo (int) $m['membershipID']; ?>">
                    <select name="memberRole" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="member" <?php echo $m['memberRole'] === 'member' ? 'selected' : ''; ?>>Member</option>
                        <option value="co-leader" <?php echo $m['memberRole'] === 'co-leader' ? 'selected' : ''; ?>>Co-leader</option>
                        <option value="leader" <?php echo $m['memberRole'] === 'leader' ? 'selected' : ''; ?>>Leader</option>
                    </select>
                    <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Set</button></noscript>
                </form>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <form method="post" action="/small-groups/member-save" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
                    <input type="hidden" name="membershipID" value="<?php echo (int) $m['membershipID']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Remove this member from the group?" title="Remove">
                        <i class="fa-solid fa-user-minus"></i>
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- 🚪 Ended memberships -->
<?php if (count($ended) > 0): ?>
    <h2 class="h5 mb-2">Ended memberships</h2>
    <div class="portal-data-list">
        <?php foreach ($ended as $m): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-6">
                    <?php echo $esc($m['fullName']); ?>
                    <small class="text-muted">— ended <?php echo $m['endedAt'] !== null ? $esc(date('j M Y', strtotime((string) $m['endedAt']))) : ''; ?></small>
                </div>
                <div class="col-12 col-md-6 text-md-end mt-2 mt-md-0">
                    <form method="post" action="/small-groups/member-save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="rejoin">
                        <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
                        <input type="hidden" name="membershipID" value="<?php echo (int) $m['membershipID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-rotate-left me-1"></i>Re-add</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
