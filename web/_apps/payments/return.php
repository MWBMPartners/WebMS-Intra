<?php
// Path: public_html/payments/return.php
/**
 * Payments — Stripe success/cancel landing page. The authoritative status
 * change happens via webhook; this page just shows a friendly outcome.
 *
 * @package   Portal\Payments
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db        = App::db();
$siteId    = Site::id();
$paymentId = (int) ($_GET['payment'] ?? 0);
$result    = (string) ($_GET['result'] ?? '');

$payment = null;
if ($paymentId > 0) {
    $stmt = $db->prepare('SELECT amountPence, currency, status, purpose, purposeRef FROM tblPayment WHERE paymentID = ? AND siteID = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $paymentId, $siteId);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$pageTitle   = 'Payment ' . ($result === 'ok' ? 'received' : 'cancelled');
$pageSection = 'payments';
$breadcrumbs = ['Dashboard' => '/', 'Payment' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="text-center py-5">
    <?php if ($result === 'ok'): ?>
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
