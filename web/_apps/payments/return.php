<?php
// Path: public_html/payments/return.php
/**
 * Payments — provider success/cancel landing page. Stripe stays webhook-
 * authoritative (unchanged). For PayPal, `intent=CAPTURE` orders are NOT
 * charged at approval — Payments::finalizeReturn() makes the explicit
 * capture call here, on the fastest path back from the provider (the
 * CHECKOUT.ORDER.APPROVED webhook is the backstop for a payer who approves
 * and never returns).
 *
 * @package   Portal\Payments
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Payments;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db        = App::db();
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$paymentId = (int) ($_GET['payment'] ?? 0);
$result    = (string) ($_GET['result'] ?? '');

// 🛡️ paymentID is a sequential GET int — scope to the caller's OWN payment
// (not just the site) so this landing page can't disclose another user's
// amount/purpose. A mismatched payment simply falls through to the generic
// "no $payment" branches below (no amount shown, "Return home" link only).
// The PayPal order id / `token` / `PayerID` query params PayPal appends to
// the redirect are deliberately NEVER read here (S3) — the order to act on
// always comes from the DB row's own providerRef, inside finalizeReturn().
$payment = null;
if ($paymentId > 0) {
    $stmt = $db->prepare('SELECT provider, amountPence, currency, status, purpose, purposeRef FROM tblPayment WHERE paymentID = ? AND siteID = ? AND userID = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('iii', $paymentId, $siteId, $userId);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

// 🟡 PayPal capture-on-return. No-op for Stripe (still webhook-
// authoritative) and re-checks the siteID+userID scoping itself — never
// trusts this page's own SELECT above.
if ($payment !== null) {
    $freshStatus = Payments::finalizeReturn($paymentId, $siteId, $userId, $result);
    if ($freshStatus !== null) {
        $payment['status'] = $freshStatus;
    }
}

$pageTitle   = 'Payment ' . ($result === 'ok' ? 'received' : 'cancelled');
$pageSection = 'payments';
$breadcrumbs = ['Dashboard' => '/', 'Payment' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php
// 🛡️ These two new states are scoped to PayPal ONLY (zero behaviour change
// for Stripe, which is still webhook-authoritative and — per the ORIGINAL
// comment this page always carried — routinely still shows `status =
// 'pending'` at this exact moment because its webhook hasn't landed yet;
// gating on provider keeps that pre-existing "always say thank you on
// result=ok" behaviour for Stripe completely untouched).
$isPaypalPending = $payment !== null && (string) $payment['provider'] === 'paypal' && (string) $payment['status'] === 'pending';
$isPaypalFailed  = $payment !== null && (string) $payment['provider'] === 'paypal' && (string) $payment['status'] === 'failed';
?>
<div class="text-center py-5">
    <?php if ($result === 'ok' && $isPaypalPending === true): ?>
        <i class="fa-solid fa-clock text-warning" style="font-size:64px;"></i>
        <h1 class="mt-3">Payment is still being confirmed</h1>
        <p class="text-muted">You'll receive a receipt once it completes — this can take a minute.</p>
    <?php elseif ($result === 'ok' && $isPaypalFailed === true): ?>
        <i class="fa-solid fa-circle-exclamation text-danger" style="font-size:64px;"></i>
        <h1 class="mt-3">We couldn't confirm this payment</h1>
        <p class="text-muted">No giving/pledge record was created. Please try again, or contact the office if you were charged.</p>
    <?php elseif ($result === 'ok'): ?>
        <i class="fa-solid fa-circle-check text-success" style="font-size:64px;"></i>
        <h1 class="mt-3">Thank you</h1>
        <?php if ($payment !== null): ?>
            <?php $sym = match ((string) $payment['currency']) { 'GBP' => '£', 'EUR' => '€', 'USD' => '$', default => $payment['currency'] . ' ' }; ?>
            <p class="lead"><?php echo $sym . number_format(((int) $payment['amountPence']) / 100, 2); ?> received.</p>
        <?php endif; ?>
        <p class="text-muted">Your receipt will arrive by email shortly.</p>
    <?php else: ?>
        <i class="fa-solid fa-circle-xmark text-secondary" style="font-size:64px;"></i>
        <h1 class="mt-3">Payment cancelled</h1>
        <p class="text-muted">No charge was made.</p>
    <?php endif; ?>
    <p class="mt-4">
        <?php if ($payment !== null && (string) $payment['purpose'] === 'pledge'): ?>
            <a href="/projects/my-pledges" class="btn btn-outline-primary">My pledges</a>
        <?php elseif ($payment !== null && (string) $payment['purpose'] === 'giving'): ?>
            <a href="/giving" class="btn btn-outline-primary">My giving</a>
        <?php else: ?>
            <a href="/" class="btn btn-outline-primary">Return home</a>
        <?php endif; ?>
    </p>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
