<?php
// Path: _apps/calendar/event-broadcast.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event broadcast / bulk-email composer ✉️ (#350)
 * -----------------------------------------------------------------------------
 * Coordinator/admin picks a segment (all-rsvps / all-volunteers /
 * crew:<id> / job:<id>) + writes a subject + body. Submitting POSTs to
 * /calendar/event/broadcast/send which uses the existing Mailer.
 *
 * Composes with #341 coordinator role, #343 crews, #344 jobs.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/350
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
    http_response_code(403); exit('Forbidden');
}
$siteId = Site::id();

$event = null;
$stmt = $mysqli->prepare('SELECT eventID, eventName FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($event === null) { http_response_code(404); exit('Event not found'); }

// 📋 Segment counts so the dropdown shows recipient count next to each option.
$counts = ['all-rsvps' => 0, 'all-volunteers' => 0];
$stmt = $mysqli->prepare('SELECT COUNT(DISTINCT userID) AS c FROM tblEventRSVPs WHERE eventID = ? AND response = "going" AND status = "confirmed"');
$stmt->bind_param('i', $eventId);
$stmt->execute();
$counts['all-rsvps'] = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $mysqli->prepare(
    'SELECT COUNT(DISTINCT m.userID) AS c '
    . 'FROM tblEventCrewMembers m '
    . 'JOIN tblEventCrews c ON c.crewID = m.crewID '
    . 'WHERE c.eventID = ? AND m.role = "leader" AND m.userID IS NOT NULL'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$counts['all-volunteers'] = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$crews = [];
$stmt = $mysqli->prepare(
    'SELECT c.crewID, c.name, COUNT(m.membershipID) AS memberCount '
    . 'FROM tblEventCrews c '
    . 'LEFT JOIN tblEventCrewMembers m ON m.crewID = c.crewID AND m.userID IS NOT NULL '
    . 'WHERE c.eventID = ? GROUP BY c.crewID ORDER BY c.sortOrder'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $crews[] = $r; }
$stmt->close();

$jobs = [];
$stmt = $mysqli->prepare(
    'SELECT j.jobID, j.name, COUNT(a.assignmentID) AS memberCount '
    . 'FROM tblEventJobs j '
    . 'LEFT JOIN tblEventJobAssignments a ON a.jobID = j.jobID AND a.userID IS NOT NULL '
    . 'WHERE j.eventID = ? GROUP BY j.jobID ORDER BY j.sortOrder'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $jobs[] = $r; }
$stmt->close();

$pageTitle = 'Email — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-3" style="max-width:760px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-envelopes-bulk me-2 text-primary"></i>Email — <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small">Send a bulk update to a segment of your event's people.</p>

    <form method="post" action="/calendar/event/broadcast/send">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">

        <div class="mb-3">
            <label for="segment" class="form-label">Send to</label>
            <select id="segment" name="segment" class="form-select" required>
                <option value="all-rsvps">All confirmed RSVPs (<?php echo $counts['all-rsvps']; ?>)</option>
                <option value="all-volunteers">All crew leaders + job-assigned volunteers (<?php echo $counts['all-volunteers']; ?>)</option>
                <?php foreach ($crews as $c): ?>
                    <option value="crew:<?php echo (int) $c['crewID']; ?>">Crew: <?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int) $c['memberCount']; ?>)</option>
                <?php endforeach; ?>
                <?php foreach ($jobs as $j): ?>
                    <option value="job:<?php echo (int) $j['jobID']; ?>">Job: <?php echo htmlspecialchars((string) $j['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int) $j['memberCount']; ?>)</option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Only portal-registered users with an email address will receive the message. External (name-only) members are skipped.</div>
        </div>

        <div class="mb-3">
            <label for="subject" class="form-label">Subject</label>
            <input type="text" id="subject" name="subject" required maxlength="255" class="form-control" placeholder="e.g. VBS Day 1 reminder">
        </div>

        <div class="mb-3">
            <label for="body" class="form-label">Message</label>
            <textarea id="body" name="body" required rows="10" class="form-control" placeholder="Hi everyone! ..."></textarea>
            <div class="form-text">Plain text. Recipients see your name as the sender.</div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i>Send</button>
        <a href="/calendar/event?slug=<?php echo (int) $eventId; ?>" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
