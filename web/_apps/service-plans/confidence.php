<?php
// Path: _apps/service-plans/confidence.php
/**
 * -----------------------------------------------------------------------------
 * Service Plans — Confidence Monitor (Speaker View) ⏱️ (#300)
 * -----------------------------------------------------------------------------
 * Full-screen, dark-themed companion to /service-plans/live. Designed for a
 * tablet in the speaker's eyeline. v1 ships clock-only; operator-to-confidence
 * message channel deferred to v2.
 *
 * @package   Portal\ServicePlans
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/300
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$planId = (int) ($_GET['id'] ?? 0);
$siteId = Site::id();

$plan = null;
$stmt = $mysqli->prepare('SELECT planID, title, startedAt, closedAt FROM tblServicePlan WHERE planID = ? AND siteID = ?');
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

$startedAt = $plan['startedAt'] !== null ? (int) strtotime((string) $plan['startedAt']) : 0;
$closedAt  = $plan['closedAt']  !== null ? (int) strtotime((string) $plan['closedAt'])  : 0;
$title     = (string) $plan['title'];
$nonce     = htmlspecialchars(App::cspNonce(), ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confidence Monitor — <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <style nonce="<?php echo $nonce; ?>">
        html, body { height: 100%; margin: 0; background: #000; color: #fff;
                     font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        body { display: flex; flex-direction: column; align-items: center; justify-content: center;
               padding: 24px; }
        .title { font-size: 1.25rem; opacity: 0.55; text-align: center; }
        .elapsed { font-size: 22vw; font-weight: 700; line-height: 1; letter-spacing: -0.02em;
                   font-variant-numeric: tabular-nums; margin: 0.3em 0; }
        .clock { font-size: 1.8rem; opacity: 0.65; font-variant-numeric: tabular-nums; }
        .badge-live { display: inline-block; background: #c0392b; color: #fff; padding: 4px 10px;
                      border-radius: 4px; font-size: 0.8rem; letter-spacing: 0.1em; margin-bottom: 0.5em;
                      animation: pulse 2s ease-in-out infinite; }
        .badge-closed { display: inline-block; background: #444; color: #ccc; padding: 4px 10px;
                        border-radius: 4px; font-size: 0.8rem; letter-spacing: 0.1em; margin-bottom: 0.5em; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.45; } }
        @media (prefers-reduced-motion: reduce) { .badge-live { animation: none; } }
    </style>
</head>
<body>
    <div class="title">
        <?php if ($startedAt > 0 && $closedAt === 0): ?>
            <span class="badge-live">● LIVE</span>
        <?php elseif ($closedAt > 0): ?>
            <span class="badge-closed">CLOSED</span>
        <?php else: ?>
            <span class="badge-closed">PRE-SERVICE</span>
        <?php endif; ?>
        <br>
        <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
    </div>

    <div class="elapsed" id="elapsed" data-started-at="<?php echo $startedAt; ?>" data-closed-at="<?php echo $closedAt; ?>">
        <?php echo $startedAt > 0 ? '00:00:00' : '00:00:00'; ?>
    </div>

    <div class="clock" id="clock">--:--</div>

    <script nonce="<?php echo $nonce; ?>">
    (function () {
        const elapsedEl = document.getElementById('elapsed');
        const clockEl   = document.getElementById('clock');
        const started   = parseInt(elapsedEl.dataset.startedAt, 10);
        const closed    = parseInt(elapsedEl.dataset.closedAt, 10);
        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        function tick() {
            const now = new Date();
            clockEl.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes());
            if (started > 0) {
                const cutoff = closed > 0 ? closed : Math.floor(now.getTime() / 1000);
                const elapsed = Math.max(0, cutoff - started);
                const h = Math.floor(elapsed / 3600);
                const m = Math.floor((elapsed % 3600) / 60);
                const s = elapsed % 60;
                elapsedEl.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
            }
        }
        tick();
        setInterval(tick, 1000);
    })();
    </script>
</body>
</html>
