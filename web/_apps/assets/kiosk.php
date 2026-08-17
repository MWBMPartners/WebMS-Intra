<?php
// Path: _apps/assets/kiosk.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Public Kiosk Terminal 🖥️🔓 (#414, Phase 3 Pass 1 stub)
 * -----------------------------------------------------------------------------
 * PUBLIC page (`assets/kiosk` seeds `isProtected = 0` — migration 161) —
 * reachable by an unauthenticated shared terminal device by design, the SAME
 * "public by design, gated some other way" shape as `assets/tag.php`
 * (migration 159). NOT gated behind `Auth::requireLogin()` — a kiosk
 * terminal has no portal login of its own; the real credential is the
 * per-device token (`tblAssetKioskTokens.token`) the #414 pass will read
 * from the query string, PLUS the per-user PIN (`tblAssetKioskPins`) a
 * person enters on the terminal itself.
 *
 * THIS PASS SHIPS NO KIOSK LOGIC WHATSOEVER — no token lookup, no PIN
 * check, no asset data, nothing session/site-specific. Since
 * `assets.kiosk_enabled` seeds `'false'` (migration 161), this route
 * currently renders ONLY a plain, non-revealing "kiosk mode is not
 * enabled" placeholder page — the exact same message REGARDLESS of any
 * `?token=` value a caller might supply, so this stub can never be used
 * as an oracle to learn whether a given token exists once the real #414
 * gate lands (mirrors `assets/tag.php`'s own "uniform response, no
 * oracle" access-model convention). The real token+PIN authentication
 * flow, idle-timeout auto-checkout, and check-in/out UI all land in a
 * later Phase 3 pass — see that pass's own header for the full design.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/414
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Asset;
use Portal\Core\Auth;

// 🔓 Public — no Auth::requireLogin(). Session started purely for CSRF/
// flash-message plumbing symmetry with every other public Asset Tracker
// page (found-save.php, tag.php) — this pass reads/writes neither, but
// starting the session here costs nothing and keeps the shape consistent
// for when the #414 pass adds them.
Auth::ensureSession();

// 🚪 Feature gate — 'true' only once an admin has explicitly opted in
// (migration 161 seeds 'false'). See file header: this pass shows the
// SAME placeholder either way, never branching on any caller-supplied
// value, so nothing here can act as an oracle.
$kioskEnabled = (string) (App::settings('assets.kiosk_enabled') ?? 'false') === 'true';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kiosk</title>
    <!-- 🤖 Never index a kiosk terminal page. -->
    <meta name="robots" content="noindex, nofollow, noai, noimageai">
    <?php echo Asset::bootstrapCss(); ?>
    <?php echo Asset::fontAwesomeCss(); ?>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: system-ui, -apple-system, sans-serif; background: #f8f9fa; text-align: center; }
        .kiosk-card { max-width: 28rem; padding: 2.5rem 2rem; }
    </style>
</head>
<body>
    <div class="kiosk-card">
        <i class="fa-solid fa-tablet-screen-button fa-3x text-secondary mb-3"></i>
        <h1 class="h4 mb-2">Kiosk mode is not enabled</h1>
        <p class="text-secondary mb-0">
            <?php echo $kioskEnabled === true
                ? 'Kiosk check-in/out is not available on this terminal yet.'
                : 'Ask a site administrator to enable Asset Tracker kiosk mode.'; ?>
        </p>
    </div>
</body>
</html>
