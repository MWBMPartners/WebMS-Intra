<?php
// Path: public_html/resources/resource.php
/**
 * Resource Booking — single resource with upcoming bookings calendar.
 *
 * @package   Portal\Resources
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/263
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\I18n;

require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-display.php';

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . Site::url('resources'));
    exit();
}

$r = null;
$stmt = $db->prepare(
    'SELECT * FROM tblResource WHERE resourceID = ? AND siteID = ? AND isActive = 1 LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($r === null) {
    http_response_code(404);
    exit('Resource not found');
}

// 🕰️ "STILL TO COME" IS DECIDED ON THE ORGANISATION'S CLOCK (#783).
//    `startAt` is a WALL-CLOCK reading on the organisation's own clock
//    (migration 252 says so in the column comment). The query used to compare
//    it with `NOW()` -- the DATABASE SERVER's clock, in its session zone,
//    which is `SYSTEM` by default: whatever the database host happens to be
//    set to. So a room in Auckland, on a database host in UTC, went on
//    showing bookings that had started up to thirteen hours earlier, and one
//    in New York hid bookings still to come. The resource belongs to THIS
//    organisation (the query above checks `siteID`), so "now" is its clock's
//    reading, worked out here and BOUND -- never the database's.
$nowHere   = I18n::wallClockNow(new \DateTimeZone(Site::timezone($siteId)));
$lookahead = (int) (App::settings()['resources']['lookahead_days'] ?? 90);
// The window's far end, counted on the same clock: whole calendar days on from
// today's date (a reading, so no clock change can move it).
$endWindow = (string) I18n::wallClockReading($nowHere)?->modify('+' . $lookahead . ' days')->format('Y-m-d');

$bookings = [];
$stmt = $db->prepare(
    // LEFT JOIN, not JOIN. `bookedByID` is emptied when whoever made a
    // booking asks to be forgotten (migration 208). With a plain JOIN that
    // booking dropped off this list, and the room then LOOKED free for a
    // time it was still booked for.
    'SELECT b.bookingID, b.startAt, b.endAt, b.purpose, b.status, '
    . '       u.fullName AS bookedByName '
    . 'FROM tblResourceBooking b LEFT JOIN tblUsers u ON u.userID = b.bookedByID '
    . 'WHERE b.resourceID = ? AND b.status IN (\'pending\',\'approved\') '
    . '  AND `b`.`startAt` >= ? AND b.startAt <= ? '
    . 'ORDER BY b.startAt'
);
if ($stmt !== false) {
    $stmt->bind_param('iss', $id, $nowHere, $endWindow);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
}

$pageTitle   = (string) $r['name'];
$pageSection = 'resources';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Resources' => Site::url('resources'), (string) $r['name'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-1"><?php echo htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
<p class="text-muted">
    <?php echo htmlspecialchars(ucfirst((string) $r['category']), ENT_QUOTES, 'UTF-8'); ?>
    <?php if ($r['location'] !== null): ?> &middot; <?php echo htmlspecialchars((string) $r['location'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    <?php if ($r['capacity'] !== null): ?> &middot; capacity <?php echo (int) $r['capacity']; ?><?php endif; ?>
</p>

<?php if (($r['description'] ?? '') !== ''): ?>
    <p><?php echo htmlspecialchars((string) $r['description'], ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<?php portal_location_display([
    'lat'     => $r['latitude'] !== null ? (float) $r['latitude'] : null,
    'lng'     => $r['longitude'] !== null ? (float) $r['longitude'] : null,
    'w3w'     => $r['what3words'] ?? null,
    'showMap' => false,
]); ?>

<a href="<?php echo htmlspecialchars(Site::url('resources/book'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo $id; ?>" class="btn btn-primary mb-3">
    <i class="fa-solid fa-calendar-plus me-1"></i>Book this resource
</a>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Upcoming bookings (next <?php echo $lookahead; ?> days)</h2>
        <?php if (count($bookings) === 0): ?>
            <p class="text-muted mb-0">No upcoming bookings.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($bookings as $b): ?>
                    <div class="row py-2 border-bottom small">
                        <div class="col-md-3">
                            <?php
                            // 🕐 READ AS A WALL CLOCK, which is what the column
                            //    holds. This was `date(…, strtotime($stored))`,
                            //    which resolves the stored text as a moment in
                            //    PHP's default zone — so on the morning the
                            //    clocks go forward it showed an hour later than
                            //    the booking. Measured: stored `01:30` displayed
                            //    as `02:30` (#777).
                            //
                            //    It was invisible until the write side was fixed:
                            //    the ask used to be MOVED on the way in too, so
                            //    the two wrongs cancelled. **A fix on one side of
                            //    a stored value makes the other side's fault
                            //    visible — look at both sides.**
                            ?>
                            <strong><?php echo htmlspecialchars(I18n::formatClockDateTime((string) $b['startAt'], 'D j M, H:i'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <br>→ <?php echo htmlspecialchars(I18n::formatClockDateTime((string) $b['endAt'], 'H:i'), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="col-md-5"><?php echo htmlspecialchars((string) ($b['purpose'] ?? '(no purpose given)'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 text-muted"><?php echo htmlspecialchars((string) ($b['bookedByName'] ?? 'Somebody no longer recorded'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2">
                            <?php if ($b['status'] === 'pending'): ?>
                                <span class="badge bg-warning text-dark">Pending approval</span>
                            <?php else: ?>
                                <span class="badge bg-success">Approved</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
