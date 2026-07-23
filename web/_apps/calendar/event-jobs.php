<?php
// Path: _apps/calendar/event-jobs.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Volunteer Job Board 🧑‍💼 (#344)
 * -----------------------------------------------------------------------------
 * Lists jobs with capacity indicators (e.g. 2/3 = 2 of 3 needed). Add /
 * remove volunteers via forms. Mirrors #343 crew builder shape.
 *
 * @package   Portal\Calendar
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/344
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

$jobs = [];
$stmt = $mysqli->prepare(
    'SELECT j.jobID, j.name, j.description, j.capacityNeeded, '
    . '       a.assignmentID, COALESCE(u.fullName, a.externalName) AS volunteerName '
    . 'FROM tblEventJobs j '
    . 'LEFT JOIN tblEventJobAssignments a ON a.jobID = j.jobID '
    . 'LEFT JOIN tblUsers u ON u.userID = a.userID '
    . 'WHERE j.eventID = ? '
    . 'ORDER BY j.sortOrder, j.jobID, a.assignedAt'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $jid = (int) $r['jobID'];
    if (isset($jobs[$jid]) === false) {
        $jobs[$jid] = [
            'jobID' => $jid, 'name' => (string) $r['name'],
            'description' => (string) ($r['description'] ?? ''),
            'capacityNeeded' => (int) $r['capacityNeeded'], 'volunteers' => [],
        ];
    }
    if ($r['assignmentID'] !== null) {
        $jobs[$jid]['volunteers'][] = ['assignmentID' => (int) $r['assignmentID'], 'name' => (string) $r['volunteerName']];
    }
}
$stmt->close();

$pageTitle = 'Jobs — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container-fluid py-3">
    <h1 class="h4 mb-2"><i class="fa-solid fa-clipboard-user me-2 text-primary"></i>Jobs — <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small">Forms-only v1. Drag-and-drop in v1.1.</p>

    <form method="post" action="/calendar/event/jobs/auto-assign" class="d-inline-block mb-3"
          data-confirm="Auto-assign unassigned crew leaders to under-capacity jobs?">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
        <button type="submit" class="btn btn-sm btn-success"><i class="fa-solid fa-shuffle me-1"></i>Auto-assign volunteers</button>
    </form>

    <details class="mb-4">
        <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Add a job</summary>
        <form method="post" action="/calendar/event/jobs/save" class="row g-2 mt-2 align-items-end p-2 bg-light rounded">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
            <input type="hidden" name="action" value="addJob">
            <div class="col-md-4"><label class="form-label small">Job name</label><input type="text" name="name" required maxlength="120" class="form-control form-control-sm" placeholder="e.g. A.V. Tech"></div>
            <div class="col-md-2"><label class="form-label small">Need</label><input type="number" name="capacityNeeded" value="1" min="1" max="50" class="form-control form-control-sm"></div>
            <div class="col-md-4"><label class="form-label small">Description (optional)</label><input type="text" name="description" maxlength="255" class="form-control form-control-sm"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm">Create job</button></div>
        </form>
    </details>

    <?php if (count($jobs) === 0): ?>
        <div class="alert alert-info">No jobs yet. Add one above to get started.</div>
    <?php else: ?>
        <div class="row g-3">
        <?php foreach ($jobs as $j):
            $assigned = count($j['volunteers']);
            $need     = max(1, $j['capacityNeeded']);
            $fillPct  = min(100, round($assigned / $need * 100));
            $badgeClass = $assigned >= $need ? 'bg-success' : ($assigned > 0 ? 'bg-warning text-dark' : 'bg-secondary');
        ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong><?php echo htmlspecialchars($j['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span class="badge <?php echo $badgeClass; ?>"><?php echo $assigned; ?>/<?php echo $need; ?></span>
                    </div>
                    <div class="card-body p-2">
                        <?php if ($j['description'] !== ''): ?>
                            <p class="small text-muted"><?php echo htmlspecialchars($j['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>

                        <?php if ($assigned === 0): ?>
                            <p class="small text-muted">No volunteers assigned yet.</p>
                        <?php else: ?>
                            <?php foreach ($j['volunteers'] as $v): ?>
                                <div class="d-flex align-items-center small mb-1">
                                    <span class="flex-grow-1"><i class="fa-solid fa-user me-1 text-secondary"></i><?php echo htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <form method="post" action="/calendar/event/jobs/save" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                        <input type="hidden" name="action" value="unassign">
                                        <input type="hidden" name="assignmentID" value="<?php echo $v['assignmentID']; ?>">
                                        <button class="btn btn-link btn-sm text-danger p-0" title="Unassign"><i class="fa-solid fa-xmark"></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <form method="post" action="/calendar/event/jobs/save" class="mt-2 d-flex gap-1">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="jobID" value="<?php echo $j['jobID']; ?>">
                            <input type="text" name="externalName" maxlength="120" placeholder="Assign volunteer" class="form-control form-control-sm" required>
                            <button class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i></button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
