<?php
// _apps/admin/livestream/dashboard.php (#318)
declare(strict_types=1);

use Portal\Core\AnonymousCheckins;
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
//
//    WHAT WAS WRONG BEFORE (#527). This list was not limited to one
//    organisation at all. The two figures above it were — both carry
//    `siteID = ?` — but this one had no organisation condition anywhere, and
//    it was not even a prepared statement. So an administrator of one
//    organisation opening this page saw the NAMES of another organisation's
//    livestreamed events and how many people had watched them, mixed in with
//    their own, with nothing on screen to say which was which. Nobody had
//    noticed, because the numbers looked perfectly plausible.
//
//    Fixed by adding `e.siteID = ?` and preparing the statement the same way
//    as its two neighbours above.
//
//    `e.isDeleted` is deliberately NOT added to the condition. A soft-deleted
//    event of an administrator's OWN organisation appearing in their own
//    dashboard is not a leak, and quietly changing what administrators see
//    today, for a reason nobody asked for, is not part of this fix.
//    👁️ EventVisibility (#514 D5, fix round 1: checker finding 2b). `api/livestream/ping.php`
//    accepts a session for ANY event number from a signed-out visitor (a separate, pre-existing
//    gap not fixed inside P3 — see the follow-up issue this fix round raises), so a session row
//    can already point at a HIDDEN imported event. The two totals just above this query are left
//    alone — they still count every session, imported or not — but this per-event breakdown must
//    not print an imported event's name to an administrator the visibility rule may refuse that
//    event to (an old-flag-only administrator counts as a plain member under the rule).
$perEvent = [];
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.eventName, COUNT(s.sessionID) AS sessions, '
    . '       MIN(s.joinedAt) AS firstJoin, MAX(s.lastPingAt) AS lastPing '
    . 'FROM tblLivestreamSessions s JOIN tblEvents e ON e.eventID = s.eventID AND e.externalFeedID IS NULL '
    . 'WHERE s.joinedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND e.siteID = ? '
    . 'GROUP BY e.eventID ORDER BY sessions DESC LIMIT 20'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) { $perEvent[] = $r; }
    $stmt->close();
}

// 🚪 Anonymous check-ins per listed event (#525).
//
//    The organisation's visibility setting is NOT consulted on this page, and
//    that is deliberate rather than an oversight: the whole page already
//    refuses anybody who is not an administrator (line 11), and administrators
//    see these figures under every one of the three choices. Nobody should
//    later "fix" the omission by adding a check here.
//
//    One small query per listed event, and the list is capped at 20, so at most
//    20 of them. A single grouped query would be faster; it was considered and
//    left out on purpose, because it would mean a second copy of the
//    senders-and-stored-figures arithmetic living outside the one class that
//    owns it, purely to save a few milliseconds on an administrator-only page.
foreach ($perEvent as $i => $r) {
    $summary = AnonymousCheckins::summaryForEvent($mysqli, (int) $r['eventID'], $siteId);
    $perEvent[$i]['anonCheckins'] = $summary['checkins'];
    $perEvent[$i]['anonPeople']   = $summary['people'];
}

$pageTitle = 'Livestream Analytics';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <!-- 🔗 The channels and schedule page is a separate page. It used to sit at
         this same address until migration 133 gave the address to this
         analytics view, after which nothing linked to it at all — including
         the button that tells subscribers "we are live now". Migration 186
         gave it an address of its own; this is the way in. -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="fa-solid fa-video me-2 text-primary"></i>Livestream Analytics</h1>
        <a href="/admin/livestream/channels" class="btn btn-sm btn-outline-primary">
            <i class="fa-solid fa-sliders me-1"></i> Channels &amp; schedule
        </a>
    </div>

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
                    <?php if ((int) ($r['anonCheckins'] ?? 0) > 0): ?>
                        <!-- 🚪 People who checked in at the door without signing
                             in (#525). Shown beside the watching figures so the
                             two are easy to compare, but they are NOT two views
                             of one thing and neither should be added to the
                             other. -->
                        <span class="badge bg-info text-dark"
                              title="Anonymous check-ins at the door: <?php echo (int) $r['anonCheckins']; ?> press<?php echo (int) $r['anonCheckins'] === 1 ? '' : 'es'; ?> of the button, claiming <?php echo (int) ($r['anonPeople'] ?? 0); ?> people">
                            <?php echo (int) ($r['anonPeople'] ?? 0); ?> at the door
                        </span>
                    <?php endif; ?>
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
