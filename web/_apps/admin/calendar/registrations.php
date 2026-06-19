<?php
// Path: _apps/admin/calendar/registrations.php
/**
 * -----------------------------------------------------------------------------
 * Admin/Coordinator — Event registrations list + moderation (#347)
 * -----------------------------------------------------------------------------
 * Filters: status (pending/approved/rejected/waitlisted), event.
 * Per-row Approve / Reject / Waitlist buttons.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/347
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$eventId = (int) ($_GET['eventID'] ?? 0);
$status  = (string) ($_GET['status'] ?? 'pending');
if (in_array($status, ['pending', 'approved', 'rejected', 'waitlisted', 'all'], true) === false) {
    $status = 'pending';
}

if (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false) {
    http_response_code(403); exit('Forbidden');
}

$siteId = Site::id();
$event = null;
if ($eventId > 0) {
    $stmt = $mysqli->prepare('SELECT eventID, eventName FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
    $stmt->bind_param('ii', $eventId, $siteId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}
if ($event === null) { http_response_code(404); exit('Event not found'); }

$regs = [];
if ($status === 'all') {
    $stmt = $mysqli->prepare(
        'SELECT registrationID, fullName, grade, allergies, parentEmail, status, createdAt '
        . 'FROM tblEventRegistrations WHERE eventID = ? ORDER BY createdAt DESC LIMIT 500'
    );
    $stmt->bind_param('i', $eventId);
} else {
    $stmt = $mysqli->prepare(
        'SELECT registrationID, fullName, grade, allergies, parentEmail, status, createdAt '
        . 'FROM tblEventRegistrations WHERE eventID = ? AND status = ? ORDER BY createdAt DESC LIMIT 500'
    );
    $stmt->bind_param('is', $eventId, $status);
}
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $regs[] = $r; }
$stmt->close();

$pageTitle = 'Registrations — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container-fluid py-3">
    <h1 class="h4 mb-2"><i class="fa-solid fa-clipboard-list me-2 text-primary"></i>Registrations — <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>

    <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Filter by status">
        <?php foreach (['pending', 'approved', 'rejected', 'waitlisted', 'all'] as $s): ?>
            <a href="?eventID=<?php echo $eventId; ?>&status=<?php echo $s; ?>" class="btn btn-outline-secondary <?php echo $s === $status ? 'active' : ''; ?>">
                <?php echo htmlspecialchars(ucfirst($s), ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (count($regs) === 0): ?>
        <div class="alert alert-info">No registrations in this view.</div>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($regs as $r):
            $statusClass = ['pending' => 'bg-warning text-dark', 'approved' => 'bg-success', 'rejected' => 'bg-danger', 'waitlisted' => 'bg-info text-dark'][$r['status']] ?? 'bg-secondary';
        ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $r['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if (!empty($r['grade'])): ?>
                        <span class="badge bg-secondary ms-1">Grade <?php echo htmlspecialchars((string) $r['grade'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <span class="badge <?php echo $statusClass; ?> ms-1"><?php echo htmlspecialchars(ucfirst((string) $r['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                    <div class="small text-muted">
                        <?php if (!empty($r['parentEmail'])): ?>
                            <?php echo htmlspecialchars((string) $r['parentEmail'], ENT_QUOTES, 'UTF-8'); ?> &middot;
                        <?php endif; ?>
                        <?php echo htmlspecialchars(date('j M Y', strtotime((string) $r['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($r['allergies'])): ?>
                            <br><span class="text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Allergies: <?php echo htmlspecialchars(mb_substr((string) $r['allergies'], 0, 80), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="portal-data-row-aside">
                    <form method="post" action="/admin/calendar/registrations/act" class="d-flex gap-1">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="registrationID" value="<?php echo (int) $r['registrationID']; ?>">
                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-outline-success" title="Approve"><i class="fa-solid fa-check"></i></button>
                        <button type="submit" name="action" value="waitlist" class="btn btn-sm btn-outline-info" title="Waitlist"><i class="fa-solid fa-clock"></i></button>
                        <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger" title="Reject"><i class="fa-solid fa-xmark"></i></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
