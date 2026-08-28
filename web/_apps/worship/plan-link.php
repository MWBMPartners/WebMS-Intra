<?php
// Path: _apps/worship/plan-link.php
/**
 * -----------------------------------------------------------------------------
 * Worship — Service-Plan Bridge pair/unpair 🎶🔗 (gap #6, #442)
 * -----------------------------------------------------------------------------
 * Single POST handler serving BOTH counterpart panels — the run-sheet editor
 * (service-plans/edit.php) AND the worship plan editor (worship/plan.php)
 * post here. Mutates ONLY `tblServicePlans.runSheetPlanID` (plus the optional,
 * NULL-only, one-directional eventID backfill inside
 * `Portal\Core\ServicePlanLink::pair()` — see that class for the full
 * invariant set: same-site, same-event-when-known, 1:1, race-safe).
 *
 * Write ACL: pairing mutates a `tblServicePlans` row, so it reuses THAT app's
 * existing write gate verbatim (admin OR coordinator of the plan's bound
 * event; free-floating template plans are admin-only) — copied from
 * `plan-save.php`'s `$gate` closure, not refactored out of it (gap #6 plan
 * §3.4). Server-side enforcement here is authoritative regardless of which
 * page's UI shows the pair/unpair controls.
 *
 * Actions:
 *   pair   — worshipPlanID + runPlanID required.
 *   unpair — worshipPlanID required.
 *
 * `from` ('worship'|'runsheet') is a redirect-target hint ONLY — never
 * trusted for authorization or row identity.
 *
 * @package   Portal\Worship
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/442
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\ServicePlanLink;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /worship/plans', true, 302);
    exit();
}

// 🛡️ CSRF-first, then session/login (house convention).
Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$siteId        = Site::id();
$userId        = (int) ($_SESSION['user_id'] ?? 0);
$action        = (string) ($_POST['action'] ?? '');
$worshipPlanId = (int) ($_POST['worshipPlanID'] ?? 0);
$runPlanId     = (int) ($_POST['runPlanID'] ?? 0);
$from          = (string) ($_POST['from'] ?? 'worship');
if (in_array($from, ['worship', 'runsheet'], true) === false) {
    $from = 'worship';
}

if (in_array($action, ['pair', 'unpair'], true) === false || $worshipPlanId <= 0) {
    http_response_code(400);
    exit('Bad request');
}

// 📋 Load the worship plan (site-scoped) — 404 stops a forged cross-tenant
// planID before any write; also supplies eventID for the ACL gate below.
$db   = App::db();
$stmt = $db->prepare('SELECT planID, eventID, runSheetPlanID FROM tblServicePlans WHERE planID = ? AND siteID = ?');
$stmt->bind_param('ii', $worshipPlanId, $siteId);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($plan === null) {
    http_response_code(404);
    exit('Plan not found');
}

/**
 * 🛡️ Write ACL — admin OR coordinator of the bound event. Copied verbatim
 * from `plan-save.php`'s `$gate` closure (gap #6 plan §3.4 — do not refactor
 * plan-save.php for this change). Template plans (eventID NULL) admin-only.
 */
$gate = static function (array $plan): bool {
    if (App::isAdmin() === true) {
        return true;
    }
    if ($plan['eventID'] === null) {
        return false;
    }
    return Auth::isCoordinatorOf((int) $plan['eventID']);
};
if ($gate($plan) === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🔗 Resolve the run-sheet redirect target BEFORE mutating anything — an
// unpair posted from the run-sheet side needs its OWN planID to redirect
// back to, and unpair() never touches the run-sheet row itself.
$redirectRunPlanId = $runPlanId;
if ($action === 'unpair' && $from === 'runsheet') {
    $existing = ServicePlanLink::runSheetForWorshipPlan($worshipPlanId, $siteId);
    if ($existing !== null) {
        $redirectRunPlanId = (int) $existing['planID'];
    }
}

if ($action === 'pair') {
    if ($runPlanId <= 0) {
        $_SESSION['flash_msg']  = 'Choose a plan to link.';
        $_SESSION['flash_type'] = 'danger';
    } else {
        $result = ServicePlanLink::pair($worshipPlanId, $runPlanId, $siteId, $userId);
        if ($result['ok'] === true) {
            $_SESSION['flash_msg']  = 'Plans linked.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_msg']  = (string) $result['error'];
            $_SESSION['flash_type'] = 'danger';
        }
    }
} else {
    // unpair — always succeeds (a no-op if already unpaired).
    ServicePlanLink::unpair($worshipPlanId, $siteId, $userId);
    $_SESSION['flash_msg']  = 'Plans unlinked.';
    $_SESSION['flash_type'] = 'success';
}

if ($from === 'runsheet' && $redirectRunPlanId > 0) {
    header('Location: /service-plans/edit?id=' . $redirectRunPlanId, true, 302);
} else {
    header('Location: /worship/plan?id=' . $worshipPlanId, true, 302);
}
exit();
