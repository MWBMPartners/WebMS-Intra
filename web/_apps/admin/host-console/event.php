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

    <hr class="my-4">

    <h2 class="h6"><i class="fa-solid fa-bullhorn me-1 text-primary"></i>Push prompt to viewers</h2>
    <p class="small text-muted">Overlays on the viewer chat widget. Auto-expires in 5 min unless dismissed sooner. CTA URL must be empty, root-relative, or http(s) — javascript:/data:/vbscript: rejected.</p>
    <form id="hostPromptForm" class="row g-2">
        <input type="hidden" name="eventID" value="<?php echo (int) $eventId; ?>">
        <div class="col-md-3">
            <label class="form-label small">Type</label>
            <select name="promptType" class="form-select form-select-sm" required>
                <option value="announcement">📢 Announcement</option>
                <option value="decision-call">🙌 Decision call</option>
                <option value="prayer-request">🙏 Prayer request</option>
                <option value="give-now">💛 Give now</option>
            </select>
        </div>
        <div class="col-md-9">
            <label class="form-label small">Title <span class="text-danger">*</span></label>
            <input type="text" name="title" maxlength="120" required class="form-control form-control-sm" placeholder="e.g. Stand if you'd like prayer">
        </div>
        <div class="col-12">
            <label class="form-label small">Body (optional)</label>
            <input type="text" name="body" maxlength="500" class="form-control form-control-sm">
        </div>
        <div class="col-md-4">
            <label class="form-label small">CTA label (optional)</label>
            <input type="text" name="ctaLabel" maxlength="60" class="form-control form-control-sm" placeholder="e.g. Give now">
        </div>
        <div class="col-md-5">
            <label class="form-label small">CTA URL (optional)</label>
            <input type="text" name="ctaUrl" maxlength="500" class="form-control form-control-sm" placeholder="/giving or https://…">
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="fa-solid fa-paper-plane me-1"></i>Push to viewers
            </button>
        </div>
        <div class="col-12 small" id="hostPromptStatus"></div>
    </form>
</div>

<script>
(function () {
    'use strict';
    const form   = document.getElementById('hostPromptForm');
    const status = document.getElementById('hostPromptStatus');
    if (!form) { return; }
    const csrf = <?php echo json_encode(\Portal\Core\Auth::csrfToken()); ?>;

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        status.textContent = '';
        const payload = {
            csrf_token: csrf,
            eventID: Number(form.elements['eventID'].value),
            promptType: form.elements['promptType'].value,
            title: form.elements['title'].value,
            body: form.elements['body'].value,
            ctaLabel: form.elements['ctaLabel'].value,
            ctaUrl: form.elements['ctaUrl'].value,
        };
        try {
            const r = await fetch('/api/livechat/prompt-publish', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
            });
            const j = await r.json().catch(() => null);
            if (!r.ok) {
                const msg = (j && j.error && (j.error.message || j.error)) || ('HTTP ' + r.status);
                status.innerHTML = '<span class="text-danger">' + String(msg).replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'})[c]) + '</span>';
                return;
            }
            status.innerHTML = '<span class="text-success">✅ Prompt pushed (id ' + (j.data.prompt.promptID || '?') + ', expires ' + (j.data.prompt.expiresAt || '?') + ').</span>';
            form.elements['title'].value = '';
            form.elements['body'].value = '';
            form.elements['ctaLabel'].value = '';
            form.elements['ctaUrl'].value = '';
        } catch (e) {
            status.innerHTML = '<span class="text-danger">Network error.</span>';
        }
    });
})();
</script>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
