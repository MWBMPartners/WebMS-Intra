<?php
// Path: _apps/calendar/event-crews-auto-build.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Auto-build crews from approved registrations 🎲 (#349)
 * -----------------------------------------------------------------------------
 * POST. For the given event:
 *   1. Collect approved tblEventRegistrations rows that are NOT already in
 *      a tblEventCrewMembers row (matched by registration fullName +
 *      crewID linkage — externalName equality).
 *   2. Group by grade (NULL/empty grade falls into a "no-grade" bucket).
 *   3. Distribute each grade-group round-robin across the existing crews,
 *      starting with the crew with the FEWEST current participants → keeps
 *      crew sizes balanced AND keeps each grade balanced across crews.
 *   4. INSERTs a tblEventCrewMembers row per assignment, role='participant'.
 *
 * Requires the event to already have crews defined (#343). Will not
 * create or delete crews — only fills them.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/349
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /calendar', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$eventId = (int) ($_POST['eventID'] ?? 0);
if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}
$siteId  = Site::id();

$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Event not found'); }

$redirect = '/calendar/event/crews?eventID=' . $eventId;

// 🪣 Load crews — keyed by crewID, value = current participant count.
$crews = [];
$stmt = $mysqli->prepare(
    'SELECT c.crewID, COUNT(m.membershipID) AS memberCount '
    . 'FROM tblEventCrews c '
    . 'LEFT JOIN tblEventCrewMembers m ON m.crewID = c.crewID AND m.role = "participant" '
    . 'WHERE c.eventID = ? GROUP BY c.crewID ORDER BY c.sortOrder'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $crews[(int) $r['crewID']] = (int) $r['memberCount']; }
$stmt->close();

if (count($crews) === 0) {
    $_SESSION['flash_msg']  = 'Create at least one crew before auto-building.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . $redirect, true, 302); exit();
}

// 📋 Collect unassigned approved registrations, grouped by grade.
$buckets = [];
$stmt = $mysqli->prepare(
    'SELECT r.fullName, COALESCE(NULLIF(r.grade, ""), "_") AS gradeKey '
    . 'FROM tblEventRegistrations r '
    . 'WHERE r.eventID = ? AND r.status = "approved" '
    . '  AND NOT EXISTS ('
    . '    SELECT 1 FROM tblEventCrewMembers m '
    . '    JOIN tblEventCrews c ON c.crewID = m.crewID '
    . '    WHERE c.eventID = r.eventID AND m.externalName = r.fullName '
    . '  ) '
    . 'ORDER BY r.fullName'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $buckets[(string) $r['gradeKey']][] = (string) $r['fullName'];
}
$stmt->close();

// 🎲 Distribute. For each grade-bucket, walk participants round-robin
//    into crews sorted by current size ASC. After each assignment we
//    re-key arsort so the smallest crew is always next.
$insertStmt = $mysqli->prepare(
    'INSERT INTO tblEventCrewMembers (crewID, externalName, role) VALUES (?, ?, "participant")'
);

$assignedTotal = 0;
foreach ($buckets as $names) {
    foreach ($names as $name) {
        // 🧮 Pick the crew with the LOWEST member count.
        asort($crews, SORT_NUMERIC);
        $targetCrew = array_key_first($crews);
        if ($targetCrew === null) { break 2; }
        $insertStmt->bind_param('is', $targetCrew, $name);
        $insertStmt->execute();
        $crews[$targetCrew]++;
        $assignedTotal++;
    }
}
$insertStmt->close();

Logger::activity('EventCrewsAutoBuilt', 'Event #' . $eventId . ' distributed ' . $assignedTotal . ' participants across ' . count($crews) . ' crews');

$_SESSION['flash_msg']  = 'Auto-built: distributed ' . $assignedTotal . ' participant' . ($assignedTotal === 1 ? '' : 's') . ' across ' . count($crews) . ' crews.';
$_SESSION['flash_type'] = $assignedTotal > 0 ? 'success' : 'info';
header('Location: ' . $redirect, true, 302);
exit();
