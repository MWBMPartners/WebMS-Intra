<?php
// Path: _apps/assets/tag.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Public Lost-and-Found Page 🏷️🔍
 * -----------------------------------------------------------------------------
 * Reached ONLY via Router::handleSpecialRoutes()'s `a/{token}` special case
 * (web/_core/Router.php — cloned from the `e/{slug}` public-landing block),
 * NOT a tblRoutes row. `$_GET['token']` is pre-validated by the Router
 * against `^[a-f0-9]{32}$` before this file is even required, but the
 * lookup below re-validates defensively rather than trusting that.
 *
 * ACCESS MODEL (#395 security-relevant — read before changing):
 *   1. Unknown token (no matching, non-deleted tblAssets row)  → 404.
 *   2. Logged-in AND (asset not confidential OR the user is an admin /
 *      responsible owner-party via AssetRegister::isResponsibleFor())
 *                                                                → 302 redirect
 *      to the internal /assets/item?id= detail page. This is the ONLY
 *      branch that reveals anything beyond the minimal public view.
 *   3. Everyone else — anonymous visitors, AND logged-in visitors who
 *      failed branch 2 — hits the SAME public-page gate:
 *        `assets.public_page_enabled` (global setting) AND
 *        `publicPageEnabled` (per-asset) AND NOT `isConfidential`.
 *      Any of those three failing renders the SAME 404 as branch 1 — a
 *      confidential asset, a disabled public page, and a token that never
 *      existed are all indistinguishable from the outside. This uniform
 *      response is deliberate: without it, an attacker could enumerate
 *      32-hex tokens and use the response (200 vs "disabled" vs "private"
 *      vs 404) as an oracle to learn which tokens are real and which are
 *      confidential. See "FOR OPUS SECURITY REVIEW" in the delivery report
 *      for this sub-issue.
 *   4. Only once every gate above passes does the page render — and only
 *      THEN is a `token`/`scan` audit row written, so a probe that never
 *      reaches a real, public-eligible asset leaves no scan trail to
 *      correlate against.
 *
 * THE "I FOUND THIS" FORM (#401, this pass) posts to
 * _apps/assets/found-save.php — that file re-derives EVERY gate above
 * independently (asset lookup by token, uniform 404, the same three-part
 * eligibility check) rather than trusting that a visitor who reached this
 * render still passes them by the time they submit; see found-save.php's
 * own header for its full gate chain (CSRF, Captcha, honeypot, per-IP
 * rate limit). This page's OWN job re: that form is limited to: issuing
 * the session-scoped CSRF token, rendering the multi-provider Captcha
 * widget (`Portal\Core\Captcha` — the SAME class/helper
 * `_apps/prayer-requests/anonymous.php` and `_apps/visitors/public-form.php`
 * use), rendering the honeypot field, and showing the flash message
 * found-save.php's redirect leaves in the session. The field allow-list
 * is UNCHANGED from the foundation pass — reporterName/reporterContact/
 * message only; no withheld field (owner/cost/serial/location/agreements)
 * is ever exposed here or accepted by found-save.php.
 *
 * SCAN LOG (#410, this pass): alongside the existing `audit('token', …,
 * 'scan')` event, this page now ALSO calls `AssetRegister::recordScan()`
 * — a separate, purpose-built, queryable log row (`tblAssetScanLog`) that
 * feeds item.php's manager-only scan-analytics sparkbar. Stores ONLY
 * salted hashes (never a raw IP/User-Agent) and never throws, so a
 * logging failure can never break this public page. See
 * AssetRegister::recordScan()'s own doc + class header point 11.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/395
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/401
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/410
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Router;
use Portal\Core\Site;

// 🔓 Public page — session is used only to check whether a logged-in
// viewer should be redirected to the internal detail page. NOT gated
// behind Auth::requireLogin() (mirrors _apps/calendar/public-landing.php
// and _apps/salvation/card.php).
Auth::ensureSession();

// 🔍 Defensive re-validation of the token shape — the Router already
// checked this, but this file must never trust an upstream guarantee for
// something this security-relevant.
$token = (string) ($_GET['token'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    Router::renderError(404);
    return;
}

$db = App::db();
$stmt = $db->prepare(
    'SELECT assetID, name, isConfidential, publicPageEnabled '
    . 'FROM tblAssets WHERE publicToken = ? AND isDeleted = 0 LIMIT 1'
);
if ($stmt === false) {
    Router::renderError(404);
    return;
}
$stmt->bind_param('s', $token);
$stmt->execute();
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 🚫 Branch 1 — unknown token. Same 404 as every other rejection below.
if ($asset === null || $asset === false) {
    Router::renderError(404);
    return;
}

$assetId            = (int) $asset['assetID'];
$isConfidential     = (int) $asset['isConfidential'] === 1;
$assetPageEnabled   = (int) $asset['publicPageEnabled'] === 1;
$loggedIn           = Auth::check();

// 🔀 Branch 2 — logged-in AND permitted → redirect to the real detail
// page. Only fires for a genuinely privileged viewer; everyone else
// (including logged-in-but-not-privileged) falls through to branch 3.
if ($loggedIn === true) {
    $privileged = App::isAdmin() === true || AssetRegister::isResponsibleFor($assetId) === true;
    if ($isConfidential === false || $privileged === true) {
        header('Location: /assets/item?id=' . $assetId, true, 302);
        exit();
    }
    // ⬇️ Deliberately falls through — see access-model comment above.
}

// 🚫 Branch 3 — the public-page gate. Anonymous visitors AND non-
// privileged logged-in visitors both land here, and both get the exact
// same outcome for the exact same reasons (uniform response, no oracle).
$globalPagesEnabled = (string) (App::settings('assets.public_page_enabled') ?? 'true') === 'true';
if ($globalPagesEnabled === false || $assetPageEnabled === false || $isConfidential === true) {
    Router::renderError(404);
    return;
}

// ✅ Branch 4 — every gate passed. Audit the view BEFORE rendering (so a
// request that errors out mid-render still leaves a trail), then render
// the minimal safe public page.
AssetRegister::audit('token', $assetId, $assetId, 'scan', actorType: $loggedIn === true ? 'user' : 'public');

// 🔍 Scan log (#410) — a SEPARATE, purpose-built row alongside the audit()
// call above (NOT instead of it — see AssetRegister's class header point
// 11 for why both exist). Context is always 'public' here (this file only
// ever renders the public /a/{token} page); the actor is the session user
// when this branch was reached by a logged-in-but-non-privileged viewer,
// else null (anonymous) — same `> 0 ? … : null` guard audit() itself uses
// above, so a defensively-empty session never inserts a bogus actorUserID
// of 0. recordScan() never throws — a logging failure can never break
// this public page.
$scanActorUserId = $loggedIn === true ? (int) ($_SESSION['user_id'] ?? 0) : 0;
AssetRegister::recordScan($assetId, 'public', $scanActorUserId > 0 ? $scanActorUserId : null);

$assetName  = (string) $asset['name'];
$siteName   = (string) (Site::branding('name') ?? App::settings('site.name') ?? 'this organisation');
$brandName  = method_exists(Site::class, 'productName') === true ? (string) Site::productName() : 'Portal';

$assetNameSafe = htmlspecialchars($assetName, ENT_QUOTES, 'UTF-8');
$siteNameSafe  = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
$brandNameSafe = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');

// 🛡️ CSRF token for the "I found this" form below — the session was
// already started (Auth::ensureSession() above) purely so an anonymous
// visitor can still carry a CSRF token, same convention as
// prayer-requests/anonymous.php and visitors/public-form.php.
$csrfToken = Auth::csrfToken();

// 🚩 Flash message left by found-save.php's post-submit redirect back to
// THIS page (session-based flash, same $_SESSION['flash_msg']/
// ['flash_type'] convention every internal app controller uses — see
// e.g. item.php). Consumed once, then cleared.
$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? 'info');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $assetNameSafe; ?> &middot; <?php echo $siteNameSafe; ?></title>
    <!-- 🤖 Never index a lost-and-found token page — it identifies a
         specific physical item and its owning organisation. -->
    <meta name="robots" content="noindex, nofollow, noai, noimageai">
    <!-- 🎨 Bootstrap + Font Awesome (CDN with local fallback, #401) — the
         foundation pass's placeholder button already used Bootstrap
         classes (btn btn-outline-secondary) without loading Bootstrap's
         CSS; now that a real form/alert/badge set of Bootstrap classes is
         in play, load it properly rather than compound that gap. -->
    <?php echo Asset::bootstrapCss(); ?>
    <?php echo Asset::fontAwesomeCss(); ?>
    <link rel="stylesheet" href="/assets/css/portal.css">
    <!-- 🤖 Captcha script for the active provider (Turnstile/reCAPTCHA/
         hCaptcha) — empty string, and Captcha::widget() below likewise
         empty, when no provider is configured (graceful degradation,
         same as every other Captcha::-using public form). -->
    <?php echo Captcha::scriptTag(); ?>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f8f9fa; }
        .tag-hero {
            background: #0d9488; color: #fff; padding: 3rem 1rem; text-align: center;
        }
        .tag-hero h1 { font-size: clamp(1.5rem, 5vw, 2.5rem); margin: 0 0 .5rem; font-weight: 800; }
        .tag-hero p { margin: 0; opacity: .9; }
        .tag-body { max-width: 560px; margin: 2rem auto; padding: 0 1rem; }
        .info-card { background: #fff; padding: 1.5rem; border-radius: 8px; margin-bottom: 1rem; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
        .info-card h2 { margin-top: 0; font-size: 1.1rem; color: #0d9488; }
        .tag-footer { text-align: center; padding: 2rem 1rem; color: #6c757d; font-size: .85rem; }
        /* 🕳️ Honeypot (#401) — visually hidden from a sighted human AND
           removed from tab order, but still present in the DOM/markup for
           a naive scraping bot to auto-fill. A real visitor never sees or
           reaches this field; found-save.php silently treats a filled-in
           value as "this was a bot" and pretends the submission succeeded
           without ever writing a tblAssetFoundReports row. */
        .hp-field { position: absolute; left: -9999px; top: -9999px; width: 1px; height: 1px; overflow: hidden; }
    </style>
</head>
<body>

<section class="tag-hero">
    <h1><i class="fa-solid fa-tag me-2"></i><?php echo $assetNameSafe; ?></h1>
    <p>Property of <?php echo $siteNameSafe; ?></p>
</section>

<main class="tag-body">
    <div class="info-card">
        <h2><i class="fa-solid fa-hand-holding-heart me-1"></i>Found this item?</h2>
        <p>
            If you have found this item, please return it to
            <strong><?php echo $siteNameSafe; ?></strong> or get in touch with them directly —
            they will be able to confirm the best way to arrange its return.
        </p>

        <?php if ($flashMsg !== ''): ?>
            <!-- 🚩 Flash from found-save.php's post-submit redirect — see
                 setup code above. htmlspecialchars() even though every
                 flash string this file's own controller sets is a fixed
                 literal (defence in depth — never trust that stays true). -->
            <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> mb-3" role="alert">
                <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <!-- 📝 "I found this" form (#401) — posts to found-save.php, which
             re-derives every access gate independently (see this file's
             header). Carries the TOKEN (not a numeric assetID) so
             found-save.php looks the asset up exactly the way this page
             did. Field allow-list is deliberately tiny: a name, SOME way
             to get in touch (or at least a message), nothing else. -->
        <form method="post" action="/assets/found-save" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

            <!-- 🕳️ Honeypot — see the .hp-field style comment above. Real
                 humans never see this; a bot that blindly fills every
                 field it can find will trip it. aria-hidden + tabindex=-1
                 + autocomplete=off so it's also invisible to assistive
                 tech and never reachable by keyboard tabbing. -->
            <div class="hp-field" aria-hidden="true">
                <label for="website">Leave this field blank</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="mb-3">
                <label for="reporterName" class="form-label">Your name <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control" id="reporterName" name="reporterName" maxlength="150" autocomplete="name">
            </div>

            <div class="mb-3">
                <label for="reporterContact" class="form-label">Email or phone <span class="text-muted">(so we can reach you)</span></label>
                <input type="text" class="form-control" id="reporterContact" name="reporterContact" maxlength="255" autocomplete="email">
            </div>

            <div class="mb-3">
                <label for="message" class="form-label">Message <span class="text-muted">(optional)</span></label>
                <textarea class="form-control" id="message" name="message" rows="3" maxlength="4000" placeholder="Where/when you found it, or anything else that might help…"></textarea>
            </div>

            <p class="small text-muted">Please provide at least an email/phone number or a short message so we know how to follow up.</p>

            <?php echo Captcha::widget(); ?>

            <button type="submit" class="btn btn-success w-100 mt-2">
                <i class="fa-solid fa-flag me-1"></i>Report as found
            </button>
        </form>
    </div>
</main>

<footer class="tag-footer">
    <p>Powered by <?php echo $brandNameSafe; ?></p>
</footer>

</body>
</html>
