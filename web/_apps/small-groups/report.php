<?php
// Path: _apps/small-groups/report.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Attendance Report 📊
 * -----------------------------------------------------------------------------
 * Gate: `SmallGroups::canManage()` for one group (via `?id=`), or manage-all
 * for the all-groups summary. Filters: group select, from/to dates
 * (default last 12 weeks). Renders `SmallGroups::attendanceStats()` as
 * `portal-data-list`. Links to CSV export.
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

$siteId = Site::id();
$isManageAll = App::isAdmin() === true || App::hasRole('groups_coordinator') === true;

$groupId = (int) ($_GET['id'] ?? 0);

// 📋 Groups this viewer may report on: manage-all sees every group,
// everyone else only groups they lead.
$myGroups = SmallGroups::listGroups($siteId, false);
if ($isManageAll === false) {
    $user = App::user();
    $userId = (int) ($user['userID'] ?? 0);
    $myGroups = array_values(array_filter(
        $myGroups,
        static fn ($g) => SmallGroups::isLeader($siteId, (int) $g['groupID'], $userId)
    ));
    if (count($myGroups) === 0) {
        Router::renderError(403);
        return;
    }
    if ($groupId === 0) {
        $groupId = (int) $myGroups[0]['groupID'];
    }
}

if ($groupId > 0 && SmallGroups::canManage($siteId, $groupId) === false) {
    Router::renderError(403);
    return;
}
$group = $groupId > 0 ? SmallGroups::getGroup($siteId, $groupId) : null;
if ($groupId > 0 && $group === null) {
    Router::renderError(404);
    return;
}

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
if ($from === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
    $from = date('Y-m-d', strtotime('-12 weeks'));
}
if ($to === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
    $to = date('Y-m-d');
}

$stats = $groupId > 0 ? SmallGroups::attendanceStats($siteId, $groupId, $from, $to) : ['meetings' => [], 'members' => []];

$pageTitle   = 'Attendance Report' . ($group !== null ? ' — ' . $group['groupName'] : '');
$pageSection = 'small-groups';
$breadcrumbs = ['Dashboard' => '/', 'Small Groups' => '/small-groups', 'Report' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-chart-column me-2"></i>Attendance Report</h1>
    <a href="/small-groups<?php echo $groupId > 0 ? '/group?id=' . $groupId : ''; ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
</div>

<form method="get" action="/small-groups/report" class="row g-2 align-items-end mb-4">
    <div class="col-12 col-md-4">
        <label for="id" class="form-label">Group</label>
        <select name="id" id="id" class="form-select" onchange="this.form.submit()">
            <?php foreach ($myGroups as $g): ?>
                <option value="<?php echo (int) $g['groupID']; ?>" <?php echo $groupId === (int) $g['groupID'] ? 'selected' : ''; ?>>
                    <?php echo $esc($g['groupName']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label for="from" class="form-label">From</label>
        <input type="date" name="from" id="from" class="form-control" value="<?php echo $esc($from); ?>">
    </div>
    <div class="col-6 col-md-3">
        <label for="to" class="form-label">To</label>
        <input type="date" name="to" id="to" class="form-control" value="<?php echo $esc($to); ?>">
    </div>
    <div class="col-12 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-filter me-1"></i>Filter</button>
    </div>
</form>

<?php if ($groupId > 0): ?>
    <div class="d-flex justify-content-end mb-2">
        <a href="/small-groups/export?type=attendance&amp;id=<?php echo $groupId; ?>&amp;from=<?php echo $esc($from); ?>&amp;to=<?php echo $esc($to); ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-file-csv me-1"></i>Export CSV
        </a>
    </div>

    <h2 class="h5 mb-2">Meetings</h2>
    <?php if (count($stats['meetings']) === 0): ?>
        <div class="alert alert-info">No meetings recorded in this date range.</div>
    <?php else: ?>
        <div class="portal-data-list mb-4">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-2">Date</div>
                <div class="col-md-4">Topic</div>
                <div class="col-md-2 text-center">Present</div>
                <div class="col-md-2 text-center">Visitors</div>
                <div class="col-md-2 text-center">Total</div>
            </div>
            <?php foreach ($stats['meetings'] as $mt): ?>
                <div class="portal-data-row">
                    <div class="col-6 col-md-2"><?php echo $esc(date('D, j M Y', strtotime((string) $mt['meetingDate']))); ?></div>
                    <div class="col-6 col-md-4"><?php echo $esc($mt['topic'] ?? '—'); ?></div>
                    <div class="col-4 col-md-2 text-md-center"><?php echo (int) $mt['presentCount']; ?></div>
                    <div class="col-4 col-md-2 text-md-center"><?php echo (int) $mt['visitorCount']; ?></div>
                    <div class="col-4 col-md-2 text-md-center"><strong><?php echo (int) $mt['total']; ?></strong></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="h5 mb-2">Members</h2>
    <?php if (count($stats['members']) === 0): ?>
        <div class="alert alert-info">No active members.</div>
    <?php else: ?>
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-5">Member</div>
                <div class="col-md-3 text-center">Present</div>
                <div class="col-md-4 text-center">Attendance</div>
            </div>
            <?php foreach ($stats['members'] as $mm): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-5"><?php echo $esc($mm['fullName']); ?></div>
                    <div class="col-6 col-md-3 text-md-center"><?php echo (int) $mm['presentCount']; ?> / <?php echo (int) $mm['meetingsCount']; ?></div>
                    <div class="col-6 col-md-4 text-md-center">
                        <div class="progress" style="height:1.25rem;">
                            <div class="progress-bar" role="progressbar" style="width:<?php echo (int) $mm['percentage']; ?>%;">
                                <?php echo (int) $mm['percentage']; ?>%
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
