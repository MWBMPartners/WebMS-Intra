<?php
// Path: public_html/resources/book.php
/**
 * Resource Booking — booking form + conflict-detecting submit.
 *
 * @package   Portal\Resources
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/263
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$id     = (int) ($_GET['id'] ?? $_POST['resourceID'] ?? 0);

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

$flash = '';
$flashType = 'info';
$prefillDate  = '';
$prefillStart = '';
$prefillEnd   = '';
$prefillPurpose = '';
$prefillNotes = '';

/**
 * 🕐 Move a wall-clock value by a number of MINUTES, staying on the wall clock.
 *
 * ⚠️ NO ZONE AND NO MOMENT, deliberately. `tblResourceBooking` holds wall-clock
 *    values local to the organisation and the conflict query compares them as
 *    text, so the buffer either side of a booking has to be added the same way.
 *    Building a moment to add the minutes and formatting it back would move the
 *    window an hour on the morning the clocks go forward — away from the very
 *    bookings it exists to overlap.
 *
 *    Minutes are an EXACT length, so adding them to a reading is arithmetic, not
 *    a calendar question. Crossing midnight is handled by letting the date carry,
 *    which `mktime()` does without consulting a zone for this purpose: the value
 *    goes in and comes out in the same frame.
 *
 * @param string $wallClock `Y-m-d H:i:s`, assumed well formed by the caller.
 * @param int    $minutes   Positive or negative.
 *
 * @return string The same shape, moved.
 */
function shiftWallClock(string $wallClock, int $minutes): string
{
    $year  = (int) substr($wallClock, 0, 4);
    $month = (int) substr($wallClock, 5, 2);
    $day   = (int) substr($wallClock, 8, 2);
    $hour  = (int) substr($wallClock, 11, 2);
    $min   = (int) substr($wallClock, 14, 2);

    // UTC on purpose: it has no transitions, so the arithmetic is pure and the
    // answer is the wall-clock reading moved by exactly that many minutes.
    $moved = new DateTimeImmutable(
        sprintf('%04d-%02d-%02dT%02d:%02d:00', $year, $month, $day, $hour, $min),
        new DateTimeZone('UTC')
    );

    return $moved->modify(($minutes >= 0 ? '+' : '-') . abs($minutes) . ' minutes')
        ->format('Y-m-d H:i:s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
    $date    = (string) ($_POST['date'] ?? '');
    $start   = (string) ($_POST['startTime'] ?? '');
    $end     = (string) ($_POST['endTime'] ?? '');
    $purpose = trim((string) ($_POST['purpose'] ?? ''));
    $notes   = trim((string) ($_POST['notes'] ?? ''));

    $prefillDate    = $date;
    $prefillStart   = $start;
    $prefillEnd     = $end;
    $prefillPurpose = $purpose;
    $prefillNotes   = $notes;

    // 🕐 A BOOKING IS A TIME ON A CLOCK, AND IT STAYS ONE (#777).
    //
    // 🚨 THIS STORED THE WRONG HOUR, one morning a year. It used to build
    //    `$date . ' ' . $start . ':00'` and then hand it to `strtotime()`,
    //    which resolves a wall-clock reading in PHP's DEFAULT zone — not the
    //    organisation's. On the morning the clocks go forward that reading
    //    does not exist, so PHP moved it, and the moved value was formatted
    //    back into the INSERT. Measured in Europe/London on 2026-03-29:
    //
    //        asked 01:00-01:30  ->  STORED 02:00-02:30
    //        asked 00:30-01:30  ->  STORED 00:30-02:30   (one hour became two)
    //        asked 01:30-02:00  ->  REFUSED, "End time must be after start
    //                               time" — because the start had moved past
    //                               the end
    //
    //    So a member booked a room for an hour they did not ask for, or was
    //    turned away for a reason that was not true. `tblResourceBooking`
    //    holds wall-clock values local to the organisation, the conflict query
    //    compares them as text, and the pages read them back as text — so the
    //    posted reading was already in exactly the form to store. Turning it
    //    into a moment and back was the whole fault.
    $startAt = $date . ' ' . $start . ':00';
    $endAt   = $date . ' ' . $end . ':00';

    // Shaped like a date and a time? Asked of the TEXT, because that is what
    // these values are. `strtotime()` used to answer this and also moved them.
    $wellFormed = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $startAt) === 1
        && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $endAt) === 1
        && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)) === true;

    // ⏱️ "Is the start in the past" is the ONE question here that genuinely
    //    needs a moment, so it is the one place a zone appears — the
    //    organisation's own, through `Site::timezone()`, never PHP's default.
    //
    //    A reading inside the hour that does not exist has no moment at all.
    //    PHP resolves it FORWARD, which is what `IcsReader` already decided a
    //    repeat should do (#645), so the two agree rather than each guessing.
    $startMoment = null;
    if ($wellFormed === true) {
        try {
            $zone = new DateTimeZone(Site::timezone());
        } catch (Throwable $e) {
            $zone = new DateTimeZone('UTC');
        }
        $built = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startAt, $zone);
        $startMoment = $built === false ? null : $built;
    }

    if ($date === '' || $start === '' || $end === '' || $wellFormed === false) {
        $flash = 'Please supply a valid date and time range.';
        $flashType = 'danger';
    } elseif ($endAt <= $startAt) {
        // Wall-clock text compares correctly as TEXT, which is #435's own fix
        // and the reason this no longer refuses a valid booking: nothing has
        // been moved, so the end really is after the start.
        $flash = 'End time must be after start time.';
        $flashType = 'danger';
    } elseif ($startMoment !== null && $startMoment->getTimestamp() < time()) {
        $flash = 'Start time is in the past.';
        $flashType = 'danger';
    } else {
        // Conflict detection — include the buffer either side.
        //
        // ⚠️ THE BUFFER IS ADDED TO THE WALL CLOCK, not to a moment, because
        //    the stored values are wall-clock text and the query below compares
        //    them as text. Building a moment to add minutes and formatting it
        //    back would reintroduce exactly the shift this file just lost: on
        //    the change morning the window would move an hour away from the
        //    bookings it is meant to overlap.
        $buffer = (int) $r['bufferMinutes'];
        $windowStart = shiftWallClock($startAt, -$buffer);
        $windowEnd   = shiftWallClock($endAt, $buffer);

        $conflict = false;
        $stmt = $db->prepare(
            'SELECT 1 FROM tblResourceBooking '
            . "WHERE resourceID = ? AND status IN ('pending','approved') "
            . '  AND startAt < ? AND endAt > ? LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iss', $id, $windowEnd, $windowStart);
            $stmt->execute();
            $conflict = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        }

        if ($conflict === true) {
            $flash = 'Conflict — another booking already covers this window (including buffer).';
            $flashType = 'danger';
        } else {
            $autoApprove = (int) $r['requiresApproval'] === 0 || App::isAdmin() === true;
            $status = $autoApprove === true ? 'approved' : 'pending';
            $approvedBy = $autoApprove === true ? $userId : null;
            $approvedAt = $autoApprove === true ? date('Y-m-d H:i:s') : null;

            $purposeVal = $purpose !== '' ? $purpose : null;
            $notesVal   = $notes   !== '' ? $notes   : null;

            $stmt = $db->prepare(
                'INSERT INTO tblResourceBooking '
                . '(resourceID, bookedByID, startAt, endAt, purpose, status, approvedByID, approvedAt, notes) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt !== false) {
                // The posted reading, stored exactly as it was asked for. It
                // used to be a timestamp formatted back, which is where the
                // moved hour entered the database.
                $stmt->bind_param('iissssiss', $id, $userId, $startAt, $endAt, $purposeVal, $status, $approvedBy, $approvedAt, $notesVal);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_msg']  = $autoApprove === true
                    ? 'Booking confirmed.'
                    : 'Booking submitted — awaiting admin approval.';
                $_SESSION['flash_type'] = 'success';
                header('Location: ' . Site::url('resources/my-bookings'));
                exit();
            }
        }
    }
}

$pageTitle   = 'Book ' . $r['name'];
$pageSection = 'resources';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Resources' => Site::url('resources'), $r['name'] => Site::url('resources/resource') . '?id=' . $id, 'Book' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-calendar-plus me-2"></i>Book <?php echo htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8'); ?></h1>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ((int) $r['requiresApproval'] === 1 && App::isAdmin() === false): ?>
    <div class="alert alert-info small">
        <i class="fa-solid fa-circle-info me-1"></i>This resource requires admin approval. Your booking will be marked "pending" until approved.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="resourceID" value="<?php echo (int) $id; ?>">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($prefillDate, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Start time</label>
                    <input type="time" name="startTime" class="form-control" required value="<?php echo htmlspecialchars($prefillStart, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End time</label>
                    <input type="time" name="endTime" class="form-control" required value="<?php echo htmlspecialchars($prefillEnd, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Purpose (visible to admins + on the resource's booking list)</label>
                    <input type="text" name="purpose" class="form-control" maxlength="255" value="<?php echo htmlspecialchars($prefillPurpose, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Sabbath school class">
                </div>
                <div class="col-12">
                    <label class="form-label">Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($prefillNotes, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Request booking</button>
                <a href="<?php echo htmlspecialchars(Site::url('resources/resource'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $id; ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
