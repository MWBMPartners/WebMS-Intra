<?php
// Path: _apps/venues/invoices.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Invoices & Payments Ledger 🧾
 * -----------------------------------------------------------------------------
 * PURE OUTGOING LEDGER (01b-review-resolutions.md Q3): what the landlord
 * billed and what the church paid. NO `tblPayment`/`Payments.php` linkage
 * anywhere in this app — recording hire invoices is entirely manual/
 * offline, deliberately separate from the inbound Stripe/PayPal card rail.
 *
 * Lists every invoice for the site (filterable by venue/status), with a
 * pence-int footer totalling billed / paid / outstanding across the
 * filtered set (arithmetic stays in integer pence throughout — only the
 * final `number_format($p / 100, 2)` display conversion touches a float).
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

$siteId = Site::id();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// -----------------------------------------------------------------------------
// 📋 Filters + the list.
// -----------------------------------------------------------------------------
$venueFilter  = (int) ($_GET['venue'] ?? 0);
$statusFilter = (string) ($_GET['status'] ?? '');
if (in_array($statusFilter, Venues::INVOICE_STATUSES, true) === false) {
    $statusFilter = '';
}

$venues = Venues::listVenues($siteId, false);

$filters = [];
if ($venueFilter > 0) {
    $filters['venueID'] = $venueFilter;
}
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
$invoices = Venues::listInvoices($siteId, $filters);

// 💷 Pence-int footer totals across the filtered set.
$totalBilled = 0;
$totalPaid   = 0;
$today       = date('Y-m-d');
foreach ($invoices as $inv) {
    $totalBilled += (int) $inv['amountPence'];
    $totalPaid   += (int) $inv['paidPence'];
}
$totalOutstanding = $totalBilled - $totalPaid;

$statusBadge = [
    'pending'   => 'secondary',
    'part-paid' => 'info',
    'paid'      => 'success',
    'disputed'  => 'warning',
    'cancelled' => 'danger',
];

$pageTitle   = 'Invoices & Payments';
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venue Bookings' => '/venues', 'Invoices' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-file-invoice-dollar me-2"></i>Invoices &amp; Payments</h1>
<p class="text-muted">What the landlord has billed, and what has been paid — money OUT only. This app never takes card payments.</p>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="/venues/invoices" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small" for="venue">Venue</label>
                <select class="form-select form-select-sm" id="venue" name="venue" onchange="this.form.submit()">
                    <option value="0">All venues</option>
                    <?php foreach ($venues as $v): ?>
                        <option value="<?php echo (int) $v['venueID']; ?>" <?php echo $venueFilter === (int) $v['venueID'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $v['venueName'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach (Venues::INVOICE_STATUSES as $s): ?>
                        <option value="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($s), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5 text-end">
                <?php if (count($venues) > 0): ?>
                    <form method="get" action="/venues/invoice" class="d-inline-flex gap-2">
                        <select name="venue" class="form-select form-select-sm" required>
                            <option value="">— choose a venue —</option>
                            <?php foreach ($venues as $v): ?>
                                <option value="<?php echo (int) $v['venueID']; ?>"><?php echo htmlspecialchars((string) $v['venueName'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm text-nowrap"><i class="fa-solid fa-plus me-1"></i>New invoice</button>
                    </form>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (count($invoices) === 0): ?>
    <div class="alert alert-info">No invoices recorded yet.</div>
<?php else: ?>
    <div class="portal-data-list mb-2">
        <div class="portal-data-header">
            <div class="col-2">Reference</div>
            <div class="col-2">Venue</div>
            <div class="col-2">Issued / Due</div>
            <div class="col-2 text-end">Amount</div>
            <div class="col-2 text-end">Paid</div>
            <div class="col-1">Status</div>
            <div class="col-1 text-end">Open</div>
        </div>
        <?php foreach ($invoices as $inv): ?>
            <?php
            $isOverdue = (string) $inv['status'] !== 'paid' && (string) $inv['status'] !== 'cancelled'
                && !empty($inv['dueDate']) && (string) $inv['dueDate'] < $today;
            ?>
            <div class="portal-data-row align-items-center">
                <div class="col-2">
                    <strong><?php echo htmlspecialchars((string) ($inv['invoiceRef'] ?? ('#' . $inv['invoiceID'])), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if (!empty($inv['description'])): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars((string) $inv['description'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                    <?php if (!empty($inv['filePath'])): ?>
                        <i class="fa-solid fa-paperclip text-muted ms-1" title="Has an attachment"></i>
                    <?php endif; ?>
                </div>
                <div class="col-2 small"><?php echo htmlspecialchars((string) $inv['venueName'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-2 small text-muted">
                    <?php echo htmlspecialchars((string) $inv['issueDate'], ENT_QUOTES, 'UTF-8'); ?>
                    <br>
                    <span class="<?php echo $isOverdue ? 'text-danger fw-bold' : ''; ?>">
                        <?php echo htmlspecialchars((string) ($inv['dueDate'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php echo $isOverdue ? ' (overdue)' : ''; ?>
                    </span>
                </div>
                <div class="col-2 text-end">£<?php echo number_format(((int) $inv['amountPence']) / 100, 2); ?></div>
                <div class="col-2 text-end">£<?php echo number_format(((int) $inv['paidPence']) / 100, 2); ?></div>
                <div class="col-1">
                    <span class="badge bg-<?php echo $statusBadge[(string) $inv['status']] ?? 'secondary'; ?>">
                        <?php echo htmlspecialchars((string) $inv['status'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-1 text-end">
                    <a href="/venues/invoice?id=<?php echo (int) $inv['invoiceID']; ?>" class="btn btn-sm btn-outline-secondary" title="Open">
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="d-flex justify-content-end gap-4 text-end mb-4">
        <div><span class="text-muted small">Billed</span><br><strong>£<?php echo number_format($totalBilled / 100, 2); ?></strong></div>
        <div><span class="text-muted small">Paid</span><br><strong>£<?php echo number_format($totalPaid / 100, 2); ?></strong></div>
        <div><span class="text-muted small">Outstanding</span><br><strong>£<?php echo number_format($totalOutstanding / 100, 2); ?></strong></div>
    </div>
<?php endif; ?>

<a href="/venues" class="btn btn-outline-secondary mt-3"><i class="fa-solid fa-arrow-left me-1"></i>Back to schedule</a>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
