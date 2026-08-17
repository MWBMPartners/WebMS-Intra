<?php
// Path: _apps/assets/kiosk.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Public Kiosk Terminal 🖥️🔓 (#414, Phase 3 Pass 5)
 * -----------------------------------------------------------------------------
 * PUBLIC page (`assets/kiosk` seeds `isProtected = 0` — migration 161) —
 * reachable by an unauthenticated shared terminal device by design, the SAME
 * "public by design, gated some other way" shape as `assets/tag.php`
 * (migration 159). `Auth::ensureSession()` only — NEVER `Auth::
 * requireLogin()`, and this file NEVER sets `$_SESSION['user_id']` (that
 * would grant the shared device a real portal login). Kiosk identity lives
 * in its OWN, separate session keys: `kiosk_token_id`/`kiosk_site_id`/
 * `kiosk_user_id`/`kiosk_user_name`/`kiosk_expires` — see AssetRegister's
 * class header point 14 for the full section design.
 *
 * ACCESS MODEL (read before changing — every branch below can end the
 * request the SAME way):
 *   1. `?token=` (device credential) is resolved via `AssetRegister::
 *      resolveKioskTerminal()`, which re-validates the `^[a-f0-9]{32}$`
 *      shape itself and requires `isActive = 1` — an unknown token and a
 *      revoked one are indistinguishable (both null).
 *   2. `Site::forceContext()` re-points every read/audit call to the
 *      TOKEN's own site — a public request has no session-selected site.
 *      Wrapped in try/catch: a site that's gone inactive between mint and
 *      now fails the SAME way as an unknown token.
 *   3. `assets.kiosk_enabled` is re-checked for the TOKEN's OWN site (via
 *      `App::settingForSite()`, NOT the ambient `App::settings()` snapshot
 *      — that snapshot is frozen for the HOST-detected site, which is not
 *      necessarily the token's site).
 *   4. ANY of the three failing renders the EXACT SAME "kiosk unavailable"
 *      page — no distinguishing signal for an unknown token vs a revoked
 *      one vs a real-but-disabled terminal vs anything else (no oracle to
 *      probe which 32-hex tokens are real).
 *   5. Idle expiry — re-checked on every load, unconditionally, regardless
 *      of `assets.kiosk_auto_checkout` (that setting only controls whether
 *      the CLIENT-SIDE countdown below fires early; the server-side clock
 *      is always authoritative).
 *
 * NEVER renders any confidential asset, or any asset data beyond the
 * currently-identified kiosk user's own scanned/owned items — see
 * `AssetRegister::kioskUserActiveLoans()`'s own doc.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/414
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Asset;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Site;

// 🔓 Public — no Auth::requireLogin(). Session started purely so a CSRF
// token + the kiosk_* identity keys can live somewhere — see file header.
Auth::ensureSession();

/**
 * Render the UNIFORM "kiosk unavailable" page and stop. The SAME markup,
 * copy, and HTTP status regardless of WHICH gate failed (unknown token /
 * revoked token / inactive site / disabled feature flag) — see file header
 * access-model note. Local closure so every rejection branch below bails
 * out identically, mirroring `found-save.php`'s own `$bounce` convention
 * adapted for a public, non-redirecting terminal page.
 */
$renderUnavailable = static function (): never {
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
        <h1 class="h4 mb-2">This kiosk terminal isn't available</h1>
        <p class="text-secondary mb-0">Please ask a site administrator for help.</p>
    </div>
</body>
</html><?php
    exit();
};

// -----------------------------------------------------------------------------
// 1️⃣ Resolve the device credential — uniform failure on any problem.
// -----------------------------------------------------------------------------
$token    = (string) ($_GET['token'] ?? '');
$terminal = AssetRegister::resolveKioskTerminal($token);
if ($terminal === null) {
    $renderUnavailable();
}

$siteId  = (int) $terminal['siteID'];
$tokenId = (int) $terminal['tokenID'];

// -----------------------------------------------------------------------------
// 2️⃣ Re-point site context to the TOKEN's own site — a public request has
// no session-selected site of its own. Never allowed to throw past here.
// -----------------------------------------------------------------------------
try {
    Site::forceContext($siteId);
} catch (\Throwable $e) {
    $renderUnavailable();
}

// -----------------------------------------------------------------------------
// 3️⃣ Feature gate — re-checked for THIS token's own site via
// settingForSite() (NOT the host-detected App::settings() snapshot).
// -----------------------------------------------------------------------------
$kioskEnabled = (string) (App::settingForSite('assets.kiosk_enabled', $siteId) ?? 'false') === 'true';
if ($kioskEnabled === false) {
    $renderUnavailable();
}

// -----------------------------------------------------------------------------
// 4️⃣ Idle expiry — always server-enforced, regardless of the auto-checkout
// display setting below. Also cleared on a terminal switch (a different
// token resolving mid-session) — never carry an identity across devices.
// -----------------------------------------------------------------------------
$idleExpired = isset($_SESSION['kiosk_user_id']) === true
    && ((int) ($_SESSION['kiosk_expires'] ?? 0) < time()
        || (int) ($_SESSION['kiosk_token_id'] ?? 0) !== $tokenId);
if ($idleExpired === true) {
    unset($_SESSION['kiosk_user_id'], $_SESSION['kiosk_user_name'], $_SESSION['kiosk_expires']);
}
$_SESSION['kiosk_token_id'] = $tokenId;
$_SESSION['kiosk_site_id']  = $siteId;

$identified = isset($_SESSION['kiosk_user_id']) === true && (int) $_SESSION['kiosk_user_id'] > 0;

$idleTimeoutSeconds = (int) (App::settingForSite('assets.kiosk_idle_timeout_seconds', $siteId) ?? '90');
if ($idleTimeoutSeconds <= 0) {
    $idleTimeoutSeconds = 90;
}
$autoCheckout = (string) (App::settingForSite('assets.kiosk_auto_checkout', $siteId) ?? 'true') === 'true';

$myLoans = [];
if ($identified === true) {
    $myLoans = AssetRegister::kioskUserActiveLoans((int) $_SESSION['kiosk_user_id'], $siteId);
}

$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? 'info');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf      = Auth::csrfToken();
$nonce     = App::cspNonce();
$tokenSafe = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
$remainingSeconds = $identified === true ? max(0, (int) ($_SESSION['kiosk_expires'] ?? 0) - time()) : 0;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kiosk check-in/out</title>
    <!-- 🤖 Never index a kiosk terminal page. -->
    <meta name="robots" content="noindex, nofollow, noai, noimageai">
    <?php echo Asset::bootstrapCss(); ?>
    <?php echo Asset::fontAwesomeCss(); ?>
    <style nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
        body { margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, sans-serif; background: #f8f9fa; }
        .kiosk-wrap { max-width: 36rem; margin: 0 auto; padding: 2rem 1rem; }
        .kiosk-header { text-align: center; margin-bottom: 1.5rem; }
        .kiosk-countdown { font-variant-numeric: tabular-nums; }
        .loan-row { display: flex; align-items: center; gap: .75rem; padding: .75rem 0; border-bottom: 1px solid #e9ecef; }
        .loan-row:last-child { border-bottom: none; }
    </style>
</head>
<body>
<div class="kiosk-wrap">
    <div class="kiosk-header">
        <i class="fa-solid fa-tablet-screen-button fa-2x text-primary mb-2"></i>
        <h1 class="h4 mb-0">Self check-in / check-out</h1>
    </div>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($identified === false): ?>
        <!-- 🙋 Identify form — posts to kiosk-action.php action=identify. -->
        <div class="card">
            <div class="card-body">
                <h2 class="h5 mb-3">Who are you?</h2>
                <form method="post" action="/assets/kiosk-action" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="token" value="<?php echo $tokenSafe; ?>">
                    <input type="hidden" name="action" value="identify">
                    <div class="mb-3">
                        <label for="identifier" class="form-label">Email or username</label>
                        <input type="text" class="form-control form-control-lg" id="identifier" name="identifier"
                               autocomplete="username" autocapitalize="off" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label for="pin" class="form-label">PIN</label>
                        <input type="password" inputmode="numeric" pattern="[0-9]*" class="form-control form-control-lg"
                               id="pin" name="pin" maxlength="6" autocomplete="off" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100">Continue</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- 👤 Identified — greeting + checkout scan + my current items. -->
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h2 class="h5 mb-1">
                            Hi, <?php echo htmlspecialchars((string) $_SESSION['kiosk_user_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </h2>
                        <?php if ($autoCheckout === true): ?>
                            <p class="text-muted small mb-0">
                                Session ends in <span id="kiosk-countdown" class="kiosk-countdown"><?php echo $remainingSeconds; ?>s</span>
                            </p>
                        <?php endif; ?>
                    </div>
                    <form method="post" action="/assets/kiosk-action" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="token" value="<?php echo $tokenSafe; ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Done — not me</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h3 class="h6 mb-2"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i>Check out an item</h3>
                <form method="post" action="/assets/kiosk-action" class="d-flex gap-2" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="token" value="<?php echo $tokenSafe; ?>">
                    <input type="hidden" name="action" value="checkout">
                    <input type="text" class="form-control form-control-lg" name="code" placeholder="Scan or type item code"
                           autocomplete="off" autofocus required>
                    <button type="submit" class="btn btn-success btn-lg">Check out</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h3 class="h6 mb-2"><i class="fa-solid fa-arrow-right-from-bracket me-1"></i>Items you have out</h3>
                <?php if (count($myLoans) === 0): ?>
                    <p class="text-muted small mb-0">Nothing checked out right now.</p>
                <?php else: ?>
                    <?php foreach ($myLoans as $loan): ?>
                        <div class="loan-row">
                            <div class="flex-grow-1">
                                <?php echo htmlspecialchars((string) $loan['name'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($loan['assetTagCode'])): ?>
                                    <small class="text-muted d-block">
                                        <?php echo htmlspecialchars((string) $loan['assetTagCode'], ENT_QUOTES, 'UTF-8'); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <form method="post" action="/assets/kiosk-action" class="d-flex align-items-center gap-2" novalidate>
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="token" value="<?php echo $tokenSafe; ?>">
                                <input type="hidden" name="action" value="checkin">
                                <input type="hidden" name="assetID" value="<?php echo (int) $loan['assetID']; ?>">
                                <select name="conditionIn" class="form-select form-select-sm" style="width: auto;">
                                    <?php foreach (AssetRegister::CONDITION_STATES as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $c === 'good' ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(ucfirst($c), ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-outline-primary btn-sm">Check in</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($autoCheckout === true): ?>
            <script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
            (function () {
                // ⏱️ Client-side display countdown ONLY — the server-side
                // idle-expiry check above is always authoritative regardless
                // of whether this script runs at all. When it hits zero,
                // simply reload this same terminal URL: the server-side
                // check on that next load clears the identity if the
                // session has genuinely expired by then.
                var remaining = <?php echo json_encode($remainingSeconds); ?>;
                var kioskUrl  = <?php echo json_encode('/assets/kiosk?token=' . $token); ?>;
                var el = document.getElementById('kiosk-countdown');
                function tick() {
                    if (remaining <= 0) {
                        window.location.href = kioskUrl;
                        return;
                    }
                    if (el) { el.textContent = remaining + 's'; }
                    remaining -= 1;
                    window.setTimeout(tick, 1000);
                }
                tick();
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
