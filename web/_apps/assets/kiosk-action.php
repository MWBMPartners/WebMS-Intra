<?php
// Path: _apps/assets/kiosk-action.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Public Kiosk Action Handler 🖥️🔓 (#414, Phase 3 Pass 1 stub)
 * -----------------------------------------------------------------------------
 * PUBLIC handler (`assets/kiosk-action` seeds `isProtected = 0` — migration
 * 161) for the terminal-side check-in/out ACTION `assets/kiosk.php`'s page
 * will eventually POST to (PIN entry, scan, confirm). NOT gated behind
 * `Auth::requireLogin()` — same rationale as `assets/kiosk.php` (a kiosk
 * terminal has no portal login of its own). See that file's own header for
 * the full "public by design, gated some other way" + uniform-response/
 * no-oracle design notes — this file follows them identically.
 *
 * THIS PASS SHIPS NO KIOSK LOGIC WHATSOEVER — no token lookup, no PIN
 * check/hash comparison, no asset mutation, nothing session/site-specific.
 * Since `assets.kiosk_enabled` seeds `'false'` (migration 161), this route
 * currently renders ONLY the SAME plain, non-revealing "kiosk mode is not
 * enabled" placeholder `assets/kiosk.php` shows — regardless of method,
 * body, or any parameter a caller might supply, so this stub can never be
 * used as an oracle once the real #414 gate lands. The real token+PIN
 * check-in/out mutation logic lands in a later Phase 3 pass.
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

// 🔓 Public — no Auth::requireLogin(). See file header + assets/kiosk.php's
// own header for the shared rationale.
Auth::ensureSession();

// 🚪 Feature gate — SAME check + SAME uniform placeholder as
// assets/kiosk.php (see that file's header for the no-oracle rationale);
// deliberately never branches on $_POST/$_GET in this pass.
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
