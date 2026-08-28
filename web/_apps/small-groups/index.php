<?php
// Path: _apps/small-groups/index.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Directory 👥
 * -----------------------------------------------------------------------------
 * Lists groups/classes for the current site. When
 * `small-groups.directory_visible` is '0', a non-manage-all viewer is
 * redirected straight to "My groups" instead of seeing the full list
 * (`SmallGroups::listGroups()` is the manage-all/leader surface either
 * way — the flag only gates whether the GENERAL membership sees it).
 *
 * No address column here — location renders only on group.php behind the
 * `locationVisibility` gate (a group's meeting place is often a member's
 * home; the directory must never leak it).
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
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\SmallGroups;

Auth::ensureSession();
Auth::requireLogin();

if (SmallGroups::isEnabled() === false) {
    Router::renderError(404);
    return;
}

$siteId = Site::id();
$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);

$isManageAll = App::isAdmin() === true || App::hasRole('groups_coordinator') === true;

// 🚪 Directory visibility flag — the GENERAL membership only, never
// managers/coordinators/admins (they always need the full list to work).
$directoryVisible = (string) Settings::get('small-groups.directory_visible', '1');
if ($isManageAll === false && ($directoryVisible !== '1' && $directoryVisible !== 'true')) {
    header('Location: /small-groups/mine');
    exit();
}

$openEnrolmentSite = (string) Settings::get('small-groups.open_enrolment', '1');
$openEnrolmentOn   = $openEnrolmentSite === '1' || $openEnrolmentSite === 'true';

$groups = SmallGroups::listGroups($siteId, $isManageAll === false);

// 📋 Which groups is the current user already active/pending in? — for the
// Join button state.
$myGroupIds     = [];
$myPendingIds   = [];
foreach (SmallGroups::groupsFor($siteId, $userId, 'active') as $g) {
    $myGroupIds[(int) $g['groupID']] = true;
}
foreach (SmallGroups::groupsFor($siteId, $userId, 'pending') as $g) {
    $myPendingIds[(int) $g['groupID']] = true;
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'Small Groups';
$pageSection = 'small-groups';
$breadcrumbs = ['Dashboard' => '/', 'Small Groups' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $esc($flashType); ?> alert-dismissible fade show">
        <?php echo $esc($flashMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-people-group me-2"></i>Small Groups</h1>
        <p class="text-secondary mb-0">Groups, classes, and Bible studies — rosters, meeting rolls, and attendance tie-in.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="/small-groups/mine" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-user-group me-1"></i>My Groups</a>
        <?php if ($isManageAll === true): ?>
            <a href="/small-groups/manage" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>New Group</a>
        <?php endif; ?>
    </div>
</div>

<?php if (count($groups) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        No groups yet.
        <?php if ($isManageAll === true): ?>
            <a href="/small-groups/manage" class="alert-link">Create the first one</a>.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-3">Group</div>
            <div class="col-md-2">Meets</div>
            <div class="col-md-3">Leader(s)</div>
            <div class="col-md-2 text-center">Members</div>
            <div class="col-md-2 text-end">Actions</div>
        </div>

        <?php foreach ($groups as $g): ?>
            <?php
            $gid = (int) $g['groupID'];
            $meetsBits = [];
            if ($g['meetingDay'] !== null) {
                $meetsBits[] = $dayNames[(int) $g['meetingDay']] ?? '';
            }
            if ($g['meetingTime'] !== null && $g['meetingTime'] !== '') {
                $meetsBits[] = date('g:i A', strtotime((string) $g['meetingTime']));
            }
            $meets = count($meetsBits) > 0 ? implode(' ', $meetsBits) : 'Varies';
            $memberCount = (int) ($g['memberCount'] ?? 0);
            $capacity    = $g['capacity'] !== null ? (int) $g['capacity'] : null;
            $isMemberHere  = isset($myGroupIds[$gid]);
            $isPendingHere = isset($myPendingIds[$gid]);
            ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-3">
                    <a href="/small-groups/group?id=<?php echo $gid; ?>" class="fw-semibold text-decoration-none">
                        <?php echo $esc($g['groupName']); ?>
                    </a>
                    <br><span class="badge bg-secondary"><?php echo $esc(ucwords(str_replace('-', ' ', (string) $g['groupType']))); ?></span>
                    <?php if ((int) ($g['isActive'] ?? 1) === 0): ?><span class="badge bg-secondary">inactive</span><?php endif; ?>
                    <?php if ((int) $g['isOpenEnrolment'] === 1 && $openEnrolmentOn === true): ?>
                        <span class="badge bg-success">Open to join</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Meets: </span>
                    <small><?php echo $esc($meets); ?></small>
                    <?php if (($g['meetingNotes'] ?? '') !== ''): ?>
                        <br><small class="text-muted"><?php echo $esc($g['meetingNotes']); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-3">
                    <span class="d-md-none fw-semibold">Leader(s): </span>
                    <small><?php echo $esc($g['leaderNames'] ?? '—'); ?></small>
                </div>
                <div class="col-12 col-md-2 text-md-center">
                    <span class="d-md-none fw-semibold">Members: </span>
                    <?php echo (int) $memberCount; ?><?php echo $capacity !== null ? ' / ' . $capacity : ''; ?>
                </div>
                <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                    <a href="/small-groups/group?id=<?php echo $gid; ?>" class="btn btn-sm btn-outline-secondary" title="View">
                        <i class="fa-solid fa-eye"></i>
                    </a>
                    <?php if ($isManageAll === true): ?>
                        <a href="/small-groups/manage?id=<?php echo $gid; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                            <i class="fa-solid fa-pen"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($isMemberHere === false && $isPendingHere === false && (int) $g['isOpenEnrolment'] === 1 && $openEnrolmentOn === true): ?>
                        <form method="post" action="/small-groups/join" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
                            <input type="hidden" name="action" value="join">
                            <input type="hidden" name="groupID" value="<?php echo $gid; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success" title="Request to join">
                                <i class="fa-solid fa-user-plus"></i> Join
                            </button>
                        </form>
                    <?php elseif ($isPendingHere === true): ?>
                        <span class="badge bg-warning text-dark">Requested</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
