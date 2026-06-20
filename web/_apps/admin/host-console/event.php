<?php
// Path: _apps/admin/host-console/event.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Host Console event dashboard 🎙️📊 (#317 Phase 1)
 * -----------------------------------------------------------------------------
 * The per-event "during-the-service" cockpit. Read-only composition of:
 *   • tblLivestreamSessions — live viewer count + 7-day trend
 *   • tblDecisionMoments    — per-moment-type tallies
 *   • tblSalvationCards     — recent intake stream
 *
 * Auto-refreshes every 30s via <meta refresh> — deliberately NOT JS polling
 * to keep Phase 1 within the 2-day budget. JS / SSE upgrade is Phase 2.
 *
 * Auth: admin OR Auth::isCoordinatorOf(eventID).
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/317
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\HostConsole;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$eventId = (int) ($_GET['id'] ?? 0);
$siteId  = Site::id();

if (HostConsole::eventBelongsToSite($eventId, $siteId) === false) {
    http_response_code(404);
    exit('Event not found');
}
if (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 📋 Compose all reads via the helper class.
$liveNow   = HostConsole::liveViewerCount($eventId, $siteId);
$trend     = HostConsole::sessionTrend7d($eventId, $siteId);
$decisions = HostConsole::decisionTallies($eventId);
$cards     = HostConsole::recentCards($eventId, $siteId, 15);

// 📋 Event header (cheap query — the gate above already touched the row).
$stmt = $mysqli->prepare('SELECT eventName, startDateTime, locationName FROM tblEvents WHERE eventID = ?');
$stmt->bind_param('i', $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 🎨 Decision moment metadata — labels + icons + accent colours.
$momentMeta = [
    'first-decision'      => ['label' => 'First decision',      'icon' => 'fa-hand-holding-heart', 'colour' => 'success'],
    'rededication'        => ['label' => 'Rededication',        'icon' => 'fa-arrow-rotate-left', 'colour' => 'info'],
    'baptism-request'     => ['label' => 'Baptism request',     'icon' => 'fa-water',             'colour' => 'primary'],
    'membership-interest' => ['label' => 'Membership interest', 'icon' => 'fa-handshake',         'colour' => 'warning'],
    'prayer-request'      => ['label' => 'Prayer request',      'icon' => 'fa-hands-praying',     'colour' => 'secondary'],
    'other'               => ['label' => 'Other',               'icon' => 'fa-circle-question',   'colour' => 'dark'],
];

// 📊 Sparkline scale.
$maxTrend = max(1, max(array_column($trend, 'count')));

$pageTitle = 'Host Console — ' . (string) $event['eventName'];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<meta http-equiv="refresh" content="30">
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1"><i class="fa-solid fa-headset me-2 text-primary"></i><?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="text-muted small mb-0">
                <?php echo htmlspecialchars(date('l j M Y, H:i', strtotime((string) $event['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?>
                <?php if (!empty($event['locationName'])): ?>
                    &middot; <?php echo htmlspecialchars((string) $event['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
                &middot; refreshes every 30s
            </p>
        </div>
        <a href="/admin/host-console" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card text-center h-100">
                <div class="card-body">
                    <div class="display-3 fw-bold <?php echo $liveNow > 0 ? 'text-success' : 'text-muted'; ?>"><?php echo $liveNow; ?></div>
                    <p class="text-muted mb-0">
                        Watching now
                        <?php if ($liveNow > 0): ?>
                            <span class="badge bg-success ms-1">LIVE</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 mb-2 small text-muted">Sessions — last 7 days</h2>
                    <div class="d-flex align-items-end justify-content-between" style="height:80px; gap:4px;">
                        <?php foreach ($trend as $t): ?>
                            <?php $h = (int) (($t['count'] / $maxTrend) * 100); ?>
                            <div class="text-center" style="flex:1;">
                                <div title="<?php echo (int) $t['count']; ?> sessions" style="height:<?php echo max(2, $h); ?>%; background:#5e6ad2; border-radius:2px;"></div>
                                <div class="small text-muted mt-1" style="font-size:.65rem;"><?php echo htmlspecialchars(date('D', strtotime((string) $t['date'])), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <h2 class="h6">Decision moments</h2>
    <div class="row g-2 mb-3">
        <?php foreach ($momentMeta as $key => $meta):
            $d = $decisions[$key];
        ?>
            <div class="col-md-2 col-6">
                <div class="card text-center h-100">
                    <div class="card-body p-2">
                        <i class="fa-solid <?php echo htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'); ?> text-<?php echo $meta['colour']; ?>"></i>
                        <div class="h4 mb-0 mt-1"><?php echo (int) $d['count']; ?></div>
                        <div class="small text-muted" style="font-size:.7rem;"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="small text-muted mb-3">
        Increment counters at <a href="/calendar/event/decisions?eventID=<?php echo $eventId; ?>">Decision moments</a>.
    </p>

    <h2 class="h6">Latest salvation cards (<?php echo count($cards); ?>)</h2>
    <?php if (count($cards) === 0): ?>
        <p class="text-muted small">No cards received for this event yet. Public form: <a href="/decision-card?eventID=<?php echo $eventId; ?>">/decision-card?eventID=<?php echo $eventId; ?></a></p>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($cards as $c):
            $badge = ['new' => 'bg-info text-dark', 'assigned' => 'bg-primary', 'contacted' => 'bg-warning text-dark', 'complete' => 'bg-success', 'archived' => 'bg-secondary'][$c['status']] ?? 'bg-secondary';
        ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $c['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars((string) $c['decision'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="badge <?php echo $badge; ?> ms-1"><?php echo htmlspecialchars(ucfirst((string) $c['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                    <div class="small text-muted">
                        <?php if (!empty($c['email'])): ?>
                            <?php echo htmlspecialchars((string) $c['email'], ENT_QUOTES, 'UTF-8'); ?> &middot;
                        <?php endif; ?>
                        <?php echo htmlspecialchars(date('H:i', strtotime((string) $c['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($c['assigneeName'])): ?>
                            &middot; → <?php echo htmlspecialchars((string) $c['assigneeName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <p class="small text-muted mt-2">
            Manage all cards at <a href="/admin/decision-cards">/admin/decision-cards</a>.
        </p>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
