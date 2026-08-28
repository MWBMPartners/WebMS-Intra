<?php
// Path: _apps/venues/rooms.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Rooms 🚪
 * -----------------------------------------------------------------------------
 * Optional sub-spaces of ONE venue (`?venue=N`, required, 404 unless it
 * belongs to this site). Single-space venues — the common case — simply
 * have zero rows here; `tblVenueBookings.roomID = NULL` then means "the
 * whole venue" everywhere downstream. Self-posting reference-data CRUD
 * (GET renders, POST mutates, both on this route) — `assets/orgs.php`
 * l.51-99 house shape, `?edit=ID` inline prefill.
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
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — admins or the venue_manager role only.
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$db     = App::db();

// 🏛️ Every id arriving via GET/POST is re-fetched with siteID = Site::id()
// before use — a missing/foreign venue id is a uniform 404, never a
// cross-site oracle (§4.0 hard gate).
$venueId = (int) ($_GET['venue'] ?? $_POST['venue'] ?? 0);
$venue   = $venueId > 0 ? Venues::getVenue($venueId, $siteId) : null;
if ($venue === null) {
    Router::renderError(404);
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 CSRF FIRST — before any side-effect.
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /venues/rooms?venue=' . $venueId);
        exit();
    }

    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'save') {
        $roomId = (int) ($_POST['roomID'] ?? 0);
        $newId  = Venues::saveRoom($siteId, $venueId, $roomId, [
            'roomName'    => (string) ($_POST['roomName'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'capacity'    => (string) ($_POST['capacity'] ?? ''),
            'sortOrder'   => (string) ($_POST['sortOrder'] ?? '0'),
        ], $userId);
        $_SESSION['flash_msg']  = $newId > 0 ? 'Room saved.' : 'Could not save the room — a name is required and must be unique for this venue.';
        $_SESSION['flash_type'] = $newId > 0 ? 'success' : 'danger';
    } elseif ($action === 'toggle') {
        $roomId = (int) ($_POST['roomID'] ?? 0);
        Venues::toggleRoomActive($roomId, $siteId, $userId);
        $_SESSION['flash_msg']  = 'Room updated.';
        $_SESSION['flash_type'] = 'success';
    } elseif ($action === 'delete') {
        $roomId = (int) ($_POST['roomID'] ?? 0);
        $result = Venues::deleteRoom($roomId, $siteId, $userId);
        $_SESSION['flash_msg']  = $result['ok'] === true ? 'Room deleted.' : (string) ($result['error'] ?? 'Could not delete room.');
        $_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';
    }

    header('Location: /venues/rooms?venue=' . $venueId);
    exit();
}

// 📋 List + "future bookings" count per room — makes the delete guard
// predictable before a manager tries it (§4.2).
$rooms = Venues::listRooms($venueId, $siteId, false);
foreach ($rooms as &$room) {
    $room['futureBookings'] = 0;
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM tblVenueBookings WHERE roomID = ? AND siteID = ? AND isDeleted = 0 AND bookingDate >= CURDATE()'
    );
    if ($stmt !== false) {
        $roomIdForCount = (int) $room['roomID'];
        $stmt->bind_param('ii', $roomIdForCount, $siteId);
        $stmt->execute();
        $room['futureBookings'] = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }
}
unset($room);

$editId   = (int) ($_GET['edit'] ?? 0);
$editRoom = null;
foreach ($rooms as $r) {
    if ((int) $r['roomID'] === $editId) {
        $editRoom = $r;
        break;
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Rooms — ' . (string) $venue['venueName'];
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venues' => '/venues', (string) $venue['venueName'] => '/venues/venue?id=' . $venueId, 'Rooms' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-door-open me-2"></i>Rooms — <?php echo htmlspecialchars((string) $venue['venueName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <a href="/venues/manage" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to venues</a>
</div>
<p class="text-muted">Optional sub-spaces of this venue. Leave this list empty for a single-space venue — bookings then simply mean "the whole venue".</p>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5"><?php echo $editRoom !== null ? 'Edit room' : 'Add room'; ?></h2>
        <form method="post" action="/venues/rooms?venue=<?php echo $venueId; ?>" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="venue" value="<?php echo $venueId; ?>">
            <input type="hidden" name="roomID" value="<?php echo $editRoom !== null ? (int) $editRoom['roomID'] : 0; ?>">
            <div class="col-md-4">
                <label class="form-label small" for="roomName">Room name *</label>
                <input type="text" class="form-control form-control-sm" id="roomName" name="roomName" required maxlength="150"
                       value="<?php echo $editRoom !== null ? htmlspecialchars((string) $editRoom['roomName'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="description">Description</label>
                <input type="text" class="form-control form-control-sm" id="description" name="description" maxlength="500"
                       value="<?php echo $editRoom !== null ? htmlspecialchars((string) ($editRoom['description'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="capacity">Capacity</label>
                <input type="number" min="0" class="form-control form-control-sm" id="capacity" name="capacity"
                       value="<?php echo $editRoom !== null && $editRoom['capacity'] !== null ? (int) $editRoom['capacity'] : ''; ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fa-solid fa-<?php echo $editRoom !== null ? 'check' : 'plus'; ?> me-1"></i><?php echo $editRoom !== null ? 'Update' : 'Add'; ?>
                </button>
                <?php if ($editRoom !== null): ?>
                    <a href="/venues/rooms?venue=<?php echo $venueId; ?>" class="btn btn-outline-secondary btn-sm w-100 mt-1">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (count($rooms) === 0): ?>
    <div class="alert alert-info">No rooms — this venue is treated as a single space.</div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-4">Room</div>
            <div class="col-2">Capacity</div>
            <div class="col-2">Future bookings</div>
            <div class="col-4 text-end">Actions</div>
        </div>
        <?php foreach ($rooms as $room): ?>
            <div class="portal-data-row align-items-center">
                <div class="col-4">
                    <strong><?php echo htmlspecialchars((string) $room['roomName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ((int) $room['isActive'] === 0): ?><span class="badge bg-secondary ms-1">inactive</span><?php endif; ?>
                    <?php if (($room['description'] ?? '') !== ''): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars((string) $room['description'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-2 small text-muted"><?php echo $room['capacity'] !== null ? (int) $room['capacity'] : '—'; ?></div>
                <div class="col-2"><span class="badge bg-light text-dark border"><?php echo (int) $room['futureBookings']; ?></span></div>
                <div class="col-4 text-end">
                    <a href="/venues/rooms?venue=<?php echo $venueId; ?>&edit=<?php echo (int) $room['roomID']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                    <form method="post" action="/venues/rooms?venue=<?php echo $venueId; ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="venue" value="<?php echo $venueId; ?>">
                        <input type="hidden" name="roomID" value="<?php echo (int) $room['roomID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?php echo (int) $room['isActive'] === 1 ? 'warning' : 'success'; ?>"
                                title="<?php echo (int) $room['isActive'] === 1 ? 'Deactivate' : 'Activate'; ?>">
                            <i class="fa-solid fa-toggle-<?php echo (int) $room['isActive'] === 1 ? 'on' : 'off'; ?>"></i>
                        </button>
                    </form>
                    <form method="post" action="/venues/rooms?venue=<?php echo $venueId; ?>" class="d-inline"
                          data-confirm="Delete this room? Only possible when it has no future bookings." data-confirm-destructive="true">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="venue" value="<?php echo $venueId; ?>">
                        <input type="hidden" name="roomID" value="<?php echo (int) $room['roomID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
