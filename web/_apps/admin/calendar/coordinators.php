<?php
// Path: _apps/admin/calendar/coordinators.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Event Coordinators Picker 👥📅 (#341)
 * -----------------------------------------------------------------------------
 * Per-event picker so admins can assign / revoke coordinator role. List of
 * currently-active coordinators + "Add coordinator" user-search.
 *
 * Accessed via /admin/calendar/coordinators?eventID=N.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/341
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$eventId = (int) ($_GET['eventID'] ?? 0);
$siteId  = Site::id();

$event = null;
if ($eventId > 0) {
    $stmt = $mysqli->prepare('SELECT eventID, eventName, eventSlug FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $eventId, $siteId);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

if ($event === null) {
    http_response_code(404);
    exit('Event not found');
}

$coords = [];
$stmt = $mysqli->prepare(
    'SELECT ec.coordinatorID, ec.userID, ec.grantedAt, u.fullName, u.email '
    . 'FROM tblEventCoordinators ec '
    . 'JOIN tblUsers u ON u.userID = ec.userID '
    . 'WHERE ec.eventID = ? AND ec.revokedAt IS NULL '
    . 'ORDER BY ec.grantedAt ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $coords[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Coordinators — ' . (string) $event['eventName'];
$pageSection = 'admin';
$csrf        = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4" style="max-width:720px;">
    <h1 class="h3 mb-2"><i class="fa-solid fa-users-gear me-2 text-primary"></i>Event Coordinators</h1>
    <p class="text-muted">
        <strong><?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></strong>
        &middot; <a href="/calendar/event?slug=<?php echo htmlspecialchars((string) $event['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">view public page</a>
    </p>

    <h2 class="h5 mt-4 mb-2">Active coordinators</h2>
    <?php if (count($coords) === 0): ?>
        <div class="alert alert-info">No coordinators assigned yet.</div>
    <?php else: ?>
        <div class="portal-data-list mb-4">
        <?php foreach ($coords as $c): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <div class="fw-semibold"><?php echo htmlspecialchars((string) $c['fullName'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="text-muted small">
                        <?php echo htmlspecialchars((string) $c['email'], ENT_QUOTES, 'UTF-8'); ?>
                        &middot; assigned <?php echo htmlspecialchars(date('j M Y', strtotime((string) $c['grantedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
                <div class="portal-data-row-aside">
                    <form method="post" action="/admin/calendar/coordinators/save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                        <input type="hidden" name="coordinatorID" value="<?php echo (int) $c['coordinatorID']; ?>">
                        <input type="hidden" name="action" value="revoke">
                        <button class="btn btn-sm btn-outline-danger" title="Revoke">
                            <i class="fa-solid fa-xmark"></i> Revoke
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="h5 mt-4 mb-2">Add coordinator</h2>
    <form method="post" action="/admin/calendar/coordinators/save">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
        <input type="hidden" name="action" value="grant">
        <div class="mb-3">
            <label for="userEmail" class="form-label">User email</label>
            <input type="email" id="userEmail" name="userEmail" required maxlength="255" class="form-control" placeholder="user@example.com">
            <div class="form-text">The user must already have a portal account.</div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-user-plus me-1"></i> Grant coordinator</button>
        <a href="/calendar/manage?edit=<?php echo $eventId; ?>" class="btn btn-outline-secondary">Back to event</a>
    </form>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
