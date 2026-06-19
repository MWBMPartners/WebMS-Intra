<?php
// _apps/admin/livestream/dashboard.php (#318)
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }

$siteId = Site::id();

// 👀 Active right now (lastPingAt within 90s)
$liveCount = 0;
$stmt = $mysqli->prepare('SELECT COUNT(*) c FROM tblLivestreamSessions WHERE siteID = ? AND leftAt IS NULL AND lastPingAt >= DATE_SUB(NOW(), INTERVAL 90 SECOND)');
$stmt->bind_param('i', $siteId);
$stmt->execute();
$liveCount = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// 📊 24h totals
$dayUnique = 0;
$stmt = $mysqli->prepare('SELECT COUNT(*) c FROM tblLivestreamSessions WHERE siteID = ? AND joinedAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
$stmt->bind_param('i', $siteId);
$stmt->execute();
$dayUnique = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// 🔝 Per-event last 7 days
$perEvent = [];
$result = $mysqli->query(
    'SELECT e.eventID, e.eventName, COUNT(s.sessionID) AS sessions, '
    . '       MIN(s.joinedAt) AS firstJoin, MAX(s.lastPingAt) AS lastPing '
    . 'FROM tblLivestreamSessions s JOIN tblEvents e ON e.eventID = s.eventID '
    . 'WHERE s.joinedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY) '
    . 'GROUP BY e.eventID ORDER BY sessions DESC LIMIT 20'
);
while ($r = $result->fetch_assoc()) { $perEvent[] = $r; }

$pageTitle = 'Livestream Analytics';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <h1 class="h4 mb-3"><i class="fa-solid fa-video me-2 text-primary"></i>Livestream Analytics</h1>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card text-center">
                <div class="card-body">
                    <div class="display-4 fw-bold text-success"><?php echo $liveCount; ?></div>
                    <p class="text-muted mb-0">Watching now <span class="badge bg-success">LIVE</span></p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card text-center">
                <div class="card-body">
                    <div class="display-4 fw-bold"><?php echo $dayUnique; ?></div>
                    <p class="text-muted mb-0">Sessions in the last 24 hours</p>
                </div>
            </div>
        </div>
    </div>

    <h2 class="h6">Per-event sessions (last 7 days)</h2>
    <?php if (count($perEvent) === 0): ?>
        <p class="text-muted small">No livestream activity in the last week.</p>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($perEvent as $r): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $r['eventName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <div class="small text-muted">
                        First: <?php echo htmlspecialchars(date('j M H:i', strtotime((string) $r['firstJoin'])), ENT_QUOTES, 'UTF-8'); ?>
                        &middot; Last ping: <?php echo htmlspecialchars(date('j M H:i', strtotime((string) $r['lastPing'])), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
                <div class="portal-data-row-aside">
                    <span class="badge bg-primary"><?php echo (int) $r['sessions']; ?> sessions</span>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <hr class="my-4">
    <h2 class="h6">Embed pinger snippet</h2>
    <p class="small text-muted">Drop this into your livestream embed page. Generate a per-session token client-side, post it to /api/livestream/ping every 30 s while playing, and on unload.</p>
    <pre class="bg-light p-2 small" style="white-space: pre-wrap;"><code>const token = crypto.randomUUID().replace(/-/g, '');
const eventID = 42;
function ping(leaving) {
  fetch('/api/livestream/ping', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, eventID, leaving }),
    keepalive: true
  });
}
setInterval(() =&gt; ping(false), 30000);
addEventListener('beforeunload', () =&gt; ping(true));
ping(false);</code></pre>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
