<?php
// Path: _apps/assets/kiosk-action.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Public Kiosk Action Handler 🖥️🔓 (#414, Phase 3 Pass 5)
 * -----------------------------------------------------------------------------
 * PUBLIC POST handler (`assets/kiosk-action` seeds `isProtected = 0` —
 * migration 161) for `assets/kiosk.php`'s forms: `identify` (PIN entry),
 * `checkout` (scan an item), `checkin` (return an item), `logout` ("not
 * me"). `Auth::ensureSession()` only — NEVER `Auth::requireLogin()`, and
 * this file NEVER sets `$_SESSION['user_id']` — see `kiosk.php`'s own
 * header for the full kiosk-identity session-key design.
 *
 * GATE CHAIN (read in order — every step can end the request):
 *   1. CSRF FIRST (`Auth::verifyCsrf()`) — before the device token is even
 *      looked up, so a forged/replayed POST never reaches terminal
 *      resolution. On failure, bounces back to `/assets/kiosk` carrying
 *      whatever token value was posted (kiosk.php independently and
 *      safely re-derives everything from it — this redirect never trusts
 *      it) so a genuine terminal can just retry.
 *   2. Device token resolved + re-validated exactly like `kiosk.php`
 *      (`AssetRegister::resolveKioskTerminal()`, `Site::forceContext()`,
 *      the site-scoped `assets.kiosk_enabled` flag) — ANY failure bounces
 *      to `/assets/kiosk` with the SAME (still-unvalidated) token, never
 *      rendering its own HTML here.
 *   3. From this point on the token IS confirmed valid+active+enabled —
 *      EVERY remaining redirect target is hard-coded to `/assets/kiosk?
 *      token=` + this SAME validated token. Never a caller-supplied
 *      return URL (no open redirect).
 *   4. Idle expiry re-checked exactly like `kiosk.php` (clears kiosk_user_*
 *      before dispatching, regardless of which action was requested).
 *   5. Action dispatch:
 *        - `identify` — TWO-tier rate limit (RateLimiter) checked BEFORE
 *          the PIN is even verified; `resolveKioskUser()` +
 *          `verifyKioskPin()`; uniform "incorrect details" failure
 *          message regardless of WHY (unknown identifier / no PIN set /
 *          wrong PIN / rate-limited all read the same to the visitor,
 *          except the rate-limit message which additionally states a
 *          retry-after — see acceptance criterion, not a confidentiality
 *          concern).
 *        - `checkout`/`checkin` — require an identified, unexpired kiosk
 *          user; delegate to `AssetRegister::findByScanCode()` (Pass 4) +
 *          `kioskCheckout()`/`kioskCheckin()` (their own IDOR/confidential
 *          guards — see that class's header point 14).
 *        - `logout` — clears kiosk_user_* only.
 *      Every branch refreshes `kiosk_expires` on success (checkout/checkin/
 *      identify) so a genuinely active session doesn't idle out mid-use.
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
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\RateLimiter;
use Portal\Core\Site;

// 🔓 Public — no Auth::requireLogin(). See file header + kiosk.php's own
// header for the shared kiosk-identity session-key design.
Auth::ensureSession();

$token = (string) ($_POST['token'] ?? '');

// -----------------------------------------------------------------------------
// 1️⃣ CSRF FIRST — before ANY token/site resolution. A forged or replayed
// POST must never even reach the terminal-resolution logic below.
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    header('Location: /assets/kiosk?token=' . rawurlencode($token), true, 303);
    exit();
}

// -----------------------------------------------------------------------------
// 2️⃣ Resolve + re-validate the device credential — SAME three gates as
// kiosk.php (unknown/revoked token, inactive site, feature flag off), all
// funnelling into the SAME redirect (no HTML rendered here, no oracle).
// -----------------------------------------------------------------------------
$terminal = AssetRegister::resolveKioskTerminal($token);
if ($terminal === null) {
    header('Location: /assets/kiosk?token=' . rawurlencode($token), true, 303);
    exit();
}

$siteId  = (int) $terminal['siteID'];
$tokenId = (int) $terminal['tokenID'];

try {
    Site::forceContext($siteId);
} catch (\Throwable $e) {
    header('Location: /assets/kiosk?token=' . rawurlencode($token), true, 303);
    exit();
}

$kioskEnabled = (string) (App::settingForSite('assets.kiosk_enabled', $siteId) ?? 'false') === 'true';
if ($kioskEnabled === false) {
    header('Location: /assets/kiosk?token=' . rawurlencode($token), true, 303);
    exit();
}

// -----------------------------------------------------------------------------
// 3️⃣ From here on the token is CONFIRMED valid+active+enabled — every
// further redirect target is hard-coded to THIS SAME validated token.
// Never a caller-supplied return URL (no open redirect).
// -----------------------------------------------------------------------------
$backToKiosk = '/assets/kiosk?token=' . rawurlencode($token);

/**
 * Flash a message and redirect back to THIS terminal, then stop. Local
 * closure so every branch below bails out identically — mirrors
 * `found-save.php`'s own `$bounce` convention.
 */
$bounce = static function (string $msg, string $type) use ($backToKiosk): never {
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $backToKiosk, true, 303);
    exit();
};

// -----------------------------------------------------------------------------
// 4️⃣ Idle expiry — re-checked exactly like kiosk.php, before dispatch.
// -----------------------------------------------------------------------------
$idleExpired = isset($_SESSION['kiosk_user_id']) === true
    && ((int) ($_SESSION['kiosk_expires'] ?? 0) < time()
        || (int) ($_SESSION['kiosk_token_id'] ?? 0) !== $tokenId);
if ($idleExpired === true) {
    unset($_SESSION['kiosk_user_id'], $_SESSION['kiosk_user_name'], $_SESSION['kiosk_expires']);
}
$_SESSION['kiosk_token_id'] = $tokenId;
$_SESSION['kiosk_site_id']  = $siteId;

$idleTimeoutSeconds = (int) (App::settingForSite('assets.kiosk_idle_timeout_seconds', $siteId) ?? '90');
if ($idleTimeoutSeconds <= 0) {
    $idleTimeoutSeconds = 90;
}

$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    // -------------------------------------------------------------------
    // 🙋 Identify — PIN entry. Rate-limited BEFORE the PIN is checked.
    // -------------------------------------------------------------------
    case 'identify':
        $ipHash = AssetRegister::publicIpHash();
        // 🪣 Two tiers — per device+IP (tight) and per device across ANY
        // IP (looser, catches distributed brute force through one stolen
        // token). Bucket keys per acceptance criterion.
        $deviceIpBucket = 'astkiosk_pin:' . $tokenId . ':' . $ipHash;
        $deviceBucket   = 'astkiosk_pin_dev:' . $tokenId;

        $tooManyDeviceIp = RateLimiter::tooMany($deviceIpBucket, 5, 300);
        $tooManyDevice   = RateLimiter::tooMany($deviceBucket, 20, 300);

        if ($tooManyDeviceIp === true || $tooManyDevice === true) {
            // 🚫 Refuse WITHOUT ever checking the PIN.
            $retryAfter = max(
                RateLimiter::retryAfter($deviceIpBucket, 5, 300),
                RateLimiter::retryAfter($deviceBucket, 20, 300)
            );
            $bounce('Too many attempts — please try again in ' . $retryAfter . ' second(s).', 'warning');
        }

        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $pin        = trim((string) ($_POST['pin'] ?? ''));

        $user     = $identifier !== '' ? AssetRegister::resolveKioskUser($siteId, $identifier) : null;
        $verified = $user !== null && AssetRegister::verifyKioskPin($siteId, (int) $user['userID'], $pin);

        if ($verified === false) {
            // 🙈 Uniform failure — unknown identifier, no PIN set, and a
            // wrong PIN are all indistinguishable from this response.
            RateLimiter::recordHit($deviceIpBucket, 300);
            RateLimiter::recordHit($deviceBucket, 300);
            Logger::activity('AssetKioskIdentifyFailed', 'Failed kiosk identify on terminal #' . $tokenId);
            $bounce('Incorrect details — please try again.', 'danger');
        }

        $_SESSION['kiosk_user_id']   = (int) $user['userID'];
        $_SESSION['kiosk_user_name'] = (string) $user['fullName'];
        $_SESSION['kiosk_expires']   = time() + $idleTimeoutSeconds;

        $bounce('Welcome, ' . (string) $user['fullName'] . '.', 'success');

    // -------------------------------------------------------------------
    // 📤 Check out an item — requires an identified, unexpired user.
    // -------------------------------------------------------------------
    case 'checkout':
        if (isset($_SESSION['kiosk_user_id']) === false || (int) $_SESSION['kiosk_user_id'] <= 0) {
            $bounce('Please identify yourself first.', 'warning');
        }
        $kioskUserId = (int) $_SESSION['kiosk_user_id'];

        $code  = trim((string) ($_POST['code'] ?? ''));
        $asset = $code !== '' ? AssetRegister::findByScanCode($siteId, $code) : null;
        if ($asset === null) {
            $bounce('Item not found — check the code and try again.', 'danger');
        }

        $result = AssetRegister::kioskCheckout((int) $asset['assetID'], $kioskUserId, $tokenId);
        $_SESSION['kiosk_expires'] = time() + $idleTimeoutSeconds;
        $bounce($result['msg'], $result['ok'] === true ? 'success' : 'danger');

    // -------------------------------------------------------------------
    // 📥 Check in an item — requires an identified, unexpired user.
    // kioskCheckin() carries its OWN IDOR guard (assetID + counterpartyUserID
    // + direction='out' + status='active'), so a tampered assetID for
    // someone ELSE's loan simply reports "you don't have that checked out".
    // -------------------------------------------------------------------
    case 'checkin':
        if (isset($_SESSION['kiosk_user_id']) === false || (int) $_SESSION['kiosk_user_id'] <= 0) {
            $bounce('Please identify yourself first.', 'warning');
        }
        $kioskUserId = (int) $_SESSION['kiosk_user_id'];

        $assetId     = (int) ($_POST['assetID'] ?? 0);
        $conditionIn = (string) ($_POST['conditionIn'] ?? '');

        $result = AssetRegister::kioskCheckin($assetId, $kioskUserId, $tokenId, $conditionIn);
        $_SESSION['kiosk_expires'] = time() + $idleTimeoutSeconds;
        $bounce($result['msg'], $result['ok'] === true ? 'success' : 'danger');

    // -------------------------------------------------------------------
    // 👋 "Done — not me" — clears identity only, terminal stays enrolled.
    // -------------------------------------------------------------------
    case 'logout':
        unset($_SESSION['kiosk_user_id'], $_SESSION['kiosk_user_name'], $_SESSION['kiosk_expires']);
        $bounce('Done — thanks!', 'info');

    // -------------------------------------------------------------------
    // ❓ Unknown action — fail closed rather than a no-op 200.
    // -------------------------------------------------------------------
    default:
        $bounce('Unrecognised kiosk action.', 'danger');
}
