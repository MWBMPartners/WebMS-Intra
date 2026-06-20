<?php
// Path: _apps/admin/host-console/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Host Console event picker (#317 Phase 1)
 * -----------------------------------------------------------------------------
 * Landing page when an admin / coordinator visits /admin/host-console.
 * Lists every event that:
 *   • Belongs to the active site
 *   • Has at least one livestream session OR is scheduled to start today
 *   • Is not deleted
 * Click an event row to open the cockpit (/admin/host-console/event?id=N).
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/317
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();

// 🛡️ Admin OR ANY active event coordinator can land here. The per-event
//    dashboard re-checks isCoordinatorOf for the SPECIFIC event.
$userId = (int) ($_SESSION['user_id'] ?? 0);
$isCoordOfSomething = false;
if (App::isAdmin() === false) {
    $stmt = $mysqli->prepare(
        'SELECT 1 FROM tblEventCoordinators WHERE userID = ? AND revokedAt IS NULL LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $isCoordOfSomething = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if ($isCoordOfSomething === false) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// 📋 Events with livestream activity OR starting today.
$events = [];
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, '
    . '       (SELECT COUNT(*) FROM tblLivestreamSessions s '
    . '         WHERE s.eventID = e.eventID AND s.leftAt IS NULL '
    . '           AND s.lastPingAt >= DATE_SUB(NOW(), INTERVAL 90 SECOND)) AS liveNow, '
    . '       (SELECT MAX(s.lastPingAt) FROM tblLivestreamSessions s '
    . '         WHERE s.eventID = e.eventID) AS lastActivity '
    . 'FROM tblEvents e '
    . 'WHERE e.siteID = ? AND e.isDeleted = 0 AND e.status = "published" '
    . '  AND ('
    . '    EXISTS (SELECT 1 FROM tblLivestreamSessions s WHERE s.eventID = e.eventID) '
    . '    OR DATE(e.startDateTime) = CURDATE() '
    . '  ) '
    . 'ORDER BY liveNow DESC, e.startDateTime DESC LIMIT 30'
);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $events[] = $r;
}
$stmt->close();

$pageTitle = 'Host Console';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-headset me-2 text-primary"></i>Host Console</h1>
    <p class="text-muted small">Pick an event to open the host cockpit — viewer count, decision tallies, and salvation card intake in one view.</p>

    <?php if (count($events) === 0): ?>
        <div class="alert alert-info small">
            No events with livestream activity or scheduled to start today.
            <a href="/admin/livestream">Livestream analytics</a> shows all sessions.
        </div>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($events as $e): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <a href="/admin/host-console/event?id=<?php echo (int) $e['eventID']; ?>" class="text-decoration-none fw-semibold h6 mb-1">
                        <?php echo htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <?php if ((int) $e['liveNow'] > 0): ?>
                        <span class="badge bg-success ms-1"><i class="fa-solid fa-circle me-1" style="font-size:.5em; vertical-align:middle;"></i>LIVE — <?php echo (int) $e['liveNow']; ?></span>
                    <?php endif; ?>
                    <div class="small text-muted">
                        <?php echo htmlspecialchars(date('l j M Y, H:i', strtotime((string) $e['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($e['lastActivity'])): ?>
                            &middot; last ping: <?php echo htmlspecialchars(date('j M H:i', strtotime((string) $e['lastActivity'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="portal-data-row-aside">
                    <a href="/admin/host-console/event?id=<?php echo (int) $e['eventID']; ?>" class="btn btn-sm btn-outline-primary">
                        Open <i class="fa-solid fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="text-muted small mt-4">
        Phase 1 is read-only. Phase 2 will add host-side push prompts to viewers
        (decision-call overlays, prayer requests, give-now CTAs).
    </p>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
