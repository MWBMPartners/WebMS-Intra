<?php
// Path: _apps/worship/display.php
/**
 * -----------------------------------------------------------------------------
 * Worship — Projector Display 🎶📺 (#308 Phase 2)
 * -----------------------------------------------------------------------------
 * Public, token-gated. The PC connected to the projector loads this URL
 * once and the page polls /api/worship/state every 500ms to mirror the
 * operator's current slide.
 *
 * Standalone HTML — no portal chrome. Fullscreen-friendly. Black/blank
 * states handled with CSS.
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Site;

$token = trim((string) ($_GET['t'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(400); exit('Invalid display token.');
}

$siteId = Site::id();
$stmt = $mysqli->prepare(
    'SELECT planID, name FROM tblServicePlans WHERE displayToken = ? AND siteID = ? AND isActive = 1'
);
$stmt->bind_param('si', $token, $siteId);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($plan === null) { http_response_code(404); exit('Display not found.'); }

$brandPrimary = (string) (Site::branding()['primaryColor'] ?? '#5e6ad2');
$siteName     = (string) Site::name();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Display — <?php echo htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        :root { --brand: <?php echo htmlspecialchars($brandPrimary, ENT_QUOTES, 'UTF-8'); ?>; }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: #000; color: #fff; font-family: Georgia, serif; }
        #stage {
            position: fixed; inset: 0; display: flex; align-items: center; justify-content: center;
            padding: 4vh 6vw; text-align: center; white-space: pre-wrap; line-height: 1.4;
            font-size: clamp(2rem, 6vw, 7rem); font-weight: 500;
            background: #111; transition: background-color .25s, color .25s;
        }
        #stage.blank { background: linear-gradient(135deg, var(--brand) 0%, #000 100%); color: rgba(255,255,255,.4); font-size: clamp(2rem, 4vw, 5rem); }
        #stage.black { background: #000; color: #000; }
        #title { position: fixed; top: 2vh; left: 50%; transform: translateX(-50%); font-size: 1.1rem; opacity: .35; pointer-events: none; }
        #title.hidden { display: none; }
        #footer { position: fixed; bottom: 2vh; left: 50%; transform: translateX(-50%); font-size: .9rem; opacity: .35; pointer-events: none; }
    </style>
</head>
<body>
    <div id="title"><?php echo htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8'); ?></div>
    <div id="stage">(waiting…)</div>
    <div id="footer"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></div>

<script>
(function () {
    'use strict';
    const token = <?php echo json_encode($token); ?>;
    const stage = document.getElementById('stage');
    const titleEl = document.getElementById('title');
    let lastBody = ''; let lastBlank = null; let lastBlack = null;

    async function poll() {
        try {
            const r = await fetch('/api/worship/state?t=' + encodeURIComponent(token), { cache: 'no-store' });
            const s = await r.json();
            if (!s || s.ok === false) return;

            stage.classList.toggle('blank', !!s.isBlank);
            stage.classList.toggle('black', !!s.isBlack);
            titleEl.classList.toggle('hidden', !!s.isBlank || !!s.isBlack);

            const body = s.isBlack ? '' : (s.isBlank ? '' : (s.body || ''));
            if (body !== lastBody || s.isBlank !== lastBlank || s.isBlack !== lastBlack) {
                stage.textContent = body || (s.isBlank ? '' : '(no slide)');
                lastBody = body; lastBlank = s.isBlank; lastBlack = s.isBlack;
            }
        } catch (e) { /* keep going */ }
    }

    setInterval(poll, 500);
    poll();

    // Click anywhere to request fullscreen (browsers gate this on user gesture).
    document.body.addEventListener('click', () => {
        if (!document.fullscreenElement && document.body.requestFullscreen) {
            document.body.requestFullscreen().catch(() => { /* user can press F11 instead */ });
        }
    });
})();
</script>
</body>
</html>
