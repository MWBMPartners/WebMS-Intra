<?php
// Path: _apps/venues/generate.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Recurring / Multi-Day Generator 🏛️🔁
 * -----------------------------------------------------------------------------
 * ONE route, two-step (preview → commit), scaled up from the resources
 * conflict-probe self-post shape (`_apps/resources/book.php` l.52-130).
 * `action=preview` calls `Venues::previewSeries()` (pure expansion, no
 * writes) purely to show the manager what would happen; `action=commit`
 * calls `Venues::generateSeries()`, which RE-EXPANDS the series from the
 * posted params itself and NEVER trusts whatever the preview rendered —
 * a hand-crafted commit POST with no prior preview at all produces the
 * identical, independently-validated result.
 *
 * `?group=N` switches to "extend series" mode: the group must belong to
 * this site (404 otherwise); since `tblVenueBookingGroups` does not itself
 * carry usageType/status/room (those live per-booking-row), the prefill for
 * those three fields is read from the group's most recent booking — a
 * documented simplification, not a stored group property.
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$db     = App::db();
$esc    = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// -----------------------------------------------------------------------------
// 🔁 Extend-series mode — ?group=N (GET) or a carried-through hidden field
// (POST). Site-scoped fetch; a miss is a uniform 404.
// -----------------------------------------------------------------------------
$extendGroupId = (int) ($_GET['group'] ?? $_POST['extendGroupID'] ?? 0);
$extendGroup   = null;
if ($extendGroupId > 0) {
    $gStmt = $db->prepare('SELECT * FROM tblVenueBookingGroups WHERE groupID = ? AND siteID = ? LIMIT 1');
    if ($gStmt !== false) {
        $gStmt->bind_param('ii', $extendGroupId, $siteId);
        $gStmt->execute();
        $extendGroup = $gStmt->get_result()->fetch_assoc();
        $gStmt->close();
    }
    if ($extendGroup === null) {
        Router::renderError(404);
        return;
    }
}

$venueId = (int) ($_GET['venue'] ?? $_POST['venueID'] ?? ($extendGroup['venueID'] ?? 0));
$venue   = null;
if ($venueId > 0) {
    $venue = Venues::getVenue($venueId, $siteId);
    if ($venue === null) {
        Router::renderError(404);
        return;
    }
}
$venues = Venues::listVenues($siteId, true);

// 📎 Prefill for an extend: the group's own most recent booking supplies
// usageType/status/room (not stored on the group row itself — see header).
$extendPrefillRow = null;
if ($extendGroup !== null) {
    $pStmt = $db->prepare(
        'SELECT usageTypeID, statusID, roomID, notes FROM tblVenueBookings '
        . 'WHERE groupID = ? AND siteID = ? AND isDeleted = 0 ORDER BY bookingDate DESC LIMIT 1'
    );
    if ($pStmt !== false) {
        $pStmt->bind_param('ii', $extendGroupId, $siteId);
        $pStmt->execute();
        $extendPrefillRow = $pStmt->get_result()->fetch_assoc();
        $pStmt->close();
    }
}

$csrf = Auth::csrfToken();
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$preview = null;

// -----------------------------------------------------------------------------
// 📨 POST — preview or commit. Values are ALWAYS re-read from $_POST for
// re-render (never trusted for anything beyond that until re-validated by
// the Venues:: methods themselves).
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /venues/generate' . ($venueId > 0 ? '?venue=' . $venueId : ''));
        exit();
    }

    $postAction = (string) ($_POST['action'] ?? 'preview');
    $frequency  = (string) ($_POST['frequency'] ?? 'weekly');
    $intervalVal = $frequency === 'fortnightly' ? 2 : max(1, (int) ($_POST['intervalVal'] ?? 1));
    $customDates = [];
    foreach (explode("\n", (string) ($_POST['customDates'] ?? '')) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $customDates[] = $line;
        }
    }

    $params = [
        'groupType'      => (string) ($_POST['groupType'] ?? 'multi-day'),
        'frequency'      => $frequency,
        'intervalVal'    => $intervalVal,
        'daysOfWeek'     => array_map('intval', (array) ($_POST['daysOfWeek'] ?? [])),
        'dateFrom'       => (string) ($_POST['dateFrom'] ?? ''),
        'dateTo'         => (string) ($_POST['dateTo'] ?? ''),
        'dates'          => $customDates,
        'roomID'         => (int) ($_POST['roomID'] ?? 0),
        'usageTypeID'    => (int) ($_POST['usageTypeID'] ?? 0),
        'statusID'       => (int) ($_POST['statusID'] ?? 0),
        'notes'          => (string) ($_POST['notes'] ?? ''),
        'label'          => (string) ($_POST['label'] ?? ''),
        'extendGroupID'  => $extendGroupId,
    ];

    if ($postAction === 'commit') {
        $result = Venues::generateSeries($siteId, $venueId, $params, $userId);
        if ($result['groupID'] === 0) {
            // ❌ Hard failure — nothing created. Re-show the form with errors.
            $preview = ['dates' => [], 'skipped' => $result['skipped'], 'errors' => $result['errors']];
        } else {
            Logger::activity('VenueSeriesGenerated', 'Generated ' . count($result['created']) . ' booking(s) for venue #' . $venueId, $userId);
            $skippedNote = count($result['skipped']) > 0 ? ' (' . count($result['skipped']) . ' skipped — already booked)' : '';
            $_SESSION['flash_msg']  = 'Created ' . count($result['created']) . ' booking(s)' . $skippedNote . '.';
            $_SESSION['flash_type'] = 'success';
            $yr = $params['dateFrom'] !== '' ? (int) date('Y', (int) strtotime($params['dateFrom'])) : (int) date('Y');
            header('Location: /venues?venue=' . $venueId . '&year=' . $yr);
            exit();
        }
    } else {
        $preview = Venues::previewSeries($siteId, $venueId, $params);
    }
} else {
    // GET defaults / extend prefill.
    $params = [
        'groupType'   => $extendGroup['groupType'] ?? 'recurring',
        'frequency'   => $extendGroup['frequency'] ?? 'weekly',
        'intervalVal' => (int) ($extendGroup['intervalVal'] ?? 1),
        'daysOfWeek'  => $extendGroup !== null && $extendGroup['daysOfWeek'] !== null
            ? array_map('intval', explode(',', (string) $extendGroup['daysOfWeek'])) : [],
        'dateFrom'    => $extendGroup !== null ? date('Y-m-d', strtotime((string) $extendGroup['dateTo'] . ' +1 day')) : '',
        'dateTo'      => '',
        'dates'       => [],
        'roomID'      => (int) ($extendPrefillRow['roomID'] ?? 0),
        'usageTypeID' => (int) ($extendPrefillRow['usageTypeID'] ?? 0),
        'statusID'    => (int) ($extendPrefillRow['statusID'] ?? 0),
        'notes'       => (string) ($extendPrefillRow['notes'] ?? ''),
        'label'       => $extendGroup['label'] ?? '',
    ];
}

$activeRooms = $venueId > 0 ? Venues::listRooms($venueId, $siteId, true) : [];
$usageTypes  = $venueId > 0 ? Venues::listUsageTypes($venueId, $siteId, true) : [];
$statuses    = $venueId > 0 ? Venues::listStatuses($siteId, true) : [];

// 🎯 Sensible default status when none chosen: the site's `standing`-category
// status if present, else first by sortOrder (most rows are weekly worship).
if ((int) $params['statusID'] <= 0 && count($statuses) > 0) {
    $default = null;
    foreach ($statuses as $st) {
        if ((string) $st['statusCategory'] === 'standing') {
            $default = $st;
            break;
        }
    }
    $params['statusID'] = (int) ($default ?? $statuses[0])['statusID'];
}

$pageTitle   = 'Generate booking series';
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venue Bookings' => '/venues', 'Generate series' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-repeat me-2"></i>Generate booking series</h1>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $esc($flashType !== '' ? $flashType : 'info'); ?>"><?php echo $esc($flashMsg); ?></div>
<?php endif; ?>

<?php if ($extendGroup !== null): ?>
    <div class="alert alert-info">Extending series: <strong><?php echo $esc($extendGroup['label'] ?? ('Group #' . $extendGroupId)); ?></strong> — previous run ended <?php echo $esc((string) $extendGroup['dateTo']); ?>.</div>
<?php endif; ?>

<?php if ($venueId <= 0): ?>
    <div class="card">
        <div class="card-body">
            <form method="get" action="/venues/generate">
                <label class="form-label" for="venue">Venue</label>
                <select class="form-select" id="venue" name="venue" required onchange="this.form.submit()">
                    <option value="0">— choose —</option>
                    <?php foreach ($venues as $v): ?>
                        <option value="<?php echo (int) $v['venueID']; ?>"><?php echo $esc($v['venueName']); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
<?php else: ?>

    <form method="post" action="/venues/generate">
        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
        <input type="hidden" name="venueID" value="<?php echo (int) $venueId; ?>">
        <input type="hidden" name="extendGroupID" value="<?php echo (int) $extendGroupId; ?>">

        <div class="card mb-3">
            <div class="card-header">Venue: <?php echo $esc($venue['venueName']); ?> <a href="/venues/generate" class="small ms-2">(change)</a></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Run type</label><br>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="groupType" id="gtRecurring" value="recurring" <?php echo $params['groupType'] === 'recurring' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="gtRecurring">Recurring</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="groupType" id="gtMultiDay" value="multi-day" <?php echo $params['groupType'] === 'multi-day' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="gtMultiDay">Multi-day run</label>
                        </div>
                        <div class="form-text">Multi-day: every day in the range. Recurring: pick a pattern below.</div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="frequency">Frequency (recurring only)</label>
                        <select class="form-select" id="frequency" name="frequency">
                            <?php foreach (Venues::FREQUENCIES as $f): ?>
                                <option value="<?php echo $esc($f); ?>" <?php echo $params['frequency'] === $f ? 'selected' : ''; ?>><?php echo ucfirst($esc($f)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="intervalVal">Every N weeks (weekly only)</label>
                        <input type="number" min="1" max="52" class="form-control" id="intervalVal" name="intervalVal" value="<?php echo (int) $params['intervalVal']; ?>">
                        <div class="form-text">Fortnightly always uses 2, regardless of this value.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Days of week (weekly/fortnightly)</label><br>
                        <?php foreach (['0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat'] as $dow => $label): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="daysOfWeek[]" id="dow<?php echo $dow; ?>" value="<?php echo $dow; ?>" <?php echo in_array((int) $dow, $params['daysOfWeek'], true) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="dow<?php echo $dow; ?>"><?php echo $esc($label); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="customDates">Custom dates (one per line, "custom" frequency only)</label>
                        <textarea class="form-control" id="customDates" name="customDates" rows="3" placeholder="2026-01-05&#10;2026-01-19"></textarea>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="dateFrom">From</label>
                        <input type="date" class="form-control" id="dateFrom" name="dateFrom" required value="<?php echo $esc($params['dateFrom']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="dateTo">To</label>
                        <input type="date" class="form-control" id="dateTo" name="dateTo" required value="<?php echo $esc($params['dateTo']); ?>">
                    </div>

                    <?php if (count($activeRooms) > 0): ?>
                        <div class="col-md-3">
                            <label class="form-label" for="roomID">Room</label>
                            <select class="form-select" id="roomID" name="roomID">
                                <option value="0">Whole venue</option>
                                <?php foreach ($activeRooms as $rm): ?>
                                    <option value="<?php echo (int) $rm['roomID']; ?>" <?php echo (int) $params['roomID'] === (int) $rm['roomID'] ? 'selected' : ''; ?>><?php echo $esc($rm['roomName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="col-md-3">
                        <label class="form-label" for="usageTypeID">Usage type</label>
                        <select class="form-select" id="usageTypeID" name="usageTypeID" required>
                            <option value="0">— choose —</option>
                            <?php foreach ($usageTypes as $t): ?>
                                <option value="<?php echo (int) $t['usageTypeID']; ?>" <?php echo (int) $params['usageTypeID'] === (int) $t['usageTypeID'] ? 'selected' : ''; ?>><?php echo $esc($t['typeName']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="statusID">Status</label>
                        <select class="form-select" id="statusID" name="statusID" required>
                            <?php foreach ($statuses as $st): ?>
                                <option value="<?php echo (int) $st['statusID']; ?>" <?php echo (int) $params['statusID'] === (int) $st['statusID'] ? 'selected' : ''; ?>><?php echo $esc($st['statusName']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="label">Label (optional)</label>
                        <input type="text" class="form-control" id="label" name="label" maxlength="150" value="<?php echo $esc($params['label']); ?>" placeholder="e.g. 2026 weekly worship">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes (applied to every booking created)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"><?php echo $esc($params['notes']); ?></textarea>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" name="action" value="preview" class="btn btn-outline-primary"><i class="fa-solid fa-eye me-1"></i>Preview</button>
                    <button type="submit" name="action" value="commit" class="btn btn-primary" data-confirm="Create these bookings now? Preview first if you haven't already.">
                        <i class="fa-solid fa-check me-1"></i>Confirm &amp; create
                    </button>
                    <a href="/venues?venue=<?php echo (int) $venueId; ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </div>
    </form>

    <?php if ($preview !== null): ?>
        <div class="card">
            <div class="card-header">Preview</div>
            <div class="card-body small">
                <p><strong><?php echo count($preview['dates']); ?> will be created</strong></p>
                <?php if (count($preview['dates']) > 0): ?>
                    <p class="text-muted"><?php echo $esc(implode(', ', array_map(static fn ($d) => date('D j M', strtotime($d)), $preview['dates']))); ?></p>
                <?php endif; ?>
                <?php if (count($preview['skipped']) > 0): ?>
                    <p><strong><?php echo count($preview['skipped']); ?> skipped — already booked</strong></p>
                    <p class="text-muted"><?php echo $esc(implode(', ', $preview['skipped'])); ?></p>
                <?php endif; ?>
                <?php if (count($preview['errors']) > 0): ?>
                    <div class="alert alert-danger"><?php echo $esc(implode(' ', $preview['errors'])); ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
