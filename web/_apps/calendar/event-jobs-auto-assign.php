<?php
// Path: _apps/calendar/event-jobs-auto-assign.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Auto-assign volunteers to under-capacity jobs 🎲 (#349)
 * -----------------------------------------------------------------------------
 * POST. For the given event:
 *   1. Collect every crew leader (#343 tblEventCrewMembers role='leader')
 *      who is NOT currently assigned to a job (#344 tblEventJobAssignments).
 *   2. Walk under-capacity jobs (capacityNeeded > current count) ordered by
 *      largest deficit first.
 *   3. Fill each job up to capacity from the leader pool.
 *
 * Will not create or delete jobs. Won't over-fill (deficit-aware).
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

$redirect = '/calendar/event/jobs?eventID=' . $eventId;

// 📋 Pool: every crew leader for this event who has no current assignment.
$pool = [];
$stmt = $mysqli->prepare(
    'SELECT COALESCE(u.fullName, m.externalName) AS leaderName '
    . 'FROM tblEventCrewMembers m '
    . 'JOIN tblEventCrews c ON c.crewID = m.crewID '
    . 'LEFT JOIN tblUsers u ON u.userID = m.userID '
    . 'WHERE c.eventID = ? AND m.role = "leader" '
    . '  AND NOT EXISTS ('
    . '    SELECT 1 FROM tblEventJobAssignments a '
    . '    JOIN tblEventJobs j ON j.jobID = a.jobID '
    . '    WHERE j.eventID = c.eventID '
    . '      AND (a.userID = m.userID OR (m.userID IS NULL AND a.externalName = m.externalName))'
    . '  ) '
    . 'ORDER BY leaderName'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $pool[] = (string) $r['leaderName']; }
$stmt->close();

if (count($pool) === 0) {
    $_SESSION['flash_msg']  = 'No unassigned crew leaders available to assign.';
    $_SESSION['flash_type'] = 'info';
    header('Location: ' . $redirect, true, 302); exit();
}

// 🪣 Under-capacity jobs, deficit DESC.
$jobs = [];
$stmt = $mysqli->prepare(
    'SELECT j.jobID, j.capacityNeeded, COUNT(a.assignmentID) AS filled, '
    . '       j.capacityNeeded - COUNT(a.assignmentID) AS deficit '
    . 'FROM tblEventJobs j '
    . 'LEFT JOIN tblEventJobAssignments a ON a.jobID = j.jobID '
    . 'WHERE j.eventID = ? '
    . 'GROUP BY j.jobID HAVING deficit > 0 '
    . 'ORDER BY deficit DESC, j.sortOrder'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $jobs[] = ['jobID' => (int) $r['jobID'], 'deficit' => (int) $r['deficit']];
}
$stmt->close();

if (count($jobs) === 0) {
    $_SESSION['flash_msg']  = 'All jobs are fully assigned.';
    $_SESSION['flash_type'] = 'info';
    header('Location: ' . $redirect, true, 302); exit();
}

// 🎲 Fill round-robin, deepest-deficit-first.
$insertStmt = $mysqli->prepare('INSERT INTO tblEventJobAssignments (jobID, externalName) VALUES (?, ?)');
$poolIdx = 0;
$assigned = 0;
foreach ($jobs as &$j) {
    while ($j['deficit'] > 0 && $poolIdx < count($pool)) {
        $name = $pool[$poolIdx++];
        $insertStmt->bind_param('is', $j['jobID'], $name);
        $insertStmt->execute();
        $j['deficit']--;
        $assigned++;
    }
    if ($poolIdx >= count($pool)) { break; }
}
$insertStmt->close();

Logger::activity('EventJobsAutoAssigned', 'Event #' . $eventId . ' assigned ' . $assigned . ' volunteers across ' . count($jobs) . ' jobs');

$_SESSION['flash_msg']  = 'Auto-assigned ' . $assigned . ' volunteer' . ($assigned === 1 ? '' : 's') . ' across ' . count($jobs) . ' jobs.';
$_SESSION['flash_type'] = $assigned > 0 ? 'success' : 'info';
header('Location: ' . $redirect, true, 302);
exit();
