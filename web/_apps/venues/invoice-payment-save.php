<?php
// Path: _apps/venues/invoice-payment-save.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Record / Delete an Invoice Payment 💷
 * -----------------------------------------------------------------------------
 * POST-only handler behind `invoice.php`'s payments section. Both actions
 * funnel through `Venues::recordPayment()`/`Venues::deletePayment()`, which
 * each call `Venues::recomputeInvoiceStatus()` internally — this handler
 * never derives or writes an invoice's status itself (security item 11:
 * the paid family is entirely machine-managed).
 *
 * PURE OUTGOING LEDGER (01b-review-resolutions.md Q3) — no `tblPayment`
 * linkage, no Payments.php involvement anywhere in this flow.
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

// 🛡️ Manager gate.
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /venues/invoices');
    exit();
}

// 🔐 CSRF FIRST — before any side-effect.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /venues/invoices');
    exit();
}

$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$action    = (string) ($_POST['action'] ?? 'add');
$invoiceId = (int) ($_POST['invoiceID'] ?? 0);

if ($action === 'delete') {
    $payId = (int) ($_POST['payID'] ?? 0);
    $ok    = Venues::deletePayment($payId, $siteId, $userId);
    $_SESSION['flash_msg']  = $ok === true ? 'Payment removed.' : 'Payment not found.';
    $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
    header('Location: /venues/invoice?id=' . $invoiceId);
    exit();
}

// -----------------------------------------------------------------------------
// ➕ Add.
// -----------------------------------------------------------------------------
$data = [
    'amountPence' => (static function (string $raw): ?int {
        $v = trim($raw);
        if ($v === '' || is_numeric($v) === false) {
            return null;
        }
        return (int) round(((float) $v) * 100);
    })((string) ($_POST['amount'] ?? '')) ?? -1,
    'paidDate' => (string) ($_POST['paidDate'] ?? ''),
    'method'   => (string) ($_POST['method'] ?? 'bank-transfer'),
    'reference' => (string) ($_POST['reference'] ?? ''),
    'notes'    => (string) ($_POST['notes'] ?? ''),
];

$result = Venues::recordPayment($siteId, $invoiceId, $data, $userId);

if ($result['payID'] <= 0) {
    $_SESSION['flash_msg']  = count($result['errors']) > 0 ? implode(' ', $result['errors']) : 'Could not record payment.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /venues/invoice?id=' . $invoiceId);
    exit();
}

// 📣 Flash includes the recomputed status + outstanding (02 §4.7) —
// recordPayment() already called recomputeInvoiceStatus() internally, so
// re-fetching here reports the settled outcome, not a stale value.
$updated = Venues::getInvoice($invoiceId, $siteId);
if ($updated !== null) {
    $paid = 0;
    foreach ($updated['payments'] as $p) {
        $paid += (int) $p['amountPence'];
    }
    $outstanding = (int) $updated['amountPence'] - $paid;
    $_SESSION['flash_msg'] = sprintf(
        'Payment recorded — invoice now %s (£%s outstanding).',
        (string) $updated['status'],
        number_format($outstanding / 100, 2)
    );
} else {
    $_SESSION['flash_msg'] = 'Payment recorded.';
}
$_SESSION['flash_type'] = 'success';
header('Location: /venues/invoice?id=' . $invoiceId);
exit();
