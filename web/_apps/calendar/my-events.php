<?php
// Path: _apps/calendar/my-events.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — My Events (coordinator dashboard) 📅👤 (#341)
 * -----------------------------------------------------------------------------
 * Lists events the logged-in user coordinates (per tblEventCoordinators).
 * Mirrors VBS Pro's "My Events" pattern — one row per event with Manage /
 * View / Briefing actions. Admins see ALL their assigned events (admins
 * can also see everything via the regular admin tools).
 *
 * @package   Portal\Calendar
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

$pageTitle   = 'My Events';
$pageSection = 'calendar';
$breadcrumbs = ['Dashboard' => '/', 'Calendar' => '/calendar', 'My Events' => ''];

$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

$rows = [];
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, e.endDateTime, '
    . '       e.status, e.locationName, ec.grantedAt, '
    . '       (SELECT COUNT(*) FROM tblEventRSVPs WHERE eventID = e.eventID AND status = "confirmed") AS rsvpCount '
    . 'FROM tblEventCoordinators ec '
    . 'JOIN tblEvents e ON e.eventID = ec.eventID '
    . 'WHERE ec.userID = ? AND ec.revokedAt IS NULL '
    . '  AND e.siteID = ? AND e.isDeleted = 0 '
    . 'ORDER BY e.startDateTime ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $userId, $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <h1 class="h3 mb-3"><i class="fa-solid fa-calendar-check me-2 text-primary"></i>My Events</h1>
    <p class="text-muted">
        Events you coordinate. Each one you can edit, manage RSVPs for, mark attendance on,
        and assign crews to — without site-wide admin rights.
    </p>

    <?php if (count($rows) === 0): ?>
        <div class="alert alert-info">
            You don't coordinate any events right now. An admin can assign you via
            <strong>Admin → Calendar → Assign coordinator</strong>.
        </div>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($rows as $r):
            $statusBadge = ['draft' => 'bg-secondary', 'published' => 'bg-success', 'cancelled' => 'bg-danger', 'postponed' => 'bg-warning text-dark'];
            $when = date('j M Y, H:i', strtotime((string) $r['startDateTime']));
        ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <h2 class="h6 mb-1">
                        <a href="/calendar/event?slug=<?php echo htmlspecialchars((string) $r['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">
                            <?php echo htmlspecialchars((string) $r['eventName'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </h2>
                    <div class="text-muted small mb-2">
                        <i class="fa-solid fa-calendar me-1"></i>
                        <?php echo htmlspecialchars($when, ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($r['locationName'])): ?>
                            &middot; <i class="fa-solid fa-location-dot ms-1 me-1"></i>
                            <?php echo htmlspecialchars((string) $r['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                        &middot; <i class="fa-solid fa-user-check ms-1 me-1"></i>
                        <?php echo (int) $r['rsvpCount']; ?> confirmed RSVPs
                    </div>
                </div>
                <div class="portal-data-row-aside text-end">
                    <span class="badge <?php echo $statusBadge[$r['status']] ?? 'bg-secondary'; ?> mb-2"><?php echo htmlspecialchars(ucfirst((string) $r['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                    <div>
                        <a href="/calendar/manage?edit=<?php echo (int) $r['eventID']; ?>" class="btn btn-outline-primary btn-sm" title="Manage">
                            <i class="fa-solid fa-pen"></i> Manage
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
