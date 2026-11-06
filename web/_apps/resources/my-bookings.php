<?php
// Path: public_html/resources/my-bookings.php
/**
 * Resource Booking — my upcoming bookings + cancel link.
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

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🕰️ "STILL TO COME" IS DECIDED ON THE ORGANISATION'S CLOCK (#783).
//    `startAt`/`endAt` are WALL-CLOCK readings on the organisation's own clock
//    (migration 252 says so in the column comment). This used to be
//    `b.endAt > NOW()` -- and `NOW()` is the DATABASE SERVER's clock, in its
//    session zone, which is `SYSTEM` by default: whatever the database host
//    happens to be set to. So an organisation in Auckland on a database host
//    in UTC kept a booking that finished at 10:00 on "my upcoming bookings"
//    until 23:00, and one in New York lost a booking still to come five hours
//    early. On the morning the clocks change it was an hour out even where
//    the two shared a country.
//
//    A member's bookings can be at more than one organisation, each on its own
//    clock, so ONE bound "now" cannot be right for all of them. The query
//    therefore takes everything that could still be to come ANYWHERE -- no
//    clock on Earth reads more than twelve hours behind UTC (fourteen is the
//    furthest AHEAD), so a reading ending after "UTC now, less one day" is a
//    superset -- and each row is
//    then judged exactly, as text, against what ITS organisation's clock
//    reads now. `Y-m-d H:i:s` text sorts in time order, so no moment is made.
$looseFloor = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d H:i:s');
$nowOnClockOf = [];
$rows = [];
$stmt = $db->prepare(
    'SELECT b.bookingID, b.startAt, b.endAt, b.purpose, b.status, '
    . '       r.name AS resourceName, r.resourceID, '
    . '       `r`.`siteID` AS `resourceSiteID` '
    . 'FROM tblResourceBooking b JOIN tblResource r ON r.resourceID = b.resourceID '
    . 'WHERE b.bookedByID = ? AND `b`.`endAt` > ? AND b.status <> \'cancelled\' '
    . 'ORDER BY b.startAt'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $userId, $looseFloor);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $orgId = (int) $r['resourceSiteID'];
        if (isset($nowOnClockOf[$orgId]) === false) {
            $nowOnClockOf[$orgId] = I18n::wallClockNow(new \DateTimeZone(Site::timezone($orgId)));
        }
        if (strcmp((string) $r['endAt'], $nowOnClockOf[$orgId]) <= 0) {
            continue; // finished, on its own organisation's clock
        }
        $rows[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'My Bookings';
$pageSection = 'resources';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Resources' => Site::url('resources'), 'My bookings' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-calendar-check me-2"></i>My Bookings</h1>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">You have no upcoming bookings. <a href="<?php echo htmlspecialchars(Site::url('resources'), ENT_QUOTES, 'UTF-8'); ?>">Book a resource →</a></div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($rows as $r):
                    $statusCls = match ($r['status']) {
                        'approved' => 'success',
                        'pending'  => 'warning',
                        'declined' => 'danger',
                        default    => 'secondary',
                    };
                ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-3">
                            <a href="<?php echo htmlspecialchars(Site::url('resources/resource'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $r['resourceID']; ?>" class="text-decoration-none">
                                <strong><?php echo htmlspecialchars((string) $r['resourceName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </a>
                        </div>
                        <div class="col-md-3 small">
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
                            <?php echo htmlspecialchars(I18n::formatClockDateTime((string) $r['startAt'], 'D j M, H:i'), ENT_QUOTES, 'UTF-8'); ?>
                            → <?php echo htmlspecialchars(I18n::formatClockDateTime((string) $r['endAt'], 'H:i'), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="col-md-3 small text-muted"><?php echo htmlspecialchars((string) ($r['purpose'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-1"><span class="badge bg-<?php echo $statusCls; ?>"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2 text-end">
                            <form method="post" action="<?php echo htmlspecialchars(Site::url('resources/action'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="bookingID" value="<?php echo (int) $r['bookingID']; ?>">
                                <input type="hidden" name="action" value="cancel">
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                        data-confirm="Cancel this booking?" data-confirm-destructive="true">Cancel</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
