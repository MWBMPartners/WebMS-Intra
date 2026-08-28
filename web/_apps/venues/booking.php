<?php
// Path: _apps/venues/booking.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Booking Editor 🏛️📝
 * -----------------------------------------------------------------------------
 * Two audiences on one route: viewers get a read-only detail card (no cost);
 * managers get the full save form. Two entry modes: `?id=N` (existing
 * booking, 404 unless it belongs to this site) or `?venue=N[&date=]`
 * (create, prefilled). All mutation happens in `booking-save.php` — this
 * file only renders. Every referenced id (venue/room/usage type/status/
 * event/agreement/group) is re-fetched site/venue-scoped here for display,
 * and `Venues::saveBooking()` re-validates all of it again independently
 * (02-app-design.md §4.0 Q7 — never trust what this page already checked).
 *
 * Progressive enhancement: a small inline script pre-fills start/end times
 * from the selected usage type's effective-dated default window as the
 * manager changes the type or date. This is UI convenience only — the
 * server (`Venues::saveBooking()` → `resolveWindow()`) remains the
 * authoritative source of truth and works identically with JS disabled.
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
use Portal\Core\Router;
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$isMgr  = Venues::canManage();
$db     = App::db();

// -----------------------------------------------------------------------------
// 🚪 Resolve mode — ?id=N (existing) XOR ?venue=N[&date=] (create). Every id
// is site-scoped; a miss is a uniform 404 (Q7 — no cross-tenant oracle).
// -----------------------------------------------------------------------------
$bookingId   = (int) ($_GET['id'] ?? 0);
$booking     = null;
$prefillDate = '';

if ($bookingId > 0) {
    $booking = Venues::getBooking($bookingId, $siteId);
    if ($booking === null) {
        Router::renderError(404);
        return;
    }
    $venueId = (int) $booking['venueID'];
} else {
    $venueId = (int) ($_GET['venue'] ?? 0);
    $prefillDate = (string) ($_GET['date'] ?? '');
    if ($isMgr === false) {
        // Viewers may only view existing bookings, never create.
        header('Location: /venues?venue=' . $venueId);
        exit();
    }
}

$venue = Venues::getVenue($venueId, $siteId);
if ($venue === null) {
    Router::renderError(404);
    return;
}

$csrf     = Auth::csrfToken();
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// -----------------------------------------------------------------------------
// 📋 Reference data for the form (managers only build this — viewers get the
// cheap read-only path below).
// -----------------------------------------------------------------------------
$activeRooms   = Venues::listRooms($venueId, $siteId, true);
$showRoomSelect = count($activeRooms) > 0;
$roomOptions   = $showRoomSelect === true ? Venues::listRooms($venueId, $siteId, false) : [];
$typeOptions   = Venues::listUsageTypes($venueId, $siteId, false);
$statusOptions = Venues::listStatuses($siteId, false);

$currentAgreementId = (int) ($booking['agreementID'] ?? 0);
$agreements = array_values(array_filter(
    Venues::listAgreements($siteId, ['venueID' => $venueId]),
    static fn (array $a) => (string) $a['status'] !== 'superseded' || (int) $a['agreementID'] === $currentAgreementId
));

// 📅 Event picker: events within ±1 day of the relevant date, site-scoped.
$anchorDate = $prefillDate !== '' ? $prefillDate : (string) ($booking['bookingDate'] ?? date('Y-m-d'));
$events = [];
$evStmt = $db->prepare(
    'SELECT eventID, eventName, startDateTime FROM tblEvents '
    . 'WHERE siteID = ? AND isDeleted = 0 '
    . 'AND DATE(startDateTime) BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY) '
    . 'ORDER BY startDateTime'
);
if ($evStmt !== false) {
    $evStmt->bind_param('iss', $siteId, $anchorDate, $anchorDate);
    $evStmt->execute();
    $evRes = $evStmt->get_result();
    while ($evRow = $evRes->fetch_assoc()) {
        $events[(int) $evRow['eventID']] = $evRow;
    }
    $evStmt->close();
}
$currentEventId = (int) ($booking['eventID'] ?? 0);
if ($currentEventId > 0 && isset($events[$currentEventId]) === false) {
    $evStmt = $db->prepare('SELECT eventID, eventName, startDateTime FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1');
    if ($evStmt !== false) {
        $evStmt->bind_param('ii', $currentEventId, $siteId);
        $evStmt->execute();
        $row = $evStmt->get_result()->fetch_assoc();
        $evStmt->close();
        if ($row !== null) {
            $events[$currentEventId] = $row;
        }
    }
}

// 🕰️ Effective-dated windows for every usage type of this venue, for the
// client-side progressive-enhancement pre-fill only (server stays authoritative).
$windowsByType = [];
if (count($typeOptions) > 0) {
    $ids = array_map(static fn ($t) => (int) $t['usageTypeID'], $typeOptions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids) + 1);
    $wStmt = $db->prepare(
        "SELECT usageTypeID, effectiveFrom, defaultStartTime, defaultEndTime FROM tblVenueUsageTypeWindows "
        . "WHERE siteID = ? AND usageTypeID IN ($placeholders) ORDER BY usageTypeID, effectiveFrom"
    );
    if ($wStmt !== false) {
        $wStmt->bind_param($types, $siteId, ...$ids);
        $wStmt->execute();
        $wRes = $wStmt->get_result();
        while ($wRow = $wRes->fetch_assoc()) {
            $tid = (int) $wRow['usageTypeID'];
            $windowsByType[$tid][] = [
                'from'  => (string) $wRow['effectiveFrom'],
                'start' => $wRow['defaultStartTime'] !== null ? substr((string) $wRow['defaultStartTime'], 0, 5) : null,
                'end'   => $wRow['defaultEndTime'] !== null ? substr((string) $wRow['defaultEndTime'], 0, 5) : null,
            ];
        }
        $wStmt->close();
    }
}
$jsTypeData = [];
foreach ($typeOptions as $t) {
    $tid = (int) $t['usageTypeID'];
    $jsTypeData[$tid] = ['kind' => (string) $t['usageKind'], 'windows' => $windowsByType[$tid] ?? []];
}

// 👥 Group panel data (multi-day/recurring run this booking belongs to).
$group = null;
if ($booking !== null && (int) ($booking['groupID'] ?? 0) > 0) {
    $gStmt = $db->prepare('SELECT * FROM tblVenueBookingGroups WHERE groupID = ? AND siteID = ? LIMIT 1');
    if ($gStmt !== false) {
        $gStmt->bind_param('ii', $booking['groupID'], $siteId);
        $gStmt->execute();
        $group = $gStmt->get_result()->fetch_assoc();
        $gStmt->close();
    }
}

$currency = (string) ($booking['currency'] ?? Settings::get('venues.currency', 'GBP') ?? 'GBP');
$costValue = $booking !== null && $booking['costPence'] !== null ? number_format((int) $booking['costPence'] / 100, 2, '.', '') : '';

$pageTitle   = $booking !== null ? 'Booking — ' . $venue['venueName'] : 'New booking — ' . $venue['venueName'];
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venue Bookings' => '/venues', (string) $venue['venueName'] => '/venues/venue?id=' . $venueId, 'Booking' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $esc($flashType !== '' ? $flashType : 'info'); ?>"><?php echo $esc($flashMsg); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-calendar-day me-2"></i><?php echo $booking !== null ? 'Booking' : 'New booking'; ?> — <?php echo $esc($venue['venueName']); ?></h1>

<?php if ($isMgr === false): ?>
    <!-- ============================== 👁️ Read-only detail (viewer) ============================== -->
    <div class="card">
        <div class="card-body">
            <p><strong>Date:</strong> <?php echo $esc(date('l j F Y', strtotime((string) $booking['bookingDate']))); ?></p>
            <?php if ($booking['roomName'] !== null): ?><p><strong>Room:</strong> <?php echo $esc($booking['roomName']); ?></p><?php endif; ?>
            <p><strong>Usage type:</strong> <?php echo $esc($booking['usageTypeName']); ?></p>
            <p><strong>Times:</strong>
                <?php echo $booking['startTime'] !== null ? $esc(substr((string) $booking['startTime'], 0, 5) . '–' . substr((string) $booking['endTime'], 0, 5)) : '—'; ?>
            </p>
            <p><strong>Status:</strong> <span class="badge" style="background-color: <?php echo $esc(Venues::statusColor(['color' => $booking['statusColorRaw'] ?? null, 'statusCategory' => $booking['statusCategory'] ?? null])); ?>; color:#fff;"><?php echo $esc($booking['statusName']); ?></span></p>
            <?php if (($booking['notes'] ?? '') !== ''): ?><p><strong>Notes:</strong> <?php echo nl2br($esc($booking['notes'])); ?></p><?php endif; ?>
            <?php if (($booking['eventName'] ?? '') !== ''): ?><p><strong>Linked event:</strong> <?php echo $esc($booking['eventName']); ?></p><?php endif; ?>
        </div>
    </div>
    <a href="/venues?venue=<?php echo (int) $venueId; ?>" class="btn btn-outline-secondary mt-3"><i class="fa-solid fa-arrow-left me-1"></i>Back to schedule</a>

<?php else: ?>
    <!-- ============================== ✏️ Manager save form ============================== -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="post" action="/venues/booking-save">
                <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="bookingID" value="<?php echo (int) $bookingId; ?>">
                <input type="hidden" name="venueID" value="<?php echo (int) $venueId; ?>">
                <input type="hidden" name="groupID" value="<?php echo (int) ($booking['groupID'] ?? 0); ?>">

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="bookingDate">Date</label>
                        <input type="date" class="form-control" id="bookingDate" name="bookingDate" required
                               value="<?php echo $esc($booking['bookingDate'] ?? $prefillDate); ?>">
                    </div>

                    <?php if ($showRoomSelect === true): ?>
                        <div class="col-md-3">
                            <label class="form-label" for="roomID">Room</label>
                            <select class="form-select" id="roomID" name="roomID">
                                <option value="0">Whole venue</option>
                                <?php foreach ($roomOptions as $rm): $rmId = (int) $rm['roomID']; $rmCur = $rmId === (int) ($booking['roomID'] ?? 0); ?>
                                    <option value="<?php echo $rmId; ?>" <?php echo $rmCur ? 'selected' : ''; ?> <?php echo ((int) $rm['isActive'] === 0 && $rmCur === false) ? 'disabled' : ''; ?>>
                                        <?php echo $esc($rm['roomName']); ?><?php echo (int) $rm['isActive'] === 0 ? ' (inactive)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="col-md-3">
                        <label class="form-label" for="usageTypeID">Usage type</label>
                        <select class="form-select" id="usageTypeID" name="usageTypeID" required>
                            <option value="0">— choose —</option>
                            <?php foreach (['hire' => 'Hire', 'closed' => 'Closed', 'unavailable' => 'Unavailable'] as $kind => $label): ?>
                                <optgroup label="<?php echo $esc($label); ?>">
                                    <?php foreach ($typeOptions as $t): if ((string) $t['usageKind'] !== $kind) { continue; }
                                        $tId = (int) $t['usageTypeID']; $tCur = $tId === (int) ($booking['usageTypeID'] ?? 0); ?>
                                        <option value="<?php echo $tId; ?>" <?php echo $tCur ? 'selected' : ''; ?> <?php echo ((int) $t['isActive'] === 0 && $tCur === false) ? 'disabled' : ''; ?>>
                                            <?php echo $esc($t['typeName']); ?><?php echo (int) $t['isActive'] === 0 ? ' (inactive)' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="statusID">Status</label>
                        <select class="form-select" id="statusID" name="statusID" required>
                            <option value="0">— choose —</option>
                            <?php foreach ($statusOptions as $st): $sId = (int) $st['statusID']; $sCur = $sId === (int) ($booking['statusID'] ?? 0); ?>
                                <option value="<?php echo $sId; ?>" <?php echo $sCur ? 'selected' : ''; ?> <?php echo ((int) $st['isActive'] === 0 && $sCur === false) ? 'disabled' : ''; ?>>
                                    <?php echo $esc($st['statusName']); ?><?php echo (int) $st['isActive'] === 0 ? ' (inactive)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3" id="timeFields">
                        <label class="form-label" for="startTime">Start time</label>
                        <input type="time" class="form-control" id="startTime" name="startTime" value="<?php echo $esc($booking !== null && $booking['startTime'] !== null ? substr((string) $booking['startTime'], 0, 5) : ''); ?>">
                        <div class="form-text">Leave blank (with end time) to use the usage type's default window.</div>
                    </div>
                    <div class="col-md-3" id="endTimeField">
                        <label class="form-label" for="endTime">End time</label>
                        <input type="time" class="form-control" id="endTime" name="endTime" value="<?php echo $esc($booking !== null && $booking['endTime'] !== null ? substr((string) $booking['endTime'], 0, 5) : ''); ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label" for="costPence">Cost</label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo $esc($currency); ?></span>
                            <input type="number" step="0.01" min="0" class="form-control" id="costPence" name="costPence" value="<?php echo $esc($costValue); ?>">
                        </div>
                        <div class="form-text">Pounds — converted to pence when saved.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="currency">Currency</label>
                        <input type="text" class="form-control" id="currency" name="currency" maxlength="3" value="<?php echo $esc($currency); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="eventID">Linked calendar event (optional)</label>
                        <select class="form-select" id="eventID" name="eventID">
                            <option value="0">— none —</option>
                            <?php foreach ($events as $ev): $eId = (int) $ev['eventID']; ?>
                                <option value="<?php echo $eId; ?>" <?php echo $eId === $currentEventId ? 'selected' : ''; ?>>
                                    <?php echo $esc(date('j M', strtotime((string) $ev['startDateTime'])) . ' — ' . $ev['eventName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="agreementID">Hire agreement (optional)</label>
                        <select class="form-select" id="agreementID" name="agreementID">
                            <option value="0">— none —</option>
                            <?php foreach ($agreements as $ag): $aId = (int) $ag['agreementID']; ?>
                                <option value="<?php echo $aId; ?>" <?php echo $aId === $currentAgreementId ? 'selected' : ''; ?>>
                                    <?php echo $esc($ag['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">Notes — what's happening that day</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"><?php echo $esc($booking['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save</button>
                    <a href="/venues?venue=<?php echo (int) $venueId; ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($group !== null): ?>
        <div class="card mb-3">
            <div class="card-header">This booking is part of a run</div>
            <div class="card-body small">
                <p class="mb-1"><strong><?php echo $esc($group['label'] ?? ucfirst((string) $group['groupType'])); ?></strong>
                    (<?php echo $esc((string) $group['groupType']); ?><?php echo $group['frequency'] !== null ? ', ' . $esc((string) $group['frequency']) : ''; ?>)
                </p>
                <p class="mb-2 text-muted"><?php echo $esc($group['dateFrom'] . ' → ' . $group['dateTo']); ?></p>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" action="/venues/booking-save" class="d-flex align-items-center gap-1" data-confirm="Apply this status to every booking in the run?">
                        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                        <input type="hidden" name="action" value="group-status">
                        <input type="hidden" name="groupID" value="<?php echo (int) $group['groupID']; ?>">
                        <input type="hidden" name="venueID" value="<?php echo (int) $venueId; ?>">
                        <select name="statusID" class="form-select form-select-sm" required>
                            <?php foreach ($statusOptions as $st): ?>
                                <option value="<?php echo (int) $st['statusID']; ?>"><?php echo $esc($st['statusName']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Set status for whole run</button>
                    </form>
                    <form method="post" action="/venues/booking-save" data-confirm="Delete EVERY booking in this run? This can't be undone from the UI." data-confirm-destructive="true">
                        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                        <input type="hidden" name="action" value="group-delete">
                        <input type="hidden" name="groupID" value="<?php echo (int) $group['groupID']; ?>">
                        <input type="hidden" name="venueID" value="<?php echo (int) $venueId; ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete whole run</button>
                    </form>
                    <a href="/venues/generate?group=<?php echo (int) $group['groupID']; ?>" class="btn btn-outline-primary btn-sm">Extend series</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($booking !== null): ?>
        <div class="card border-danger">
            <div class="card-header text-danger">Danger zone</div>
            <div class="card-body small">
                <p class="text-muted">Cancellations should normally be a status change; delete only true mistakes.</p>
                <form method="post" action="/venues/booking-save" data-confirm="Delete this booking? This can't be undone from the UI." data-confirm-destructive="true">
                    <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="bookingID" value="<?php echo (int) $bookingId; ?>">
                    <input type="hidden" name="venueID" value="<?php echo (int) $venueId; ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm">Delete booking</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script>
    (function () {
        'use strict';
        var TYPE_DATA = <?php echo json_encode($jsTypeData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var typeSel  = document.getElementById('usageTypeID');
        var dateInp  = document.getElementById('bookingDate');
        var startInp = document.getElementById('startTime');
        var endInp   = document.getElementById('endTime');
        var timeFields = document.getElementById('timeFields');
        var endTimeField = document.getElementById('endTimeField');
        if (typeSel === null || dateInp === null || startInp === null || endInp === null) { return; }

        var userEdited = false;
        startInp.addEventListener('input', function () { userEdited = true; });
        endInp.addEventListener('input', function () { userEdited = true; });

        function bestWindow(typeId, dateStr) {
            var data = TYPE_DATA[String(typeId)];
            if (!data || !data.windows || data.windows.length === 0) { return null; }
            var best = null;
            for (var i = 0; i < data.windows.length; i++) {
                var w = data.windows[i];
                if (w.from <= dateStr && (best === null || w.from > best.from)) { best = w; }
            }
            return best;
        }

        function refresh() {
            var typeId = typeSel.value;
            var data = TYPE_DATA[String(typeId)];
            var isHire = data && data.kind === 'hire';
            if (timeFields !== null) { timeFields.style.display = isHire ? '' : 'none'; }
            if (endTimeField !== null) { endTimeField.style.display = isHire ? '' : 'none'; }
            if (isHire === true && userEdited === false) {
                var w = bestWindow(typeId, dateInp.value || '<?php echo date('Y-m-d'); ?>');
                if (w !== null) {
                    startInp.value = w.start || '';
                    endInp.value = w.end || '';
                }
            }
        }

        typeSel.addEventListener('change', function () { userEdited = false; refresh(); });
        dateInp.addEventListener('change', refresh);
        refresh();
    })();
    </script>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
