<?php
// Path: _apps/small-groups/group.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Group Detail 👥
 * -----------------------------------------------------------------------------
 * Read-only detail page for a single group: identity, meeting rhythm,
 * location (behind the `locationVisibility` gate — #456 shared partials,
 * no address override), roster visibility (leaders + count to everyone;
 * full names only to members/managers — membership itself is personal
 * data), recent meetings (managers only), and Join/Leave.
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

require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-display.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-map-assets.php';

Auth::ensureSession();
Auth::requireLogin();

if (SmallGroups::isEnabled() === false) {
    Router::renderError(404);
    return;
}

$siteId = Site::id();
$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);

$groupId = (int) ($_GET['id'] ?? 0);
$group   = SmallGroups::getGroup($siteId, $groupId);
if ($group === null) {
    Router::renderError(404);
    return;
}

$isManageAll = App::isAdmin() === true || App::hasRole('groups_coordinator') === true;
$isLeaderHere = SmallGroups::isLeader($siteId, $groupId, $userId);
$canManageHere = $isManageAll === true || $isLeaderHere === true;
$isMemberHere = SmallGroups::isMember($siteId, $groupId, $userId);

// 📍 Location visibility gate (#150 spec §6.3):
//   manage-group || (locationVisibility='members' && isMember)
//   || (locationVisibility='site') || (locationVisibility='leaders' && manage-group)
$visTier = (string) $group['locationVisibility'];
$locVisible = $canManageHere === true
    || ($visTier === 'members' && $isMemberHere === true)
    || ($visTier === 'site')
    || ($visTier === 'leaders' && $canManageHere === true);

if ($locVisible === true && $group['latitude'] !== null && $group['longitude'] !== null) {
    // 🗺️ Page-scoped CSP widening for OSM tiles — #386 precedent, only when
    // there is actually a pin to show. Must be set BEFORE header.php.
    $cspImgExtra = 'https://*.tile.openstreetmap.org';
}

// 👥 Roster — full names visible to members/managers only; everyone else
// sees leaders' names + a headcount (membership itself is personal data).
$rosterFull = $canManageHere === true || $isMemberHere === true;
$activeMembers = SmallGroups::membersOf($siteId, $groupId, 'active');
$leaders = array_values(array_filter($activeMembers, static fn ($m) => in_array($m['memberRole'], ['leader', 'co-leader'], true)));

$recentMeetings = [];
if ($canManageHere === true) {
    $db = App::db();
    $stmt = $db->prepare(
        'SELECT meetingID, meetingDate, topic, visitorCount, attendanceSessionID '
        . 'FROM tblSmallGroupMeetings WHERE groupID = ? AND siteID = ? ORDER BY meetingDate DESC LIMIT 10'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $groupId, $siteId);
        $stmt->execute();
        $recentMeetings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$openEnrolmentSite = (string) Settings::get('small-groups.open_enrolment', '1');
$openEnrolmentOn   = $openEnrolmentSite === '1' || $openEnrolmentSite === 'true';
$isPendingHere = false;
if ($isMemberHere === false && $userId > 0) {
    foreach (SmallGroups::groupsFor($siteId, $userId, 'pending') as $pg) {
        if ((int) $pg['groupID'] === $groupId) {
            $isPendingHere = true;
            break;
        }
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = (string) $group['groupName'];
$pageSection = 'small-groups';
$breadcrumbs = ['Dashboard' => '/', 'Small Groups' => '/small-groups', (string) $group['groupName'] => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $esc($flashType); ?> alert-dismissible fade show">
        <?php echo $esc($flashMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-people-group me-2"></i><?php echo $esc($group['groupName']); ?></h1>
        <span class="badge bg-secondary"><?php echo $esc(ucwords(str_replace('-', ' ', (string) $group['groupType']))); ?></span>
        <?php if ((int) $group['isActive'] === 0): ?><span class="badge bg-secondary">inactive</span><?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="/small-groups" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
        <?php if ($canManageHere === true): ?>
            <a href="/small-groups/manage?id=<?php echo $groupId; ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen me-1"></i>Edit</a>
            <a href="/small-groups/members?id=<?php echo $groupId; ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-users-gear me-1"></i>Manage Members</a>
            <a href="/small-groups/meeting?id=<?php echo $groupId; ?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-clipboard-check me-1"></i>Record Meeting</a>
        <?php elseif ($isMemberHere === true): ?>
            <form method="post" action="/small-groups/join" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
                <input type="hidden" name="action" value="leave">
                <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Leave this group?">
                    <i class="fa-solid fa-arrow-right-from-bracket me-1"></i>Leave
                </button>
            </form>
        <?php elseif ($isPendingHere === true): ?>
            <span class="badge bg-warning text-dark align-self-center">Join request pending</span>
        <?php elseif ((int) $group['isOpenEnrolment'] === 1 && $openEnrolmentOn === true && (int) $group['isActive'] === 1): ?>
            <form method="post" action="/small-groups/join" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
                <input type="hidden" name="action" value="join">
                <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
                <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-user-plus me-1"></i>Request to Join</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">About</div>
            <div class="card-body small">
                <?php if (($group['description'] ?? '') !== ''): ?>
                    <p><?php echo nl2br($esc($group['description'])); ?></p>
                <?php endif; ?>
                <p class="mb-1"><strong>Meets:</strong>
                    <?php
                    $meetsBits = [];
                    if ($group['meetingDay'] !== null) {
                        $meetsBits[] = $dayNames[(int) $group['meetingDay']] ?? '';
                    }
                    if ($group['meetingTime'] !== null && $group['meetingTime'] !== '') {
                        $meetsBits[] = date('g:i A', strtotime((string) $group['meetingTime']));
                    }
                    echo count($meetsBits) > 0 ? $esc(implode(' at ', $meetsBits)) : 'Varies';
                    echo ' (' . $esc(ucfirst((string) $group['meetingFrequency'])) . ')';
                    ?>
                </p>
                <?php if (($group['meetingNotes'] ?? '') !== ''): ?>
                    <p class="mb-1"><strong>Notes:</strong> <?php echo $esc($group['meetingNotes']); ?></p>
                <?php endif; ?>
                <?php if (($group['resourcesURL'] ?? '') !== ''): ?>
                    <p class="mb-1">
                        <a href="<?php echo $esc($group['resourcesURL']); ?>" target="_blank" rel="noopener">
                            <i class="fa-solid fa-book-open me-1"></i>Lesson / study resources
                        </a>
                    </p>
                <?php endif; ?>

                <?php portal_location_display([
                    'address' => [
                        'line1' => $group['addressLine1'], 'line2' => $group['addressLine2'],
                        'city' => $group['city'], 'region' => $group['region'],
                        'postcode' => $group['postcode'],
                    ],
                    'lat'     => $group['latitude'] !== null ? (float) $group['latitude'] : null,
                    'lng'     => $group['longitude'] !== null ? (float) $group['longitude'] : null,
                    'w3w'     => $group['what3words'],
                    'visible' => $locVisible,
                    'coarsen' => false,
                    'showMap' => true,
                    'mapId'   => 'sgMap',
                    'label'   => 'Meeting location',
                ]); ?>
                <?php if ($locVisible === false): ?>
                    <p class="text-muted small mb-0"><i class="fa-solid fa-lock me-1"></i>Meeting location is visible to group members only.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Leaders</div>
            <div class="card-body small">
                <?php if (count($leaders) === 0): ?>
                    <p class="text-muted mb-0">No leader assigned yet.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($leaders as $ld): ?>
                            <li class="mb-1">
                                <i class="fa-solid fa-user me-1 text-muted"></i>
                                <?php echo $esc($ld['fullName']); ?>
                                <span class="badge bg-secondary ms-1"><?php echo $esc(ucwords(str_replace('-', ' ', (string) $ld['memberRole']))); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="mt-3 mb-0">
                    <strong><?php echo count($activeMembers); ?></strong> active member<?php echo count($activeMembers) === 1 ? '' : 's'; ?>
                    <?php echo $group['capacity'] !== null ? ' (capacity ' . (int) $group['capacity'] . ')' : ''; ?>
                </p>
                <?php if ($rosterFull === true && count($activeMembers) > 0): ?>
                    <hr>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($activeMembers as $m): ?>
                            <li class="mb-1"><?php echo $esc($m['fullName']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($canManageHere === true): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                Recent Meetings
                <a href="/small-groups/report?id=<?php echo $groupId; ?>" class="btn btn-sm btn-outline-secondary">Full report</a>
            </div>
            <div class="card-body small">
                <?php if (count($recentMeetings) === 0): ?>
                    <p class="text-muted mb-0">No meetings recorded yet.</p>
                <?php else: ?>
                    <div class="portal-data-list">
                        <?php foreach ($recentMeetings as $mt): ?>
                            <div class="portal-data-row">
                                <div class="col-6 col-md-3"><?php echo $esc(date('D, j M Y', strtotime((string) $mt['meetingDate']))); ?></div>
                                <div class="col-6 col-md-5"><?php echo $esc($mt['topic'] ?? '—'); ?></div>
                                <div class="col-6 col-md-2">
                                    <?php if ($mt['attendanceSessionID'] !== null): ?>
                                        <span class="badge bg-success">pushed</span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-6 col-md-2 text-md-end">
                                    <a href="/small-groups/meeting?id=<?php echo $groupId; ?>&amp;meetingID=<?php echo (int) $mt['meetingID']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
portal_location_map_assets(App::cspNonce());
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
