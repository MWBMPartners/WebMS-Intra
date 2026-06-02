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
    header('Location: /resources');
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

    $startAt = $date . ' ' . $start . ':00';
    $endAt   = $date . ' ' . $end . ':00';
    $startTs = strtotime($startAt);
    $endTs   = strtotime($endAt);

    if ($date === '' || $start === '' || $end === '' || $startTs === false || $endTs === false) {
        $flash = 'Please supply a valid date and time range.';
        $flashType = 'danger';
    } elseif ($endTs <= $startTs) {
        $flash = 'End time must be after start time.';
        $flashType = 'danger';
    } elseif ($startTs < time()) {
        $flash = 'Start time is in the past.';
        $flashType = 'danger';
    } else {
        // Conflict detection — include the buffer either side.
        $buffer = (int) $r['bufferMinutes'];
        $windowStart = date('Y-m-d H:i:s', $startTs - $buffer * 60);
        $windowEnd   = date('Y-m-d H:i:s', $endTs + $buffer * 60);

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
                $startDt = date('Y-m-d H:i:s', $startTs);
                $endDt   = date('Y-m-d H:i:s', $endTs);
                $stmt->bind_param('iissssiss', $id, $userId, $startDt, $endDt, $purposeVal, $status, $approvedBy, $approvedAt, $notesVal);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_msg']  = $autoApprove === true
                    ? 'Booking confirmed.'
                    : 'Booking submitted — awaiting admin approval.';
                $_SESSION['flash_type'] = 'success';
                header('Location: /resources/my-bookings');
                exit();
            }
        }
    }
}

$pageTitle   = 'Book ' . $r['name'];
$pageSection = 'resources';
$breadcrumbs = ['Dashboard' => '/', 'Resources' => '/resources', $r['name'] => '/resources/resource?id=' . $id, 'Book' => ''];
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
                <a href="/resources/resource?id=<?php echo (int) $id; ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
