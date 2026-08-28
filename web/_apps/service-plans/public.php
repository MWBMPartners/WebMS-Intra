<?php
// Path: _apps/service-plans/public.php
/**
 * -----------------------------------------------------------------------------
 * Service Plans — public Order of Service view (gap #128) 🔗📄
 * -----------------------------------------------------------------------------
 * Reached ONLY via Router::handleSpecialRoutes()'s `os/{token}` special
 * case — cloned from the `a/{token}` block (assets/tag.php's own precedent)
 * — NOT a tblRoutes row, so this works even mid-migration. `$_GET['token']`
 * is pre-validated by the Router against `^[a-f0-9]{32}$` before this file
 * is even required; re-validated here defensively rather than trusting
 * that (same discipline as tag.php).
 *
 * ACCESS MODEL (security-relevant — read before changing):
 *   Renders ONLY when ALL of the following hold, in order:
 *     1. The token matches an existing `tblServicePlan.publicToken`.
 *     2. That plan's `isPublicShared = 1`.
 *     3. That plan's `status = 'published'` (never a draft or archived plan).
 *     4. The PLAN'S OWN SITE (never the request's host-detected site — see
 *        the tenant-safety note below) has `service_plans.public_share.
 *        enabled = 'true'` (the site-level kill-switch).
 *     5. The `service-plans` app itself is enabled for that site
 *        (`service_plans.enabled = '1'`).
 *   ANY of these failing renders the exact SAME 404 as an unknown token —
 *   uniform response, no oracle. An attacker probing tokens can never tell
 *   "wrong token" apart from "right token, but sharing is off" or "right
 *   token, but the app is disabled" (assets/tag.php's own documented
 *   rationale, applied here identically).
 *
 * TENANT SAFETY: this page is reached with NO active-site context (it is
 * public, unauthenticated, and the request's Host header may not even
 * belong to the plan's own site on a multi-site install) — so EVERY query
 * after the initial token lookup is scoped explicitly to
 * `$plan['siteID']` (never `Site::id()`), and the settings gate above uses
 * `App::settingForSite()` rather than the bootstrap `$SETTINGS` snapshot,
 * mirroring `ApiRouter::resolveEnabledFlag()`'s identical reasoning for a
 * bearer request pinned to a specific tenant.
 *
 * CONTENT MODEL — congregation fields ONLY, per gap #128's decided
 * defaults: order/position, section-type label, title, scripture-style
 * titles, and PRESENTER NAMES (decided default: yes — standard on a
 * printed order of service). Internal `notes` (AV cues / tech direction)
 * are NEVER queried, let alone rendered — the SELECT below simply does not
 * name that column, so there is no risk of a later refactor accidentally
 * echoing it. `noindex,nofollow`; no session is started; no personal data
 * beyond a presenter's display name is ever shown.
 *
 * @package   Portal\ServicePlans
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/128
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Router;
use Portal\Core\Site;

// 🔍 Defensive re-validation — the Router already checked this shape, but
// this file must never trust an upstream guarantee for something this
// security-relevant (assets/tag.php precedent).
$token = (string) ($_GET['token'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    Router::renderError(404);
    return;
}

$db   = App::db();
$stmt = $db->prepare(
    'SELECT planID, siteID, title, serviceDate, status FROM tblServicePlan '
    . 'WHERE publicToken = ? LIMIT 1'
);
if ($stmt === false) {
    Router::renderError(404);
    return;
}
$stmt->bind_param('s', $token);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 🚫 Branch 1 — unknown token. Same 404 as every other rejection below.
if ($plan === null) {
    Router::renderError(404);
    return;
}

$planSiteId = (int) $plan['siteID'];
$planIdInt  = (int) $plan['planID'];

// 🚫 Branches 2-5 — every remaining gate, uniform 404 on any failure.
// Deliberately re-selects `isPublicShared` alongside the others rather
// than trusting a partial row from above, keeping every gate in one place.
$gateRow  = null;
$gateStmt = $db->prepare('SELECT isPublicShared FROM tblServicePlan WHERE planID = ? LIMIT 1');
if ($gateStmt !== false) {
    $gateStmt->bind_param('i', $planIdInt);
    $gateStmt->execute();
    $gateRow = $gateStmt->get_result()->fetch_assoc();
    $gateStmt->close();
}

$isShared        = $gateRow !== null && (int) $gateRow['isPublicShared'] === 1;
$isPublished     = (string) $plan['status'] === 'published';
$siteShareOn     = (string) (App::settingForSite('service_plans.public_share.enabled', $planSiteId) ?? 'false') === 'true';
$appEnabledOn    = (string) (App::settingForSite('service_plans.enabled', $planSiteId) ?? '0') === '1';

if ($isShared === false || $isPublished === false || $siteShareOn === false || $appEnabledOn === false) {
    Router::renderError(404);
    return;
}

// ✅ Every gate passed — load congregation-safe items. `notes` is
// deliberately NOT selected (see file header "CONTENT MODEL").
$items = [];
$itemStmt = $db->prepare(
    'SELECT i.sectionType, i.position, i.title, i.presenterText, u.fullName AS presenterName '
    . 'FROM tblServicePlanItem i LEFT JOIN tblUsers u ON u.userID = i.presenterID '
    . 'WHERE i.planID = ? ORDER BY i.position, i.itemID'
);
if ($itemStmt !== false) {
    $itemStmt->bind_param('i', $planIdInt);
    $itemStmt->execute();
    $rs = $itemStmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $items[] = $r;
    }
    $itemStmt->close();
}

$siteName  = (string) (Site::branding('name') ?? App::settingForSite('site.name', $planSiteId) ?? 'this organisation');
$brandName = method_exists(Site::class, 'productName') === true ? (string) Site::productName() : 'Portal';

$planTitleSafe = htmlspecialchars((string) $plan['title'], ENT_QUOTES, 'UTF-8');
$siteNameSafe  = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
$brandNameSafe = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');
$serviceDate   = htmlspecialchars(date('l, j F Y', strtotime((string) $plan['serviceDate'])), ENT_QUOTES, 'UTF-8');

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $planTitleSafe; ?> &middot; <?php echo $siteNameSafe; ?></title>
    <!-- 🤖 Never index a public Order-of-Service page — a per-service link
         shared by the church itself, not content meant for search engines. -->
    <meta name="robots" content="noindex, nofollow, noai, noimageai">
    <meta http-equiv="Cache-Control" content="no-cache">
    <link rel="stylesheet" href="/assets/css/portal.css">
    <style>
        body { margin: 0; font-family: Georgia, "Times New Roman", serif; background: #f8f9fa; color: #1b2330; }
        .os-hero { background: #0d6efd; color: #fff; padding: 2.5rem 1rem; text-align: center; }
        .os-hero h1 { font-size: clamp(1.4rem, 5vw, 2.2rem); margin: 0 0 .35rem; font-weight: 800; }
        .os-hero p { margin: 0; opacity: .9; }
        .os-body { max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        .os-section { border-bottom: 1px solid #e5e7eb; padding: 0.85rem 0; }
        .os-section:last-child { border-bottom: none; }
        .os-num { float: left; font-weight: bold; width: 2rem; color: #0d6efd; }
        .os-section-body { margin-left: 2rem; }
        .os-section-title { font-weight: 600; }
        .os-section-meta { color: #6b7280; font-size: .9rem; margin-top: .2rem; }
        .os-footer { text-align: center; padding: 2rem 1rem; color: #6c757d; font-size: .85rem; }
        @media print { .os-hero { background: none !important; color: #000 !important; } }
    </style>
</head>
<body>

<section class="os-hero">
    <h1><i class="fa-solid fa-list-ol" aria-hidden="true"></i> <?php echo $planTitleSafe; ?></h1>
    <p><?php echo $siteNameSafe; ?> &middot; <?php echo $serviceDate; ?></p>
</section>

<main class="os-body">
    <?php if (count($items) === 0): ?>
        <p class="text-muted">The order of service has not been published yet.</p>
    <?php endif; ?>
    <?php foreach ($items as $idx => $it):
        // 🔒 Gap #128 — the internal sectionType label is deliberately NOT
        // rendered here (leader-only concept; see print.php's congregation
        // variant for the identical decision) — just numbering, title, and
        // presenter, matching a normal printed order-of-service bulletin.
        $presenter = (string) ($it['presenterName'] ?? '') !== ''
            ? (string) $it['presenterName']
            : (string) ($it['presenterText'] ?? '');
    ?>
        <div class="os-section">
            <div class="os-num"><?php echo $idx + 1; ?>.</div>
            <div class="os-section-body">
                <?php if (($it['title'] ?? '') !== ''): ?>
                    <div class="os-section-title"><?php echo htmlspecialchars((string) $it['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if ($presenter !== ''): ?>
                    <div class="os-section-meta"><?php echo htmlspecialchars($presenter, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</main>

<footer class="os-footer">
    <p>Powered by <?php echo $brandNameSafe; ?></p>
</footer>

</body>
</html>
