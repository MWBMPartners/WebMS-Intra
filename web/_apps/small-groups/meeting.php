<?php
// Path: _apps/small-groups/meeting.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Record / Edit a Meeting + Roll 📆
 * -----------------------------------------------------------------------------
 * `?id=` (groupID) + optional `?meetingID=` for edit mode. Gate:
 * `SmallGroups::canManage()`. One checkbox per ACTIVE member
 * (`membersOf(...,'active')`), pre-ticked from existing
 * `tblSmallGroupMeetingAttendance` rows in edit mode. "Copy last meeting's
 * topic" mirrors the Attendance app's copy-from-last convenience.
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

$db = App::db();
$meetingId = (int) ($_GET['meetingID'] ?? 0);
$editMeeting = null;
$markedUserIds = [];

if ($meetingId > 0) {
    $stmt = $db->prepare('SELECT * FROM tblSmallGroupMeetings WHERE meetingID = ? AND groupID = ? AND siteID = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('iii', $meetingId, $groupId, $siteId);
        $stmt->execute();
        $editMeeting = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if ($editMeeting !== null) {
        $mStmt = $db->prepare('SELECT userID FROM tblSmallGroupMeetingAttendance WHERE meetingID = ? AND userID IS NOT NULL');
        if ($mStmt !== false) {
            $mStmt->bind_param('i', $meetingId);
            $mStmt->execute();
            $res = $mStmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $markedUserIds[(int) $r['userID']] = true;
            }
            $mStmt->close();
        }
    }
}

// 📑 Copy last meeting's topic (convenience — no attendance data copied).
$lastTopic = null;
if ($editMeeting === null) {
    $lastStmt = $db->prepare('SELECT topic FROM tblSmallGroupMeetings WHERE groupID = ? AND siteID = ? ORDER BY meetingDate DESC LIMIT 1');
    if ($lastStmt !== false) {
        $lastStmt->bind_param('ii', $groupId, $siteId);
        $lastStmt->execute();
        $row = $lastStmt->get_result()->fetch_assoc();
        $lastStmt->close();
        $lastTopic = $row['topic'] ?? null;
    }
}

$activeMembers = SmallGroups::membersOf($siteId, $groupId, 'active');

$pushSetting = (string) Settings::get('small-groups.push_attendance', '1');
$pushSiteOn  = $pushSetting === '1' || $pushSetting === 'true';
$canOfferPush = $pushSiteOn === true && $group['serviceTypeID'] !== null;
$pushDefaultChecked = $editMeeting !== null ? ($editMeeting['attendanceSessionID'] !== null) : true;

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = ($editMeeting !== null ? 'Edit Meeting' : 'Record Meeting') . ' — ' . $group['groupName'];
$pageSection = 'small-groups';
$breadcrumbs = ['Dashboard' => '/', 'Small Groups' => '/small-groups', (string) $group['groupName'] => '/small-groups/group?id=' . $groupId, ($editMeeting !== null ? 'Edit Meeting' : 'Record Meeting') => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $esc($flashType); ?> alert-dismissible fade show">
        <?php echo $esc($flashMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-clipboard-check me-2"></i><?php echo $editMeeting !== null ? 'Edit Meeting' : 'Record Meeting'; ?> — <?php echo $esc($group['groupName']); ?></h1>
    <a href="/small-groups/group?id=<?php echo $groupId; ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
</div>

<form method="post" action="/small-groups/meeting-save">
    <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
    <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
    <?php if ($editMeeting !== null): ?>
        <input type="hidden" name="meetingID" value="<?php echo $meetingId; ?>">
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Meeting details</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label for="meetingDate" class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="date" name="meetingDate" id="meetingDate" class="form-control" required
                           value="<?php echo $esc($editMeeting['meetingDate'] ?? date('Y-m-d')); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label for="meetingTime" class="form-label">Time</label>
                    <input type="time" name="meetingTime" id="meetingTime" class="form-control"
                           value="<?php echo $esc($editMeeting['meetingTime'] ?? ($group['meetingTime'] ?? '')); ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label for="topic" class="form-label">Topic <small class="text-muted">(optional)</small></label>
                    <input type="text" name="topic" id="topic" class="form-control" maxlength="255"
                           value="<?php echo $esc($editMeeting['topic'] ?? ($lastTopic ?? '')); ?>">
                    <?php if ($editMeeting === null && $lastTopic !== null): ?>
                        <small class="text-muted">Copied from the last meeting — edit or clear as needed.</small>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-2">
                    <label for="visitorCount" class="form-label">Visitors</label>
                    <input type="number" name="visitorCount" id="visitorCount" class="form-control" min="0"
                           value="<?php echo (int) ($editMeeting['visitorCount'] ?? 0); ?>">
                </div>
                <div class="col-12">
                    <label for="notes" class="form-label">Notes <small class="text-muted">(optional)</small></label>
                    <textarea name="notes" id="notes" class="form-control" rows="2"><?php echo $esc($editMeeting['notes'] ?? ''); ?></textarea>
                </div>
                <?php if ($canOfferPush === true): ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="pushAttendance" id="pushAttendance" value="1"
                                   <?php echo $pushDefaultChecked === true ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="pushAttendance">
                                Also record headcount in Attendance <small class="text-muted">(service type: <?php echo $esc((string) ($group['serviceTypeID'])); ?>)</small>
                            </label>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Roll — active members</h5>
            <span class="badge bg-primary" id="rollCount"></span>
        </div>
        <div class="card-body">
            <?php if (count($activeMembers) === 0): ?>
                <p class="text-muted mb-0">No active members yet — <a href="/small-groups/members?id=<?php echo $groupId; ?>">add some first</a>.</p>
            <?php else: ?>
                <div class="row g-2">
                    <?php foreach ($activeMembers as $m): ?>
                        <?php $mUserId = (int) $m['userID']; ?>
                        <div class="col-12 col-sm-6 col-md-4">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input roll-check" name="present[]" id="present<?php echo $mUserId; ?>" value="<?php echo $mUserId; ?>"
                                       <?php echo isset($markedUserIds[$mUserId]) === true ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="present<?php echo $mUserId; ?>"><?php echo $esc($m['fullName']); ?></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save</button>
        <a href="/small-groups/group?id=<?php echo $groupId; ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<script nonce="<?php echo $esc(App::cspNonce()); ?>">
(function () {
    'use strict';
    var checks = document.querySelectorAll('.roll-check');
    var counter = document.getElementById('rollCount');
    function update() {
        var n = 0;
        checks.forEach(function (c) { if (c.checked) { n++; } });
        if (counter) { counter.textContent = n + ' present'; }
    }
    checks.forEach(function (c) { c.addEventListener('change', update); });
    update();
})();
</script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
