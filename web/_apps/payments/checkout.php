<?php
// Path: public_html/payments/checkout.php
/**
 * Payments — start a checkout. POST with amount + purpose + (optional)
 * purposeRef; we redirect to the provider's hosted checkout.
 *
 * Used by Giving (purpose=giving, purposeRef=categoryID) and Projects
 * (purpose=pledge, purposeRef=pledgeID).
 *
 * purpose/purposeRef are authorised server-side against the current user +
 * site BEFORE the pending row is created (IDOR + amount-forgery hardening,
 * #430). Descriptions are built server-side from the validated category /
 * project name — the POSTed `description` field is never read (S11, closes
 * the provider-page text-injection vector PayPal's own hosted checkout page
 * makes reachable). Giving amounts additionally carry a sanity ceiling
 * (S10) — see GIVING_MAX_AMOUNT_PENCE below.
 *
 * @package   Portal\Payments
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.2
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Payments;
use Portal\Core\Site;

/**
 * 🛡️ Open-redirect guard for the POST-supplied `return_to` value. Mirrors
 * assets/event-assign.php's safe-redirect rule: reject protocol-relative
 * (`//`), absolute-URL (`://`), or non-rooted values; fall back to `/`.
 *
 * @param mixed $raw Raw POST value.
 *
 * @return string A same-origin, root-relative path.
 */
function sanitizeReturnTo(mixed $raw): string
{
    $value = (string) $raw;
    if ($value === ''
        || str_starts_with($value, '//') === true
        || str_contains($value, '://') === true
        || str_starts_with($value, '/') === false
    ) {
        return '/';
    }
    return $value;
}

// 💷 Fat-finger/fraud ceiling on self-service online GIVING only (S10) —
// large legitimate gifts should go through the office rather than this
// form. Pledges are exempt (their amount is forced from the pledge row
// below, never the POSTed value, so a large pledge is never at risk of
// this rejecting it). Tune here.
const GIVING_MAX_AMOUNT_PENCE = 1_000_000; // £10,000.00

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$settings = App::settings()['payments'] ?? [];
if ((string) ($settings['enabled'] ?? '0') !== '1') {
    http_response_code(503);
    exit('Payments not enabled');
}

$siteId   = Site::id();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$currency = (string) ($settings['currency'] ?? 'GBP');

$amountRaw   = (string) ($_POST['amount'] ?? '');
$clean       = preg_replace('/[^0-9.]/', '', $amountRaw) ?? '';
$amountPence = (int) round(((float) $clean) * 100);
if ($amountPence < 100) {
    $_SESSION['flash_msg']  = 'Minimum amount is 1.00.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . sanitizeReturnTo($_POST['return_to'] ?? '/'));
    exit();
}

$purpose     = (string) ($_POST['purpose'] ?? 'other');
$purposeRef  = trim((string) ($_POST['purposeRef'] ?? ''));
// 🛡️ Server-built description (S11) — the POSTed `description` field is
// NEVER read; this default only ever reaches the provider for the 'other'
// branch below, which has no natural title and never fans out.
$description = 'Donation';

// 🛡️ Purpose/purposeRef authorisation (IDOR + amount-forgery hardening).
// `purposeRef` arrives as raw POST and, uncontrolled, becomes a free-form
// foreign key that Payments::markPaymentSucceeded() acts on for the paying
// user's benefit (e.g. purpose=pledge marks ANY pledgeID fulfilled for
// whatever amount was POSTed). Validate against the CURRENT user + site
// here, before the pending tblPayment row is ever created, so an invalid
// or someone-else's-record reference can never reach startCheckout().
$db = App::db();
if ($purpose === 'pledge') {
    $pledgeId = (int) $purposeRef;
    $pledge   = null;
    if ($pledgeId > 0) {
        $stmt = $db->prepare(
            'SELECT p.donorID, p.amountPence, pr.currency, pr.title '
            . 'FROM tblProjectPledge p INNER JOIN tblProject pr ON pr.projectID = p.projectID '
            . 'WHERE p.pledgeID = ? AND pr.siteID = ? AND p.fulfilledAt IS NULL LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $pledgeId, $siteId);
            $stmt->execute();
            $pledge = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    // Require the payer to BE the pledge's own donor — no ownership check
    // upstream in Projects::fulfilPledge() means this is the only gate.
    if ($pledge === null || $userId <= 0 || (int) ($pledge['donorID'] ?? 0) !== $userId) {
        $_SESSION['flash_msg']  = "That pledge can't be paid.";
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . sanitizeReturnTo($_POST['return_to'] ?? '/'));
        exit();
    }
    // Force the amount + currency to the pledge's own record — never trust
    // the POSTed amount for a pledge (it would let an under-payment still
    // fulfil the pledge in full).
    $amountPence = (int) $pledge['amountPence'];
    $currency    = (string) ($pledge['currency'] ?? $currency);
    $purposeRef  = (string) $pledgeId;
    // 🖊️ Server-built description (S11) — never the POSTed value.
    $description = mb_substr('Pledge — ' . (string) $pledge['title'], 0, 120);
} elseif ($purpose === 'giving') {
    $categoryId = (int) $purposeRef;
    $category   = null;
    if ($categoryId > 0) {
        $stmt = $db->prepare(
            'SELECT categoryID, name FROM tblGivingCategory WHERE categoryID = ? AND siteID = ? AND isActive = 1 LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $categoryId, $siteId);
            $stmt->execute();
            $category = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    if ($category === null) {
        $_SESSION['flash_msg']  = "That giving category can't be used.";
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . sanitizeReturnTo($_POST['return_to'] ?? '/'));
        exit();
    }
    // 💷 Fat-finger/fraud ceiling (S10) — large legitimate gifts should go
    // through the office rather than this self-service form.
    if ($amountPence > GIVING_MAX_AMOUNT_PENCE) {
        $_SESSION['flash_msg']  = 'For large gifts please contact the office.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . sanitizeReturnTo($_POST['return_to'] ?? '/'));
        exit();
    }
    // The donation amount is the donor's own choice (subject to the
    // min/max checks above) — only the category needs validating.
    $purposeRef = (string) $categoryId;
    // 🖊️ Server-built description (S11) — never the POSTed value.
    $description = 'Giving — ' . (string) $category['name'];
} else {
    // membership/other/unrecognised — markPaymentSucceeded() has no side
    // effect for these purposes, so never let an arbitrary purposeRef ride
    // along on the pending row.
    $purpose    = 'other';
    $purposeRef = null;
}

$redirect = Payments::startCheckout($siteId, $userId, $amountPence, $currency, $description, $purpose, $purposeRef !== null && $purposeRef !== '' ? $purposeRef : null);
if ($redirect === null || $redirect === '') {
    $_SESSION['flash_msg']  = 'Could not start checkout — provider may not be configured.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . sanitizeReturnTo($_POST['return_to'] ?? '/'));
    exit();
}

header('Location: ' . $redirect, true, 303);
exit();
