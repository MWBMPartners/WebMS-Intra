<?php
// Path: _apps/venues/agreement-save.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Save / Transition / Supersede a Hire Agreement 📜
 * -----------------------------------------------------------------------------
 * POST-only handler behind `agreements.php`'s form(s). Three actions, each
 * funnelling every write through `Portal\Core\Venues` (which owns the audit
 * choke-point — this handler never touches `tblVenueAgreements` directly):
 *
 *   save        — full create/update via Venues::saveAgreement(). venueID is
 *                 only honoured on CREATE (saveAgreement's own UPDATE branch
 *                 never touches the column) — an agreement's venue is fixed
 *                 for its life once created.
 *   set-status  — a one-click transition restricted to the MANUAL family
 *                 {active, terminated, expired} (never `superseded`, which
 *                 is exclusively supersedeAgreement()'s side-effect, and
 *                 never `draft`, which only the full save form sets).
 *                 Implemented per 02-app-design.md §4.6 as "a validated
 *                 transition via saveAgreement": the existing row is
 *                 fetched, its status field replaced, and the FULL row is
 *                 re-saved — so every other field's validation still runs.
 *   supersede   — Venues::supersedeAgreement(): creates the renewal (via
 *                 saveAgreement(siteId, 0, ...) internally) and marks the
 *                 old agreement `superseded` + `supersededByID` in one call.
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — re-checked here regardless of the rendering page's
// own gate (security item 12 — every -save handler re-checks canManage()).
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

// 🚪 Non-POST hits redirect to the parent page rather than rendering
// anything (house convention, calendar/manage/save.php l.33-36 shape).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('venues/agreements'));
    exit();
}

// 🔐 CSRF FIRST — before any side-effect.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('venues/agreements'));
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? 'save');

// 💷 A typed amount becomes whole hundredths through the ONE converter,
//    Currency::parseAmount() (#1004), never `(int) round((float) $v * 100)`.
//    That wrapped an absurd figure into a small or negative one: measured on
//    PHP 8.5, "184467440737095557.12" was stored as 40.96, and "1e17" came out
//    negative so the page said "Amount cannot be negative", which was untrue.
//    It answers an int, or the true reason the text was refused, which is
//    shown and nothing is written. A typed box takes figures only (D-152):
//    no comma and no currency sign. No currency is passed, so a "too large"
//    refusal states the limit as a plain number.
//    A BLANK rate box means "no rate" (null), as before. Any other text must be
//    an amount: a figure that could not be read used to CLEAR the stored rate
//    without a word. A refusal goes back to the form with nothing written.
$rateOrRefuse = static function (string $raw, string $back): ?int {
    if (trim($raw) === '') {
        return null;
    }
    $rate = \Portal\Core\Currency::parseAmount($raw, '');
    if (is_string($rate) === true) {
        $_SESSION['flash_msg']  = 'Rate: ' . $rate . ' Nothing was saved.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $back);
        exit();
    }

    return $rate;
};

if ($action === 'set-status') {
    // -------------------------------------------------------------------
    // 🔁 Manual-family transition — fetch the full row, replace status,
    // re-save via the same validated path as a normal edit.
    // -------------------------------------------------------------------
    $agreementId = (int) ($_POST['agreementID'] ?? 0);
    $status      = (string) ($_POST['status'] ?? '');
    $manualFamily = ['active', 'terminated', 'expired'];

    if (in_array($status, $manualFamily, true) === false) {
        $_SESSION['flash_msg']  = 'Invalid status transition.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('venues/agreements') . '?edit=' . $agreementId);
        exit();
    }

    $existing = Venues::getAgreement($agreementId, $siteId);
    if ($existing === null) {
        Router::renderError(404);
        return;
    }

    $data = $existing;
    $data['status'] = $status;
    $result = Venues::saveAgreement($siteId, $agreementId, $data, $userId);

    $_SESSION['flash_msg']  = count($result['errors']) > 0 ? implode(' ', $result['errors']) : 'Agreement status updated.';
    $_SESSION['flash_type'] = count($result['errors']) > 0 ? 'danger' : 'success';
    header('Location: ' . Site::url('venues/agreements') . '?edit=' . $agreementId);
    exit();
}

if ($action === 'supersede') {
    // -------------------------------------------------------------------
    // 🔄 Renewal — a fresh agreement created from the posted fields; the
    // old agreement is marked superseded inside supersedeAgreement().
    // -------------------------------------------------------------------
    $oldAgreementId = (int) ($_POST['oldAgreementID'] ?? 0);

    $newData = [
        'venueID'          => (int) ($_POST['venueID'] ?? 0),
        'agreementType'    => (string) ($_POST['agreementType'] ?? 'standing'),
        'title'            => (string) ($_POST['title'] ?? ''),
        'reference'        => (string) ($_POST['reference'] ?? ''),
        'termStart'        => (string) ($_POST['termStart'] ?? ''),
        'termEnd'          => (string) ($_POST['termEnd'] ?? ''),
        'renewalDate'      => (string) ($_POST['renewalDate'] ?? ''),
        'noticePeriodDays' => (string) ($_POST['noticePeriodDays'] ?? ''),
        'rateAmountPence'  => $rateOrRefuse((string) ($_POST['rateAmount'] ?? ''), Site::url('venues/agreements') . '?edit=0&supersede=' . $oldAgreementId),
        'rateUnit'         => (string) ($_POST['rateUnit'] ?? ''),
        'currency'         => (string) ($_POST['currency'] ?? ''),
        'status'           => (string) ($_POST['status'] ?? 'active'),
        'notes'            => (string) ($_POST['notes'] ?? ''),
    ];

    $result = Venues::supersedeAgreement($oldAgreementId, $siteId, $newData, $userId);

    if ($result['newId'] <= 0) {
        $_SESSION['flash_msg']  = count($result['errors']) > 0 ? implode(' ', $result['errors']) : 'Could not create the renewal.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('venues/agreements') . '?edit=0&supersede=' . $oldAgreementId);
        exit();
    }

    $_SESSION['flash_msg']  = 'Renewal created; previous agreement marked superseded.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('venues/agreements') . '?edit=' . (int) $result['newId']);
    exit();
}

// -----------------------------------------------------------------------------
// 💾 Default action — full create/update.
// -----------------------------------------------------------------------------
$agreementId = (int) ($_POST['agreementID'] ?? 0);

$data = [
    'venueID'          => (int) ($_POST['venueID'] ?? 0),
    'agreementType'    => (string) ($_POST['agreementType'] ?? 'standing'),
    'title'            => (string) ($_POST['title'] ?? ''),
    'reference'        => (string) ($_POST['reference'] ?? ''),
    'termStart'        => (string) ($_POST['termStart'] ?? ''),
    'termEnd'          => (string) ($_POST['termEnd'] ?? ''),
    'renewalDate'      => (string) ($_POST['renewalDate'] ?? ''),
    'noticePeriodDays' => (string) ($_POST['noticePeriodDays'] ?? ''),
    'rateAmountPence'  => $rateOrRefuse((string) ($_POST['rateAmount'] ?? ''), Site::url('venues/agreements') . '?edit=' . $agreementId),
    'rateUnit'         => (string) ($_POST['rateUnit'] ?? ''),
    // Absent means "keep" on an edit (Venues::saveAgreement() decides), never today's currency (D-139).
    'currency'         => (string) ($_POST['currency'] ?? ''),
    'status'           => (string) ($_POST['status'] ?? 'draft'),
    'notes'            => (string) ($_POST['notes'] ?? ''),
];

$result = Venues::saveAgreement($siteId, $agreementId, $data, $userId);

if (count($result['errors']) > 0 || $result['id'] <= 0) {
    $_SESSION['flash_msg']  = count($result['errors']) > 0 ? implode(' ', $result['errors']) : 'Could not save agreement.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('venues/agreements') . '?edit=' . $agreementId);
    exit();
}

$_SESSION['flash_msg']  = 'Agreement saved.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . Site::url('venues/agreements') . '?edit=' . (int) $result['id']);
exit();
