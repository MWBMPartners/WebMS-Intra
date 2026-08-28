<?php
// Path: _apps/small-groups/manage.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Create / Edit Group 📝
 * -----------------------------------------------------------------------------
 * Gate: manage-all (admin || groups_coordinator) for create; manage-group
 * (manage-all || active leader/co-leader) for editing an existing group.
 * Leaders may edit their own group's details but NOT its serviceTypeID,
 * isActive, or sortOrder — those three fields render disabled for a
 * non-manage-all editor (server re-enforces in save.php by omitting the
 * columns from a leader-tier UPDATE entirely — a toggle can never wipe an
 * existing link, #436 discipline).
 *
 * Location fieldset reuses the #456 shared partial with CANONICAL column
 * names — no `names` override — so it binds byte-identical to migration
 * 180's tblVenues shape.
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

require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-input.php';

Auth::ensureSession();
Auth::requireLogin();

if (SmallGroups::isEnabled() === false) {
    Router::renderError(404);
    return;
}

$siteId = Site::id();
$isManageAll = App::isAdmin() === true || App::hasRole('groups_coordinator') === true;

$groupId    = (int) ($_GET['id'] ?? 0);
$editGroup  = $groupId > 0 ? SmallGroups::getGroup($siteId, $groupId) : null;
$isCreate   = $editGroup === null;

if ($isCreate === true && $groupId > 0) {
    // ✏️ An id was posted but resolved to nothing at THIS site — 404-
    // equivalent rather than silently falling through to "create".
    Router::renderError(404);
    return;
}

if ($isCreate === true && $isManageAll === false) {
    Router::renderError(403);
    return;
}
if ($isCreate === false && SmallGroups::canManage($siteId, $groupId) === false) {
    Router::renderError(403);
    return;
}

// 🏷️ Service types — hierarchical list (attendance/record.php precedent).
$serviceTypes = [];
$db = App::db();
$stmtTypes = $db->prepare(
    'SELECT serviceTypeID, parentID, typeName FROM tblAttendanceServiceTypes '
    . 'WHERE isActive = 1 AND siteID = ? ORDER BY sortOrder, typeName'
);
if ($stmtTypes !== false) {
    $stmtTypes->bind_param('i', $siteId);
    $stmtTypes->execute();
    $result = $stmtTypes->get_result();
    while ($r = $result->fetch_assoc()) {
        $serviceTypes[] = $r;
    }
    $stmtTypes->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = $isCreate === true ? 'New Group' : 'Edit Group';
$pageSection = 'small-groups';
$breadcrumbs = ['Dashboard' => '/', 'Small Groups' => '/small-groups', $pageTitle => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$val = static fn (string $k, string $default = ''): string => $editGroup !== null
    ? $esc((string) ($editGroup[$k] ?? $default))
    : $esc($default);
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $esc($flashType); ?> alert-dismissible fade show">
        <?php echo $esc($flashMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-<?php echo $isCreate === true ? 'plus' : 'pen'; ?> me-2"></i><?php echo $esc($pageTitle); ?></h1>
    <a href="/small-groups" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
</div>

<form method="post" action="/small-groups/save">
    <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
    <input type="hidden" name="action" value="<?php echo $isCreate === true ? 'create' : 'update'; ?>">
    <?php if ($isCreate === false): ?>
        <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Identity</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="groupName" class="form-label">Group name <span class="text-danger">*</span></label>
                    <input type="text" name="groupName" id="groupName" class="form-control" maxlength="150" required value="<?php echo $val('groupName'); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label for="groupType" class="form-label">Type</label>
                    <input type="text" name="groupType" id="groupType" class="form-control" maxlength="50" list="groupTypeOptions" value="<?php echo $val('groupType', 'small-group'); ?>">
                    <datalist id="groupTypeOptions">
                        <option value="sabbath-school">
                        <option value="small-group">
                        <option value="bible-study">
                        <option value="ministry-team">
                        <option value="other">
                    </datalist>
                </div>
                <div class="col-12 col-md-3">
                    <label for="capacity" class="form-label">Member cap <small class="text-muted">(optional)</small></label>
                    <input type="number" name="capacity" id="capacity" class="form-control" min="1" value="<?php echo $val('capacity'); ?>">
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Description <small class="text-muted">(optional)</small></label>
                    <textarea name="description" id="description" class="form-control" rows="2" maxlength="1000"><?php echo $val('description'); ?></textarea>
                </div>
                <div class="col-12 col-md-6">
                    <label for="resourcesURL" class="form-label">Lesson / study resources link <small class="text-muted">(optional)</small></label>
                    <input type="url" name="resourcesURL" id="resourcesURL" class="form-control" maxlength="500" placeholder="https://…" value="<?php echo $val('resourcesURL'); ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label for="serviceTypeID" class="form-label">Attendance service type <small class="text-muted">(optional)</small></label>
                    <select name="serviceTypeID" id="serviceTypeID" class="form-select" <?php echo $isManageAll === false ? 'disabled' : ''; ?>>
                        <option value="">— No attendance link —</option>
                        <?php
                        $topLevel = array_filter($serviceTypes, static fn ($t) => $t['parentID'] === null);
                        $selectedTypeId = $editGroup !== null ? (int) ($editGroup['serviceTypeID'] ?? 0) : 0;
                        foreach ($topLevel as $parent):
                            $pid = (int) $parent['serviceTypeID'];
                        ?>
                            <option value="<?php echo $pid; ?>" <?php echo $selectedTypeId === $pid ? 'selected' : ''; ?>>
                                <?php echo $esc($parent['typeName']); ?>
                            </option>
                            <?php
                            $children = array_filter($serviceTypes, static fn ($c) => $c['parentID'] !== null && (int) $c['parentID'] === $pid);
                            foreach ($children as $child):
                                $cid = (int) $child['serviceTypeID'];
                            ?>
                                <option value="<?php echo $cid; ?>" <?php echo $selectedTypeId === $cid ? 'selected' : ''; ?>>
                                    &nbsp;&nbsp;&nbsp;&mdash; <?php echo $esc($child['typeName']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isManageAll === false): ?>
                        <input type="hidden" name="serviceTypeID_locked" value="1">
                        <small class="text-muted">Only admins/coordinators can change the attendance link.</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Meeting rhythm</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label for="meetingDay" class="form-label">Day</label>
                    <select name="meetingDay" id="meetingDay" class="form-select">
                        <option value="">Varies</option>
                        <?php
                        $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
                        $selDay = $editGroup !== null && $editGroup['meetingDay'] !== null ? (int) $editGroup['meetingDay'] : 0;
                        foreach ($days as $num => $name):
                        ?>
                            <option value="<?php echo $num; ?>" <?php echo $selDay === $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label for="meetingTime" class="form-label">Time</label>
                    <input type="time" name="meetingTime" id="meetingTime" class="form-control" value="<?php echo $val('meetingTime'); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label for="meetingFrequency" class="form-label">Frequency</label>
                    <select name="meetingFrequency" id="meetingFrequency" class="form-select">
                        <?php foreach (['weekly', 'fortnightly', 'monthly', 'adhoc'] as $freq): ?>
                            <option value="<?php echo $freq; ?>" <?php echo $val('meetingFrequency', 'weekly') === $freq ? 'selected' : ''; ?>><?php echo ucfirst($freq); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label for="isOpenEnrolment" class="form-label d-block">Open enrolment</label>
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" class="form-check-input" name="isOpenEnrolment" id="isOpenEnrolment" value="1"
                               <?php echo ($editGroup !== null && (int) $editGroup['isOpenEnrolment'] === 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label small" for="isOpenEnrolment">Members may request to join</label>
                    </div>
                </div>
                <div class="col-12">
                    <label for="meetingNotes" class="form-label">Meeting notes <small class="text-muted">(optional)</small></label>
                    <input type="text" name="meetingNotes" id="meetingNotes" class="form-control" maxlength="500" placeholder="e.g. Term time only, 2nd Tuesday of the month" value="<?php echo $val('meetingNotes'); ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Meeting location</h5></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">
                    <label for="locationVisibility" class="form-label">Who can see the meeting address?</label>
                    <select name="locationVisibility" id="locationVisibility" class="form-select">
                        <option value="leaders" <?php echo $val('locationVisibility', 'members') === 'leaders' ? 'selected' : ''; ?>>Leaders only</option>
                        <option value="members" <?php echo $val('locationVisibility', 'members') === 'members' ? 'selected' : ''; ?>>Group members</option>
                        <option value="site" <?php echo $val('locationVisibility', 'members') === 'site' ? 'selected' : ''; ?>>Everyone at this site</option>
                    </select>
                    <small class="text-muted">Groups meeting in a member's home should keep this at "Group members". There is no public option.</small>
                </div>
            </div>
            <?php
            // 🔑 portal_location_input()'s `values` array is keyed by its
            // CANONICAL field names (line1/line2/city/region/postcode/
            // countryCode/lat/lng/w3w), NOT by the tblSmallGroups column
            // names — remap explicitly (venues/manage.php precedent). No
            // `names` override is passed, so the rendered <input name="">
            // attributes stay on the canonical DEFAULTS (addressLine1,
            // latitude, longitude, what3words, …) — byte-identical to the
            // tblSmallGroups columns save.php reads back from $_POST.
            $locValues = $editGroup ?? [];
            portal_location_input([
                'values' => [
                    'line1'       => $locValues['addressLine1'] ?? '',
                    'line2'       => $locValues['addressLine2'] ?? '',
                    'city'        => $locValues['city'] ?? '',
                    'region'      => $locValues['region'] ?? '',
                    'postcode'    => $locValues['postcode'] ?? '',
                    'countryCode' => $locValues['countryCode'] ?? 'GB',
                    'lat'         => $locValues['latitude'] ?? '',
                    'lng'         => $locValues['longitude'] ?? '',
                    'w3w'         => $locValues['what3words'] ?? '',
                ],
                'lookup'     => true,
                'w3wSuggest' => true,
            ]); ?>
        </div>
    </div>

    <?php if ($isManageAll === true): ?>
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Admin</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label for="sortOrder" class="form-label">Sort order</label>
                    <input type="number" name="sortOrder" id="sortOrder" class="form-control" value="<?php echo $val('sortOrder', '0'); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label d-block">Active</label>
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" class="form-check-input" name="isActive" id="isActive" value="1"
                               <?php echo ($editGroup === null || (int) $editGroup['isActive'] === 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label small" for="isActive">Group is active</label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save</button>
        <a href="/small-groups" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
