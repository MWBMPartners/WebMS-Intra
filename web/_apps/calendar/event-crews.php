<?php
// Path: _apps/calendar/event-crews.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Crew / Group Builder 🎨 (#343)
 * -----------------------------------------------------------------------------
 * VBS-style crew builder. Lists existing crews with their members + leaders,
 * plus forms to: add a crew, add a member to a crew, remove a member.
 * Forms-only for v1; SortableJS drag-and-drop is the v1.1 polish layer.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/343
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

// 📋 Crews + members (one query, group server-side).
$crews = [];
$stmt = $mysqli->prepare(
    'SELECT c.crewID, c.name, c.color, c.gradesAccepted, '
    . '       m.membershipID, m.role, COALESCE(u.fullName, m.externalName) AS memberName '
    . 'FROM tblEventCrews c '
    . 'LEFT JOIN tblEventCrewMembers m ON m.crewID = c.crewID '
    . 'LEFT JOIN tblUsers u ON u.userID = m.userID '
    . 'WHERE c.eventID = ? '
    . 'ORDER BY c.sortOrder, c.crewID, m.role DESC, m.sortOrder'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $cid = (int) $r['crewID'];
    if (isset($crews[$cid]) === false) {
        $crews[$cid] = [
            'crewID' => $cid, 'name' => (string) $r['name'], 'color' => (string) $r['color'],
            'gradesAccepted' => (string) ($r['gradesAccepted'] ?? ''),
            'leaders' => [], 'participants' => [],
        ];
    }
    if ($r['membershipID'] !== null) {
        $entry = ['membershipID' => (int) $r['membershipID'], 'name' => (string) $r['memberName']];
        if ($r['role'] === 'leader') {
            $crews[$cid]['leaders'][] = $entry;
        } else {
            $crews[$cid]['participants'][] = $entry;
        }
    }
}
$stmt->close();

$pageTitle = 'Crews — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container-fluid py-3">
    <h1 class="h4 mb-2"><i class="fa-solid fa-people-group me-2 text-primary"></i>Crews — <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small">Forms-only v1. Drag-and-drop is a v1.1 polish layer.</p>

    <form method="post" action="/calendar/event/crews/auto-build" class="d-inline-block mb-3"
          onsubmit="return confirm('Distribute all approved registrations across these crews, balancing by grade?');">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
        <button type="submit" class="btn btn-sm btn-success"><i class="fa-solid fa-shuffle me-1"></i>Auto-build from registrations</button>
    </form>

    <details class="mb-4">
        <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Add a crew</summary>
        <form method="post" action="/calendar/event/crews/save" class="row g-2 mt-2 align-items-end p-2 bg-light rounded">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
            <input type="hidden" name="action" value="addCrew">
            <div class="col-md-4"><label class="form-label small">Name</label><input type="text" name="name" required maxlength="80" class="form-control form-control-sm"></div>
            <div class="col-md-2"><label class="form-label small">Colour</label><input type="color" name="color" value="#5e6ad2" class="form-control form-control-sm form-control-color"></div>
            <div class="col-md-3"><label class="form-label small">Grades accepted</label><input type="text" name="gradesAccepted" maxlength="100" placeholder="P,K,1,2,3" class="form-control form-control-sm"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm">Create crew</button></div>
        </form>
    </details>

    <?php if (count($crews) === 0): ?>
        <div class="alert alert-info">No crews yet. Add one above to get started.</div>
    <?php else: ?>
        <div class="row g-3">
        <?php foreach ($crews as $c): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-header text-white" style="background-color: <?php echo htmlspecialchars($c['color'], ENT_QUOTES, 'UTF-8'); ?>;">
                        <strong><?php echo htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span class="badge bg-light text-dark ms-1"><?php echo count($c['participants']); ?></span>
                        <?php if ($c['gradesAccepted'] !== ''): ?>
                            <small class="float-end">Grades: <?php echo htmlspecialchars($c['gradesAccepted'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-2">
                        <h3 class="h6 small text-muted mt-0">Leaders</h3>
                        <?php if (count($c['leaders']) === 0): ?>
                            <p class="small text-muted">No leaders yet.</p>
                        <?php else: ?>
                            <?php foreach ($c['leaders'] as $l): ?>
                                <div class="d-flex align-items-center small mb-1">
                                    <span class="flex-grow-1"><i class="fa-solid fa-user-tie me-1 text-secondary"></i><?php echo htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <form method="post" action="/calendar/event/crews/save" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                        <input type="hidden" name="action" value="removeMember">
                                        <input type="hidden" name="membershipID" value="<?php echo $l['membershipID']; ?>">
                                        <button class="btn btn-link btn-sm text-danger p-0" title="Remove"><i class="fa-solid fa-xmark"></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <h3 class="h6 small text-muted mt-2">Participants</h3>
                        <?php if (count($c['participants']) === 0): ?>
                            <p class="small text-muted">No participants yet.</p>
                        <?php else: ?>
                            <?php foreach ($c['participants'] as $p): ?>
                                <div class="d-flex align-items-center small mb-1">
                                    <span class="flex-grow-1"><i class="fa-solid fa-user me-1 text-secondary"></i><?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <form method="post" action="/calendar/event/crews/save" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                        <input type="hidden" name="action" value="removeMember">
                                        <input type="hidden" name="membershipID" value="<?php echo $p['membershipID']; ?>">
                                        <button class="btn btn-link btn-sm text-danger p-0" title="Remove"><i class="fa-solid fa-xmark"></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <form method="post" action="/calendar/event/crews/save" class="mt-2 d-flex gap-1">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                            <input type="hidden" name="action" value="addMember">
                            <input type="hidden" name="crewID" value="<?php echo $c['crewID']; ?>">
                            <input type="text" name="externalName" maxlength="120" placeholder="Add by name" class="form-control form-control-sm" required>
                            <select name="role" class="form-select form-select-sm" style="max-width:110px;"><option value="participant">Participant</option><option value="leader">Leader</option></select>
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
