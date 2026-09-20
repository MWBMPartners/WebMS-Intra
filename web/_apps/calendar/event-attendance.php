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

use Portal\Core\AnonymousCheckins;
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

// -----------------------------------------------------------------------------
// 🚪 Anonymous check-ins (#525)
// -----------------------------------------------------------------------------
// Somebody can check in to this event without signing in, by scanning a QR code
// or pressing a button on a kiosk at the door. Those presses have been recorded
// since September 2025 and, until this change, NOTHING read them — no screen, no
// report, no download. This card is the first place they appear.
//
// The page's own rule at the top of this file is UNCHANGED. It already refused
// everybody who is not an administrator or a coordinator of this event, which is
// why `$passesPageGate` below is simply true: anybody still reading this line
// got past it. The setting can only narrow from there, never widen.
//
// `Auth::isCoordinatorOf()` is used rather than a direct look at the coordinator
// table because it also applies the DBS-check gate when the organisation has
// that switched on. It answers true for any administrator before it looks at the
// coordinator list at all, which is harmless here: the owner decided on
// 18 September 2026 that administrators always see these figures anyway.
$anonChoice  = AnonymousCheckins::readVisibilityChoice($siteId);
$mayViewAnon = AnonymousCheckins::mayView(
    $anonChoice,
    true,
    App::isAdmin(),
    Auth::isCoordinatorOf($eventId),
    true
);

$anon           = null;
$anonSessions   = [];
// sessionID => the headcount already stored under THIS event's label in that
// session (AnonymousCheckins::storedInSession()), or simply absent from this
// array when nothing is stored there yet. Shown on the transfer form below so
// an administrator can see, before pressing the button, what a repeat press
// would overwrite -- not only in the rare case two events' names collide once
// cut to 100 characters (the case the comment on addToAttendanceSession()
// talks about), but every single time, which is the far more common and more
// useful case.
//
// WAS DEAD CODE UNTIL THE #525 ROUND-1 INDEPENDENT CHECK: this used to be a
// single scalar ($anonStoredNow = null) that nothing ever set or read again,
// sitting beside a class comment promising "the screen shows the number
// already stored under the label before anything is written" -- a promise
// the code did not keep. Fixed by actually using storedInSession() and
// showing its answer, rather than only fixing the comment, because the
// method already existed and doing the real thing is more useful than
// describing accurately why it does not happen.
$anonStoredNow  = [];
$eventFirstDay  = date('Y-m-d', $startTs);
$eventLastDay   = date('Y-m-d', $endTs);

if ($mayViewAnon === true) {
    $anon = AnonymousCheckins::summaryForEvent($mysqli, $eventId, $siteId);

    // ➕ Only an administrator may push these figures into the official
    //    attendance record, and only on purpose. The list is this
    //    organisation's own sessions, nearest dates first.
    if (App::isAdmin() === true) {
        $stmt = $mysqli->prepare(
            'SELECT s.sessionID, s.sessionDate, st.typeName '
            . 'FROM tblAttendanceSessions s '
            . 'INNER JOIN tblAttendanceServiceTypes st ON st.serviceTypeID = s.serviceTypeID '
            . 'WHERE s.siteID = ? AND s.isDeleted = 0 '
            . 'ORDER BY s.sessionDate DESC LIMIT 60'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($r = $result->fetch_assoc()) {
                $anonSessions[] = $r;
            }
            $stmt->close();
        }

        // One small lookup per listed session (capped at 60 by the query
        // above, so at most 60 extra reads on an admin-only page that is not
        // loaded often -- the same trade-off the livestream door-figures
        // panel already makes deliberately, see admin/livestream/dashboard.php).
        // A bulk query keyed on groupLabel was considered and rejected: it
        // would have meant rebuilding this event's label a second time
        // outside labelFor(), which is exactly the drift storedInSession()'s
        // own file header warns against.
        foreach ($anonSessions as $s) {
            $stored = AnonymousCheckins::storedInSession($mysqli, $siteId, $eventId, (int) $s['sessionID']);
            if ($stored !== null) {
                $anonStoredNow[(int) $s['sessionID']] = $stored;
            }
        }
    }
}

// 💬 This page had no way of showing a message until now, because nothing ever
//    redirected back to it. The "add to session" handler does, so a message set
//    there has to be shown here or it would be silently dropped and the
//    administrator would have no idea whether the button worked.
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

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

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($mayViewAnon === true && $anon !== null): ?>
        <!-- 🚪 Anonymous check-ins (#525). Built with portal-data-list, not a
             <table> — the house rule for data display. The raw table further
             down this page is older and is deliberately left alone here; it is
             recorded separately as part of #528. -->
        <div class="card mb-4 border-info">
            <div class="card-header bg-body-tertiary">
                <h2 class="h6 mb-0">
                    <i class="fa-solid fa-door-open me-2 text-info"></i>Anonymous check-ins at the door
                </h2>
            </div>
            <div class="card-body">
                <?php if ($anon['checkins'] === 0): ?>
                    <p class="mb-0 text-muted">No anonymous check-ins for this event.</p>
                <?php else: ?>
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-3">
                            <div class="card text-center h-100">
                                <div class="card-body py-2">
                                    <h3 class="mb-0"><?php echo number_format($anon['checkins']); ?></h3>
                                    <small class="text-muted">Check-ins</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card text-center h-100">
                                <div class="card-body py-2">
                                    <h3 class="mb-0"><?php echo number_format($anon['people']); ?></h3>
                                    <small class="text-muted">People claimed</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card text-center h-100">
                                <div class="card-body py-2">
                                    <h3 class="mb-0"><?php echo number_format($anon['groups']); ?></h3>
                                    <small class="text-muted">Were a group, not one person</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card text-center h-100">
                                <div class="card-body py-2">
                                    <h3 class="mb-0"><?php echo number_format($anon['senders']); ?></h3>
                                    <small class="text-muted">Probably different senders</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p class="small text-muted mb-3">
                        How they arrived:
                        <span class="badge bg-secondary">Their own phone: <?php echo (int) $anon['bySource']['self']; ?></span>
                        <span class="badge bg-secondary">Kiosk at the door: <?php echo (int) $anon['bySource']['kiosk']; ?></span>
                        <span class="badge bg-secondary">QR code: <?php echo (int) $anon['bySource']['qr']; ?></span>
                    </p>

                    <div class="portal-data-list mb-3">
                        <div class="portal-data-row portal-data-header d-none d-md-flex">
                            <div class="col-md-4">Day</div>
                            <div class="col-md-2 text-center">Check-ins</div>
                            <div class="col-md-3 text-center">People claimed</div>
                            <div class="col-md-3 text-center">Probably different senders</div>
                        </div>
                        <?php foreach ($anon['byDay'] as $d): ?>
                            <?php
                            // Compared as plain text, never turned into a
                            // timestamp and stepped forward. Stepping by 86400
                            // seconds repeats or skips a day on the two days a
                            // year the clocks change (#528).
                            $isOutside = ($d['day'] < $eventFirstDay || $d['day'] > $eventLastDay);
                            ?>
                            <div class="portal-data-row">
                                <div class="col-12 col-md-4">
                                    <span class="d-md-none fw-semibold">Day: </span>
                                    <strong><?php echo htmlspecialchars($d['day'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if ($isOutside === true): ?>
                                        <span class="badge bg-warning text-dark ms-1"
                                              title="This day is outside the event's own start and end dates">
                                            Outside the event's dates
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($d['includesLateArrivals'] === true): ?>
                                        <span class="badge bg-secondary ms-1"
                                              title="Check-ins arrived for this day after its senders figure had been worked out and stored, so somebody may be counted twice">
                                            Includes late arrivals, so less exact
                                        </span>
                                    <?php elseif ($d['sendersAreStored'] === true): ?>
                                        <span class="badge bg-light text-dark border ms-1"
                                              title="The senders figure for this day was worked out before the technical detail behind it was cleared">
                                            Worked out before the detail was cleared
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12 col-md-2 text-md-center">
                                    <span class="d-md-none fw-semibold">Check-ins: </span>
                                    <?php echo number_format($d['checkins']); ?>
                                </div>
                                <div class="col-12 col-md-3 text-md-center">
                                    <span class="d-md-none fw-semibold">People claimed: </span>
                                    <?php echo number_format($d['people']); ?>
                                </div>
                                <div class="col-12 col-md-3 text-md-center">
                                    <span class="d-md-none fw-semibold">Probably different senders: </span>
                                    <?php echo number_format($d['senders']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="alert alert-secondary small mb-0">
                        <p class="mb-1">
                            <strong>These are not part of the attendance record below, and neither figure
                            is exact.</strong> The names below are people who said they were coming, or
                            who were ticked off on the day. These are anonymous presses of a button, and
                            nothing links one to a person.
                        </p>
                        <p class="mb-0">
                            &ldquo;Probably different senders&rdquo; counts different internet
                            connections, not different people. Everybody on the building's own wifi
                            looks like one sender, so the real number of people is usually higher.
                            Somebody who comes back on another day is counted again on that day.
                            <?php if ($anon['sendersIncludeLateArrivals'] === true): ?>
                                At least one day here gained check-ins after its figure had already been
                                worked out and stored, so that figure includes late arrivals and may
                                count one visitor twice.
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php if (App::isAdmin() === true): ?>
                        <!-- ➕ Adding these figures to the official attendance
                             record is a deliberate act, never automatic. It
                             writes one row labelled with this event's name, so
                             two events pushed into the same session cannot
                             overwrite each other, and pressing it again replaces
                             that row rather than adding a second one. -->
                        <hr class="my-3">
                        <h3 class="h6">Add these to an attendance session</h3>
                        <p class="small text-muted">
                            These figures are not part of the attendance record until you put them
                            there. This writes one headcount row, labelled
                            &ldquo;<?php echo htmlspecialchars(
                                AnonymousCheckins::labelFor((string) $event['eventName']),
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?>&rdquo;, into the session you pick. Doing it again replaces that same
                            row with the current number; it never adds a second one.
                        </p>
                        <!-- ⚠️ Said BEFORE the button, not discovered afterwards.
                             The label carries the event's NAME, and once it is in
                             the attendance record it appears in the "By Headcount
                             Group" cards on the attendance reports page — which
                             today any signed-in user on this installation can
                             open, not only members of this organisation, because
                             that page asks only that somebody is signed in.
                             That is not a leak in this feature: it is an
                             administrator choosing to put the event's name into
                             the organisation's own attendance record, exactly as
                             they would by typing any other group label. But it
                             would be an unpleasant surprise with an internal
                             event, and it was found by a proof rather than by
                             thinking about it, so it is written on the screen.
                             (Narrowing who may open the reports page at all is
                             issue #529, not this work.) -->
                        <div class="alert alert-warning small py-2">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            This label includes the event's name, and it will then appear on the
                            <a href="/attendance/report">attendance reports page</a>, which every
                            signed-in user on this installation can open at the moment, not only
                            members of your organisation (a pre-existing gap, tracked as #529). If this
                            event's name is not something members should see there, do not add it to
                            a session.
                        </div>
                        <?php if (count($anonSessions) === 0): ?>
                            <p class="small text-muted mb-0">
                                There are no attendance sessions to add them to yet.
                                <a href="/attendance/record">Record an attendance session</a> first.
                            </p>
                        <?php else: ?>
                            <form method="post" action="/calendar/event/attendance/anonymous/add"
                                  class="row g-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                <div class="col-12 col-md-5">
                                    <label for="anonSessionID" class="form-label small">Attendance session</label>
                                    <!-- Each option that already holds a figure under this event's
                                         label says so, using AnonymousCheckins::storedInSession() --
                                         so an administrator sees what a repeat press replaces BEFORE
                                         picking a session, not after. A session with nothing stored
                                         yet (not in $anonStoredNow) shows no extra text. -->
                                    <select id="anonSessionID" name="sessionID" class="form-select form-select-sm" required>
                                        <?php foreach ($anonSessions as $s): ?>
                                            <?php $sid = (int) $s['sessionID']; ?>
                                            <option value="<?php echo $sid; ?>">
                                                <?php echo htmlspecialchars(
                                                    (string) $s['sessionDate'] . ' — ' . (string) $s['typeName'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>
                                                <?php if (isset($anonStoredNow[$sid]) === true): ?>
                                                    <?php echo htmlspecialchars(
                                                        ' (' . number_format($anonStoredNow[$sid])
                                                            . ' already stored under this label; picking'
                                                            . ' this replaces it)',
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label for="anonDay" class="form-label small">Which day's check-ins</label>
                                    <select id="anonDay" name="day" class="form-select form-select-sm">
                                        <option value="">All days of this event (<?php echo number_format($anon['people']); ?> people)</option>
                                        <?php foreach ($anon['byDay'] as $d): ?>
                                            <option value="<?php echo htmlspecialchars($d['day'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($d['day'], ENT_QUOTES, 'UTF-8'); ?>
                                                (<?php echo number_format($d['people']); ?> people)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                                        <i class="fa-solid fa-plus me-1"></i>Add to session
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

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
