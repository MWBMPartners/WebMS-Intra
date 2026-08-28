<?php
// Path: _apps/venues/index.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Year Schedule 🏛️📅
 * -----------------------------------------------------------------------------
 * THE landing page of the Venue Bookings app: a spreadsheet-shaped, month-
 * grouped list of every hire date for the selected venue/year — one row per
 * booking, matching the source spreadsheet the church already keeps 1:1
 * (02-app-design.md §4.1). Viewers get a read-only schedule + CSV/PDF export
 * links; managers (`Venues::canManage()`) additionally see the Cost column,
 * an Edit link per row, and the full management toolbar.
 *
 * Shell/gate pattern: `_apps/resources/index.php` l.16-47. Data-list pattern:
 * `_apps/assets/orgs.php` l.163-205 (`portal-data-list`, never a table tag).
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
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

$siteId  = Site::id();
$isMgr   = Venues::canManage();

// 🌱 First-access idempotent seed (Q8) — cheap short-circuit inside.
Venues::seedStatuses($siteId);

// -----------------------------------------------------------------------------
// 🔎 Resolve the effective venue: explicit ?venue=, else the site's only
// active venue, else "no venue chosen yet" (a required picker is shown).
// -----------------------------------------------------------------------------
$venues     = Venues::listVenues($siteId, true);
$requestedVenueId = (int) ($_GET['venue'] ?? 0);
$venueId    = 0;
if ($requestedVenueId > 0) {
    foreach ($venues as $v) {
        if ((int) $v['venueID'] === $requestedVenueId) {
            $venueId = $requestedVenueId;
            break;
        }
    }
} elseif (count($venues) === 1) {
    $venueId = (int) $venues[0]['venueID'];
}

$year      = (int) ($_GET['year'] ?? date('Y'));
if ($year < 1970 || $year > 2200) {
    $year = (int) date('Y');
}
$statusId    = (int) ($_GET['status'] ?? 0);
$usageTypeId = (int) ($_GET['type'] ?? 0);
$roomId      = (int) ($_GET['room'] ?? 0);
$showRejected = (string) ($_GET['rejected'] ?? '1') !== '0';

$statuses    = Venues::listStatuses($siteId, false);
$usageTypes  = $venueId > 0 ? Venues::listUsageTypes($venueId, $siteId, false) : [];
$rooms       = $venueId > 0 ? Venues::listRooms($venueId, $siteId, false) : [];

$bookings = [];
$years    = [(int) date('Y'), (int) date('Y') + 1];
if ($venueId > 0) {
    $bookings = Venues::listBookings($siteId, [
        'venueID'         => $venueId,
        'year'            => $year,
        'statusID'        => $statusId,
        'usageTypeID'     => $usageTypeId,
        'roomID'          => $roomId,
        'includeRejected' => $showRejected,
    ]);

    // 📆 Distinct years with bookings for this venue, for the year picker —
    // a simple read-only reference query (no Venues:: method exists for
    // "distinct years"; the schema is real and site/venue-scoped).
    $db = App::db();
    $yStmt = $db->prepare('SELECT DISTINCT YEAR(bookingDate) AS y FROM tblVenueBookings WHERE venueID = ? AND siteID = ? AND isDeleted = 0');
    if ($yStmt !== false) {
        $yStmt->bind_param('ii', $venueId, $siteId);
        $yStmt->execute();
        $yRes = $yStmt->get_result();
        while ($yRow = $yRes->fetch_assoc()) {
            $years[] = (int) $yRow['y'];
        }
        $yStmt->close();
    }
    $years = array_values(array_unique($years));
    sort($years);
}

$currency = (string) (Settings::get('venues.currency', 'GBP') ?? 'GBP');
$today    = date('Y-m-d');

$pageTitle   = 'Venue Bookings';
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venue Bookings' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// 🎨 Same colour formula as Venues::availabilityForRange() — kind colours
// win over status colour for closed/unavailable rows.
$rowColor = static function (array $row): string {
    $kindColor = Venues::KIND_COLORS[(string) $row['usageKind']] ?? null;
    if ($kindColor !== null) {
        return $kindColor;
    }
    return Venues::statusColor(['color' => $row['statusColorRaw'] ?? null, 'statusCategory' => $row['statusCategory'] ?? null]);
};
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-church me-2"></i>Venue Bookings</h1>
        <p class="text-secondary mb-0">The agreed hire schedule for a rented building — one row per date.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($isMgr === true): ?>
            <a href="/venues/booking?venue=<?php echo (int) $venueId; ?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Booking</a>
            <a href="/venues/generate?venue=<?php echo (int) $venueId; ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-repeat me-1"></i>Generate series</a>
            <a href="/venues/import" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-file-import me-1"></i>Import</a>
            <a href="/venues/agreements" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-file-signature me-1"></i>Agreements</a>
            <a href="/venues/invoices" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-file-invoice me-1"></i>Invoices</a>
            <div class="btn-group">
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-gear me-1"></i>Config
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="/venues/manage">Manage venues</a></li>
                    <li><a class="dropdown-item" href="/venues/statuses">Statuses</a></li>
                    <li><a class="dropdown-item" href="/venues/usage-types?venue=<?php echo (int) $venueId; ?>">Usage types</a></li>
                    <li><a class="dropdown-item" href="/venues/rooms?venue=<?php echo (int) $venueId; ?>">Rooms</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="/venues/settings">Settings</a></li>
                </ul>
            </div>
        <?php else: ?>
            <?php if ($venueId > 0): ?>
                <a href="/venues/export?venue=<?php echo (int) $venueId; ?>&amp;year=<?php echo (int) $year; ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-file-csv me-1"></i>CSV</a>
                <a href="/venues/schedule-pdf?venue=<?php echo (int) $venueId; ?>&amp;year=<?php echo (int) $year; ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-file-pdf me-1"></i>PDF</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (count($venues) === 0): ?>
    <div class="alert alert-info">
        No venues configured yet.
        <?php if ($isMgr === true): ?>
            <a href="/venues/manage">Add one to get started →</a>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="/venues" class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="venue">Venue</label>
                    <select class="form-select form-select-sm" id="venue" name="venue" onchange="this.form.submit()">
                        <?php if (count($venues) > 1): ?><option value="0">— choose —</option><?php endif; ?>
                        <?php foreach ($venues as $v): ?>
                            <option value="<?php echo (int) $v['venueID']; ?>" <?php echo $venueId === (int) $v['venueID'] ? 'selected' : ''; ?>>
                                <?php echo $esc($v['venueName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="year">Year</label>
                    <select class="form-select form-select-sm" id="year" name="year" onchange="this.form.submit()">
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo (int) $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo (int) $y; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($venueId > 0): ?>
                    <div class="col-6 col-md-2">
                        <label class="form-label small" for="status">Status</label>
                        <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($statuses as $st): ?>
                                <option value="<?php echo (int) $st['statusID']; ?>" <?php echo $statusId === (int) $st['statusID'] ? 'selected' : ''; ?>>
                                    <?php echo $esc($st['statusName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small" for="type">Usage type</label>
                        <select class="form-select form-select-sm" id="type" name="type" onchange="this.form.submit()">
                            <option value="0">All</option>
                            <?php foreach ($usageTypes as $ut): ?>
                                <option value="<?php echo (int) $ut['usageTypeID']; ?>" <?php echo $usageTypeId === (int) $ut['usageTypeID'] ? 'selected' : ''; ?>>
                                    <?php echo $esc($ut['typeName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (count($rooms) > 0): ?>
                        <div class="col-6 col-md-2">
                            <label class="form-label small" for="room">Room</label>
                            <select class="form-select form-select-sm" id="room" name="room" onchange="this.form.submit()">
                                <option value="0">All</option>
                                <?php foreach ($rooms as $rm): ?>
                                    <option value="<?php echo (int) $rm['roomID']; ?>" <?php echo $roomId === (int) $rm['roomID'] ? 'selected' : ''; ?>>
                                        <?php echo $esc($rm['roomName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-6 col-md-2 form-check ms-1">
                        <input type="checkbox" class="form-check-input" id="rejected" name="rejected" value="1" onchange="this.form.submit()" <?php echo $showRejected === true ? 'checked' : ''; ?>>
                        <label class="form-check-label small" for="rejected">Show rejected</label>
                        <?php if ($showRejected === false): ?><input type="hidden" name="rejected" value="0"><?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($venueId <= 0): ?>
        <div class="alert alert-warning">Choose a venue above to view its schedule.</div>
    <?php elseif (count($bookings) === 0): ?>
        <div class="alert alert-info">
            No bookings for <?php echo (int) $year; ?> yet.
            <?php if ($isMgr === true): ?>
                <a href="/venues/booking?venue=<?php echo (int) $venueId; ?>">Add the first one →</a>
                or <a href="/venues/generate?venue=<?php echo (int) $venueId; ?>">generate a series</a>
                or <a href="/venues/import">import a workbook</a>.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php
        $lastMonth  = '';
        $seenDates  = [];
        ?>
        <div class="portal-data-list">
            <div class="portal-data-header">
                <div class="col-2">Date</div>
                <div class="col-1">Day</div>
                <div class="col-2">Usage type</div>
                <div class="col-2">Times</div>
                <div class="col-2">Status</div>
                <div class="col-<?php echo $isMgr === true ? '2' : '3'; ?>">Notes</div>
                <?php if ($isMgr === true): ?><div class="col-1 text-end">Cost</div><?php endif; ?>
            </div>
            <?php foreach ($bookings as $row): ?>
                <?php
                $date = (string) $row['bookingDate'];
                $month = date('F Y', strtotime($date));
                if ($month !== $lastMonth):
                    $lastMonth = $month;
                ?>
                    <div class="portal-data-row bg-body-tertiary fw-semibold">
                        <div class="col-12"><?php echo $esc($month); ?></div>
                    </div>
                <?php endif; ?>
                <?php
                $anchorId = '';
                if (isset($seenDates[$date]) === false) {
                    $seenDates[$date] = true;
                    $anchorId = 'd-' . $date;
                }
                $isToday = $date === $today;
                $timesNeeded = (string) $row['usageKind'] === 'hire' && $row['startTime'] === null;
                ?>
                <div class="portal-data-row align-items-center<?php echo $isToday === true ? ' border-primary border-2' : ''; ?>" <?php echo $anchorId !== '' ? 'id="' . $esc($anchorId) . '"' : ''; ?>>
                    <div class="col-2" data-label="Date">
                        <?php echo $esc(date('j M Y', strtotime($date))); ?>
                        <?php if ($isToday === true): ?><span class="badge bg-primary ms-1">Today</span><?php endif; ?>
                        <?php if ($row['roomName'] !== null): ?><br><small class="text-muted"><?php echo $esc($row['roomName']); ?></small><?php endif; ?>
                    </div>
                    <div class="col-1 small text-muted" data-label="Day"><?php echo $esc(date('D', strtotime($date))); ?></div>
                    <div class="col-2" data-label="Usage type"><?php echo $esc($row['usageTypeName']); ?></div>
                    <div class="col-2" data-label="Times">
                        <?php if ($row['startTime'] !== null && $row['endTime'] !== null): ?>
                            <?php echo $esc(substr((string) $row['startTime'], 0, 5) . '–' . substr((string) $row['endTime'], 0, 5)); ?>
                        <?php else: ?>
                            —
                            <?php if ($timesNeeded === true): ?><span class="badge bg-warning text-dark ms-1">times needed</span><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-2" data-label="Status">
                        <span class="badge" style="background-color: <?php echo $esc($rowColor($row)); ?>; color:#fff;">
                            <?php echo $esc($row['statusName']); ?>
                        </span>
                    </div>
                    <div class="col-<?php echo $isMgr === true ? '2' : '3'; ?> small text-muted" data-label="Notes">
                        <?php echo $row['notes'] !== null ? $esc($row['notes']) : ''; ?>
                    </div>
                    <?php if ($isMgr === true): ?>
                        <div class="col-1 text-end small" data-label="Cost">
                            <?php echo $row['costPence'] !== null ? $esc($currency) . ' ' . number_format((int) $row['costPence'] / 100, 2) : '—'; ?>
                            <br><a href="/venues/booking?id=<?php echo (int) $row['bookingID']; ?>" class="small">Edit</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
