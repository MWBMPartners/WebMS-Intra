<?php
// Path: _apps/service-plans/live.php
/**
 * -----------------------------------------------------------------------------
 * Service Plans — Live Runtime (Operator View) ⏱️ (#300)
 * -----------------------------------------------------------------------------
 * Tech booth's live view of an in-progress service. Shows the master clock
 * + per-item progress + plan items in order, plus an operator →
 * confidence-monitor message panel (#300 v2): send a short cue ("wrap up —
 * 2 mins") that appears on /service-plans/confidence within one 4s poll,
 * or clear the currently-active message.
 *
 * @package   Portal\ServicePlans
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/300
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

$planId = (int) ($_GET['id'] ?? 0);
$siteId = Site::id();

$plan = null;
$items = [];

$stmt = $mysqli->prepare('SELECT * FROM tblServicePlan WHERE planID = ? AND siteID = ?');
if ($stmt !== false) {
    $stmt->bind_param('ii', $planId, $siteId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

if ($plan === null) {
    http_response_code(404);
    exit('Plan not found');
}

$stmt = $mysqli->prepare(
    'SELECT itemID, sectionType, position, title, durationMin, notes '
    . 'FROM tblServicePlanItem WHERE planID = ? ORDER BY position ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $items[] = $r;
    }
    $stmt->close();
}

// 💬 Confidence-monitor message channel (#300 v2) — current active message.
$activeMsg = null;
$stmt = $mysqli->prepare(
    'SELECT messageID, body, createdAt FROM tblServicePlanMessages '
    . 'WHERE planID = ? AND siteID = ? AND isCleared = 0 '
    . 'ORDER BY messageID DESC LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $planId, $siteId);
    $stmt->execute();
    $activeMsg = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$startedAt = $plan['startedAt'] !== null ? (int) strtotime((string) $plan['startedAt']) : 0;
$closedAt  = $plan['closedAt']  !== null ? (int) strtotime((string) $plan['closedAt'])  : 0;
$isLive    = ($startedAt > 0 && $closedAt === 0);
$csrf      = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

$pageTitle   = 'Live: ' . (string) $plan['title'];
$pageSection = 'service-plans';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h4 mb-1"><i class="fa-solid fa-tower-broadcast me-2 text-danger"></i><?php echo htmlspecialchars((string) $plan['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <div class="text-muted small">
                <?php echo htmlspecialchars(date('l j F Y', strtotime((string) $plan['serviceDate'])), ENT_QUOTES, 'UTF-8'); ?>
                &middot; <a href="/service-plans/edit?id=<?php echo $planId; ?>" class="text-decoration-none">Edit plan</a>
                &middot; <a href="/service-plans/confidence?id=<?php echo $planId; ?>" target="_blank" class="text-decoration-none">Open confidence monitor <i class="fa-solid fa-arrow-up-right-from-square ms-1"></i></a>
            </div>
        </div>

        <form method="post" action="/service-plans/live-toggle">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="planID" value="<?php echo $planId; ?>">
            <?php if ($isLive === false && $startedAt === 0): ?>
                <input type="hidden" name="action" value="start">
                <button class="btn btn-success btn-lg"><i class="fa-solid fa-play me-1"></i> Start service</button>
            <?php elseif ($isLive === true): ?>
                <input type="hidden" name="action" value="close">
                <button class="btn btn-outline-danger btn-lg"><i class="fa-solid fa-stop me-1"></i> Close service</button>
            <?php else: ?>
                <span class="badge bg-secondary fs-6">Closed</span>
            <?php endif; ?>
        </form>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body text-center py-4">
            <div class="text-uppercase small text-muted mb-2">Elapsed</div>
            <div id="liveTimer" class="display-3 font-monospace fw-bold" data-started-at="<?php echo $startedAt; ?>" data-closed-at="<?php echo $closedAt; ?>">
                <?php echo $startedAt > 0 ? '00:00:00' : '—'; ?>
            </div>
            <div class="text-muted small mt-2">
                <?php if ($startedAt > 0): ?>
                    Started <?php echo htmlspecialchars(date('H:i', $startedAt), ENT_QUOTES, 'UTF-8'); ?>
                <?php else: ?>
                    Press "Start service" above to begin the live timer.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    // 💬 Confidence-monitor message channel (#300 v2) — operator panel.
    // Plain form POST + 303 (no AJAX), matching this app's only existing
    // submit idiom (the start/close toggle above).
    ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 text-muted mb-3"><i class="fa-solid fa-comment-dots me-1"></i> Confidence-monitor message</h2>

            <?php if ($activeMsg !== null): ?>
                <div class="d-flex justify-content-between align-items-center bg-body-secondary rounded p-2 mb-3">
                    <div class="fw-semibold">
                        <?php echo htmlspecialchars((string) $activeMsg['body'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <form method="post" action="/service-plans/live-message" class="ms-2">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="planID" value="<?php echo $planId; ?>">
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="fa-solid fa-xmark me-1"></i> Clear from monitor
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="text-muted small mb-3">No message currently showing on the confidence monitor.</div>
            <?php endif; ?>

            <?php if ($closedAt === 0): ?>
                <form method="post" action="/service-plans/live-message" class="input-group">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="planID" value="<?php echo $planId; ?>">
                    <input type="hidden" name="action" value="send">
                    <input type="text" name="body" maxlength="255" required class="form-control"
                           placeholder="e.g. wrap up — 2 mins">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-paper-plane me-1"></i> Send to monitor
                    </button>
                </form>
            <?php else: ?>
                <div class="text-muted small">Plan is closed — sending new messages is disabled.</div>
            <?php endif; ?>
        </div>
    </div>

    <h2 class="h6 text-muted mb-2">Order of service</h2>
    <ol class="list-group list-group-numbered">
    <?php foreach ($items as $item):
        $duration = $item['durationMin'] !== null ? (int) $item['durationMin'] . ' min' : '—';
        $sectionLabel = ucwords(str_replace('_', ' ', (string) $item['sectionType']));
    ?>
        <li class="list-group-item d-flex justify-content-between align-items-start">
            <div class="ms-2 me-auto">
                <div class="fw-semibold"><?php echo htmlspecialchars((string) ($item['title'] ?? $sectionLabel), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="small text-muted">
                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($item['notes'] !== null && $item['notes'] !== ''): ?>
                        &middot; <?php echo htmlspecialchars(mb_substr((string) $item['notes'], 0, 100), ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </div>
            </div>
            <span class="badge bg-secondary rounded-pill"><?php echo htmlspecialchars($duration, ENT_QUOTES, 'UTF-8'); ?></span>
        </li>
    <?php endforeach; ?>
    </ol>
</div>

<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
(function () {
    const el = document.getElementById('liveTimer');
    if (el === null) return;
    const started = parseInt(el.dataset.startedAt, 10);
    const closed  = parseInt(el.dataset.closedAt, 10);
    if (started <= 0) return;
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function tick() {
        const now = closed > 0 ? closed : Math.floor(Date.now() / 1000);
        const elapsed = Math.max(0, now - started);
        const h = Math.floor(elapsed / 3600);
        const m = Math.floor((elapsed % 3600) / 60);
        const s = elapsed % 60;
        el.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
    }
    tick();
    if (closed === 0) setInterval(tick, 1000);
})();
</script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
