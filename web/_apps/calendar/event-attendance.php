<?php
// Path: _apps/calendar/event-attendance.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Multi-day attendance grid 📋✅ (#345)
 * -----------------------------------------------------------------------------
 * Coordinator / admin-only grid: rows = confirmed RSVP participants,
 * columns = each day from startDateTime → endDateTime, cells = attended
 * toggle. Walk-in enrol form for on-the-spot additions.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/345
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$eventId = (int) ($_GET['eventID'] ?? 0);
if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403);
    exit('Forbidden');
}

$siteId = Site::id();

// 📋 Load event.
$event = null;
$stmt = $mysqli->prepare('SELECT eventID, eventName, startDateTime, endDateTime FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
if ($stmt !== false) {
    $stmt->bind_param('ii', $eventId, $siteId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}
if ($event === null) { http_response_code(404); exit('Event not found'); }

// 📅 Build the day list (one column per day from start to end).
$startTs = strtotime((string) $event['startDateTime']);
$endTs   = !empty($event['endDateTime']) ? strtotime((string) $event['endDateTime']) : $startTs;
if ($endTs < $startTs) { $endTs = $startTs; }
$days = [];
for ($t = $startTs; $t <= $endTs + 1; $t += 86400) {
    $days[] = date('Y-m-d', $t);
    if (count($days) >= 14) { break; } // cap at 14 days for sanity
}
if (count($days) === 0) { $days[] = date('Y-m-d', $startTs); }

// 👥 Participants: confirmed RSVPs (with userID).
$participants = [];
$stmt = $mysqli->prepare(
    'SELECT u.userID, u.fullName '
    . 'FROM tblEventRSVPs r '
    . 'JOIN tblUsers u ON u.userID = r.userID '
    . 'WHERE r.eventID = ? AND r.status = "confirmed" '
    . 'ORDER BY u.fullName ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $participants[] = ['userID' => (int) $r['userID'], 'name' => (string) $r['fullName'], 'isWalkin' => false, 'walkinName' => null];
    }
    $stmt->close();
}

// 🚶 Walk-ins (anonymous attendees with no RSVP row).
$stmt = $mysqli->prepare(
    'SELECT DISTINCT walkinName FROM tblEventAttendance '
    . 'WHERE eventID = ? AND userID IS NULL AND walkinName IS NOT NULL '
    . 'ORDER BY walkinName ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $participants[] = ['userID' => null, 'name' => (string) $r['walkinName'], 'isWalkin' => true, 'walkinName' => (string) $r['walkinName']];
    }
    $stmt->close();
}

// ✅ Build attendance map: key = userID:dayDate OR walkin:walkinName:dayDate
$attended = [];
$stmt = $mysqli->prepare(
    'SELECT userID, walkinName, dayDate FROM tblEventAttendance WHERE eventID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $key = $r['userID'] !== null
            ? 'u:' . (int) $r['userID'] . ':' . $r['dayDate']
            : 'w:' . (string) $r['walkinName'] . ':' . $r['dayDate'];
        $attended[$key] = true;
    }
    $stmt->close();
}

$pageTitle   = 'Attendance — ' . (string) $event['eventName'];
$pageSection = 'calendar';
$csrf        = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container-fluid py-3">
    <h1 class="h4 mb-2"><i class="fa-solid fa-clipboard-check me-2 text-primary"></i>Attendance — <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small">
        Click a cell to toggle. <strong><?php echo count($participants); ?></strong> participants
        across <strong><?php echo count($days); ?></strong> day<?php echo count($days) === 1 ? '' : 's'; ?>.
    </p>

    <?php if (count($participants) === 0): ?>
        <div class="alert alert-info">No confirmed RSVPs yet — add a walk-in to get started.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <?php foreach ($days as $d): ?>
                            <th class="text-center" style="min-width: 90px;">
                                <?php echo htmlspecialchars(date('D j M', strtotime($d)), ENT_QUOTES, 'UTF-8'); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $p): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($p['isWalkin']): ?>
                                    <span class="badge bg-warning text-dark ms-1" title="Walk-in">WI</span>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($days as $d):
                                $key = $p['userID'] !== null ? 'u:' . $p['userID'] . ':' . $d : 'w:' . $p['walkinName'] . ':' . $d;
                                $isAttended = isset($attended[$key]);
                            ?>
                                <td class="text-center">
                                    <form method="post" action="/calendar/event/attendance/mark" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                        <?php if ($p['userID'] !== null): ?>
                                            <input type="hidden" name="userID" value="<?php echo (int) $p['userID']; ?>">
                                        <?php else: ?>
                                            <input type="hidden" name="walkinName" value="<?php echo htmlspecialchars((string) $p['walkinName'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php endif; ?>
                                        <input type="hidden" name="dayDate" value="<?php echo htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="toggle" value="<?php echo $isAttended ? '0' : '1'; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $isAttended ? 'btn-success' : 'btn-outline-secondary'; ?>" style="width: 50px;">
                                            <?php echo $isAttended ? '<i class="fa-solid fa-check"></i>' : ''; ?>
                                        </button>
                                    </form>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="h6 mt-4">Walk-in enrol</h2>
    <form method="post" action="/calendar/event/attendance/mark" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
        <input type="hidden" name="toggle" value="1">
        <div class="col-md-4">
            <label for="walkinName" class="form-label small">Name</label>
            <input type="text" id="walkinName" name="walkinName" required maxlength="120" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
            <label for="walkinDay" class="form-label small">Day</label>
            <select id="walkinDay" name="dayDate" class="form-select form-select-sm" required>
                <?php foreach ($days as $d): ?>
                    <option value="<?php echo htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(date('D j M', strtotime($d)), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-user-plus me-1"></i>Add walk-in
            </button>
        </div>
    </form>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
