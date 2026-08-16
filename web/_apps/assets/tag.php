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
 * The full "I found this" submission form (posting to
 * _apps/assets/found-save.php) is a later sub-issue — this pass ships a
 * minimal, safe, read-only public view plus a placeholder for that form.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/395
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
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

$assetName  = (string) $asset['name'];
$siteName   = (string) (Site::branding('name') ?? App::settings('site.name') ?? 'this organisation');
$brandName  = method_exists(Site::class, 'productName') === true ? (string) Site::productName() : 'Portal';

$assetNameSafe = htmlspecialchars($assetName, ENT_QUOTES, 'UTF-8');
$siteNameSafe  = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
$brandNameSafe = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $assetNameSafe; ?> &middot; <?php echo $siteNameSafe; ?></title>
    <!-- 🤖 Never index a lost-and-found token page — it identifies a
         specific physical item and its owning organisation. -->
    <meta name="robots" content="noindex, nofollow, noai, noimageai">
    <link rel="stylesheet" href="/assets/css/portal.css">
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
        <!-- 🚧 The full "report found" submission form (posts to
             /assets/found-save) is a later Asset Tracker sub-issue. This
             placeholder keeps the page honest about what's available
             today rather than presenting a form that doesn't do anything
             yet. -->
        <button type="button" class="btn btn-outline-secondary" disabled title="Coming in a later Asset Tracker update">
            <i class="fa-solid fa-flag me-1"></i>Report as found (coming soon)
        </button>
    </div>
</main>

<footer class="tag-footer">
    <p>Powered by <?php echo $brandNameSafe; ?></p>
</footer>

</body>
</html>
