<?php
// Path: _apps/venues/invoice.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Single Invoice: Form, Allocation, Payments, Status 🧾
 * -----------------------------------------------------------------------------
 * `?id=N` opens an existing invoice (404 unless it belongs to this site);
 * `?venue=N` opens the create form for that venue (404 unless the venue
 * belongs to this site). Four sections per 02-app-design.md §4.7:
 *
 *   1. The invoice form itself (posts `invoice-save.php action=save`) —
 *      venue is LOCKED after create (Venues::saveInvoice()'s own UPDATE
 *      branch never touches venueID), so it renders read-only in edit mode.
 *   2. Allocation lines — which bookings this invoice covers, with an
 *      add-line form scoped to the invoice's venue and (when set) its
 *      billing period, else ±1 year of the issue date.
 *   3. Payments — the pure outgoing ledger (01b Q3): what has actually
 *      been paid, with running total + outstanding.
 *   4. Manual status buttons (Dispute/Cancel — the ONLY statuses a human
 *      can set; the paid family is machine-derived from payments) and an
 *      admin-only hard-delete (refused server-side while payments exist).
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

use Portal\Core\App;
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
$db     = App::db();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$invoiceId = (int) ($_GET['id'] ?? 0);
$invoice   = null;
$venueId   = 0;

if ($invoiceId > 0) {
    $invoice = Venues::getInvoice($invoiceId, $siteId);
    if ($invoice === null) {
        Router::renderError(404);
        return;
    }
    $venueId = (int) $invoice['venueID'];
} else {
    $venueId = (int) ($_GET['venue'] ?? 0);
}

$venue = $venueId > 0 ? Venues::getVenue($venueId, $siteId) : null;
if ($venue === null) {
    Router::renderError(404);
    return;
}

// 🤝 Non-superseded agreements for this venue, for the agreement select.
$agreements = array_values(array_filter(
    Venues::listAgreements($siteId, ['venueID' => $venueId]),
    static fn (array $a): bool => (string) $a['status'] !== 'superseded'
));

$defaultCurrency = (string) App::settings('venues.currency') ?: 'GBP';

// -----------------------------------------------------------------------------
// 📅 Booking candidates for the "add allocation line" select — inside the
// invoice's billing period when set, else ±1 year of the issue date
// (02 §4.7's own fallback window).
// -----------------------------------------------------------------------------
$bookingCandidates = [];
if ($invoice !== null) {
    $periodStart = $invoice['periodStart'] ?? null;
    $periodEnd   = $invoice['periodEnd'] ?? null;
    if ($periodStart !== null && $periodEnd !== null) {
        $rangeFrom = (string) $periodStart;
        $rangeTo   = (string) $periodEnd;
    } else {
        $issue = new \DateTimeImmutable((string) $invoice['issueDate']);
        $rangeFrom = $issue->modify('-1 year')->format('Y-m-d');
        $rangeTo   = $issue->modify('+1 year')->format('Y-m-d');
    }
    $bookingCandidates = Venues::listBookings($siteId, [
        'venueID'  => $venueId,
        'dateFrom' => $rangeFrom,
        'dateTo'   => $rangeTo,
    ]);
    // 🚫 Exclude bookings already allocated to THIS invoice — no point
    // offering a duplicate (the DB unique key would just reject it).
    $alreadyAllocated = array_map(static fn (array $l): int => (int) $l['bookingID'], $invoice['lines']);
    $bookingCandidates = array_values(array_filter(
        $bookingCandidates,
        static fn (array $b): bool => in_array((int) $b['bookingID'], $alreadyAllocated, true) === false
    ));
}

// 👤 Resolve recordedByID -> fullName for the payments list (one batch
// read query — a display-only lookup, not a Venues:: mutation).
$recorderNames = [];
if ($invoice !== null && count($invoice['payments']) > 0) {
    $ids = array_values(array_unique(array_map(static fn (array $p): int => (int) $p['recordedByID'], $invoice['payments'])));
    if (count($ids) > 0) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $nameStmt = $db->prepare("SELECT userID, fullName FROM tblUsers WHERE userID IN ({$placeholders})");
        if ($nameStmt !== false) {
            $nameStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $nameStmt->execute();
            $nameResult = $nameStmt->get_result();
            while ($nameRow = $nameResult->fetch_assoc()) {
                $recorderNames[(int) $nameRow['userID']] = (string) ($nameRow['fullName'] ?? ('User #' . $nameRow['userID']));
            }
            $nameStmt->close();
        }
    }
}

$paidPence = 0;
if ($invoice !== null) {
    foreach ($invoice['payments'] as $p) {
        $paidPence += (int) $p['amountPence'];
    }
}
$outstandingPence = $invoice !== null ? ((int) $invoice['amountPence'] - $paidPence) : 0;

$pageTitle   = $invoice !== null ? 'Invoice ' . (string) ($invoice['invoiceRef'] ?? ('#' . $invoiceId)) : 'New Invoice';
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venue Bookings' => '/venues', 'Invoices' => '/venues/invoices', $pageTitle => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-file-invoice-dollar me-2"></i><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
<p class="text-muted"><?php echo htmlspecialchars((string) $venue['venueName'], ENT_QUOTES, 'UTF-8'); ?></p>

<!-- ===================================================================
     1️⃣ Invoice form.
==================================================================== -->
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Invoice details</h2>
        <form method="post" action="/venues/invoice-save" enctype="multipart/form-data" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="invoiceID" value="<?php echo $invoiceId; ?>">
            <input type="hidden" name="venueID" value="<?php echo $venueId; ?>">

            <div class="col-md-3">
                <label class="form-label small" for="invoiceRef">Landlord's reference</label>
                <input type="text" class="form-control form-control-sm" id="invoiceRef" name="invoiceRef" maxlength="100"
                       value="<?php echo htmlspecialchars((string) ($invoice['invoiceRef'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="description">Description</label>
                <input type="text" class="form-control form-control-sm" id="description" name="description" maxlength="500"
                       value="<?php echo htmlspecialchars((string) ($invoice['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label small" for="agreementID">Agreement (optional)</label>
                <select class="form-select form-select-sm" id="agreementID" name="agreementID">
                    <option value="0">— none —</option>
                    <?php foreach ($agreements as $a): ?>
                        <option value="<?php echo (int) $a['agreementID']; ?>" <?php echo (int) ($invoice['agreementID'] ?? 0) === (int) $a['agreementID'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $a['title'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small" for="periodStart">Billing period from</label>
                <input type="date" class="form-control form-control-sm" id="periodStart" name="periodStart"
                       value="<?php echo htmlspecialchars((string) ($invoice['periodStart'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="periodEnd">Billing period to</label>
                <input type="date" class="form-control form-control-sm" id="periodEnd" name="periodEnd"
                       value="<?php echo htmlspecialchars((string) ($invoice['periodEnd'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="issueDate">Issue date</label>
                <input type="date" class="form-control form-control-sm" id="issueDate" name="issueDate" required
                       value="<?php echo htmlspecialchars((string) ($invoice['issueDate'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="dueDate">Due date</label>
                <input type="date" class="form-control form-control-sm" id="dueDate" name="dueDate"
                       value="<?php echo htmlspecialchars((string) ($invoice['dueDate'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label small" for="amount">Amount</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">£</span>
                    <input type="number" step="0.01" min="0.01" class="form-control form-control-sm" id="amount" name="amount" required
                           value="<?php echo $invoice !== null ? htmlspecialchars(number_format(((int) $invoice['amountPence']) / 100, 2, '.', ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="currency">Currency</label>
                <input type="text" class="form-control form-control-sm" id="currency" name="currency" maxlength="3"
                       value="<?php echo htmlspecialchars((string) ($invoice['currency'] ?? $defaultCurrency), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-7">
                <label class="form-label small" for="notes">Notes</label>
                <input type="text" class="form-control form-control-sm" id="notes" name="notes"
                       value="<?php echo htmlspecialchars((string) ($invoice['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="col-md-8">
                <label class="form-label small" for="file">Scanned/PDF invoice<?php echo !empty($invoice['fileName']) ? ' (replaces the current attachment)' : ''; ?></label>
                <input type="file" class="form-control form-control-sm" id="file" name="file" accept=".pdf,.png,.jpg,.jpeg,.webp">
                <?php if (!empty($invoice['fileName'])): ?>
                    <div class="form-text">
                        Current: <a href="/venues/invoice-download?id=<?php echo $invoiceId; ?>"><?php echo htmlspecialchars((string) $invoice['fileName'], ENT_QUOTES, 'UTF-8'); ?></a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-check me-1"></i>Save invoice</button>
            </div>
        </form>
        <?php if ($invoice !== null): ?>
            <div class="mt-2">
                <a href="/venues/invoice-pdf?id=<?php echo $invoiceId; ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-file-pdf me-1"></i>Printable PDF</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($invoice !== null): ?>
    <!-- ===============================================================
         2️⃣ Allocation lines.
    ================================================================ -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5">Allocated bookings</h2>
            <?php if (count($invoice['lines']) === 0): ?>
                <p class="text-muted small">No bookings allocated to this invoice yet.</p>
            <?php else: ?>
                <div class="portal-data-list mb-3">
                    <div class="portal-data-header">
                        <div class="col-4">Booking date</div>
                        <div class="col-4">Notes</div>
                        <div class="col-2 text-end">Amount</div>
                        <div class="col-2 text-end">Remove</div>
                    </div>
                    <?php foreach ($invoice['lines'] as $line): ?>
                        <div class="portal-data-row align-items-center">
                            <div class="col-4"><?php echo htmlspecialchars((string) $line['bookingDate'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-4 small text-muted"><?php echo htmlspecialchars((string) ($line['bookingNotes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-2 text-end"><?php echo $line['amountPence'] !== null ? '£' . number_format(((int) $line['amountPence']) / 100, 2) : '—'; ?></div>
                            <div class="col-2 text-end">
                                <form method="post" action="/venues/invoice-save" class="d-inline" data-confirm="Remove this allocation?">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="remove-line">
                                    <input type="hidden" name="invoiceID" value="<?php echo $invoiceId; ?>">
                                    <input type="hidden" name="lineID" value="<?php echo (int) $line['lineID']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (count($bookingCandidates) > 0): ?>
                <form method="post" action="/venues/invoice-save" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="allocate">
                    <input type="hidden" name="invoiceID" value="<?php echo $invoiceId; ?>">
                    <div class="col-md-6">
                        <label class="form-label small" for="bookingID">Add booking</label>
                        <select class="form-select form-select-sm" id="bookingID" name="bookingID" required>
                            <?php foreach ($bookingCandidates as $b): ?>
                                <option value="<?php echo (int) $b['bookingID']; ?>">
                                    <?php echo htmlspecialchars((string) $b['bookingDate'], ENT_QUOTES, 'UTF-8'); ?>
                                    &mdash; <?php echo htmlspecialchars((string) $b['usageTypeName'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($b['notes'])): ?> (<?php echo htmlspecialchars(mb_substr((string) $b['notes'], 0, 40), ENT_QUOTES, 'UTF-8'); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="lineAmount">Amount (optional)</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">£</span>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="lineAmount" name="amount">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Allocate</button>
                    </div>
                </form>
            <?php else: ?>
                <p class="text-muted small">No unallocated bookings found for this venue in the invoice's period.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===============================================================
         3️⃣ Payments.
    ================================================================ -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5">Payments</h2>
            <?php if (count($invoice['payments']) === 0): ?>
                <p class="text-muted small">No payments recorded yet.</p>
            <?php else: ?>
                <div class="portal-data-list mb-3">
                    <div class="portal-data-header">
                        <div class="col-2">Date</div>
                        <div class="col-2 text-end">Amount</div>
                        <div class="col-2">Method</div>
                        <div class="col-2">Reference</div>
                        <div class="col-2">Recorded by</div>
                        <div class="col-2 text-end">Remove</div>
                    </div>
                    <?php foreach ($invoice['payments'] as $p): ?>
                        <div class="portal-data-row align-items-center">
                            <div class="col-2"><?php echo htmlspecialchars((string) $p['paidDate'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-2 text-end">£<?php echo number_format(((int) $p['amountPence']) / 100, 2); ?></div>
                            <div class="col-2 small"><?php echo htmlspecialchars((string) $p['method'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-2 small text-muted"><?php echo htmlspecialchars((string) ($p['reference'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-2 small text-muted"><?php echo htmlspecialchars($recorderNames[(int) $p['recordedByID']] ?? ('User #' . (int) $p['recordedByID']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-2 text-end">
                                <form method="post" action="/venues/invoice-payment-save" class="d-inline" data-confirm="Delete this payment record?" data-confirm-destructive="true">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="invoiceID" value="<?php echo $invoiceId; ?>">
                                    <input type="hidden" name="payID" value="<?php echo (int) $p['payID']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="mb-3 small">
                <strong>Paid so far:</strong> £<?php echo number_format($paidPence / 100, 2); ?>
                &nbsp;|&nbsp;
                <strong>Outstanding:</strong> £<?php echo number_format($outstandingPence / 100, 2); ?>
            </div>

            <?php if (in_array((string) $invoice['status'], Venues::INVOICE_MANUAL_STATUSES, true) === false): ?>
                <form method="post" action="/venues/invoice-payment-save" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="invoiceID" value="<?php echo $invoiceId; ?>">
                    <div class="col-md-2">
                        <label class="form-label small" for="paidDate">Date paid</label>
                        <input type="date" class="form-control form-control-sm" id="paidDate" name="paidDate" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" for="payAmount">Amount</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">£</span>
                            <input type="number" step="0.01" min="0.01" class="form-control form-control-sm" id="payAmount" name="amount" required>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" for="method">Method</label>
                        <select class="form-select form-select-sm" id="method" name="method">
                            <?php foreach (Venues::PAYMENT_METHODS as $m): ?>
                                <option value="<?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="reference">Reference</label>
                        <input type="text" class="form-control form-control-sm" id="reference" name="reference" maxlength="100">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Record payment</button>
                    </div>
                </form>
            <?php else: ?>
                <p class="text-muted small mb-0">This invoice is <?php echo htmlspecialchars((string) $invoice['status'], ENT_QUOTES, 'UTF-8'); ?> — payments cannot be recorded against it.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===============================================================
         4️⃣ Status + admin delete.
    ================================================================ -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5">Status</h2>
            <p>
                Current status:
                <span class="badge bg-secondary"><?php echo htmlspecialchars((string) $invoice['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="text-muted small ms-2">The paid/part-paid status is calculated automatically from recorded payments.</span>
            </p>
            <div class="d-flex flex-wrap gap-2">
                <?php if ((string) $invoice['status'] !== 'disputed'): ?>
                    <form method="post" action="/venues/invoice-save" data-confirm="Mark this invoice as disputed?">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="set-status">
                        <input type="hidden" name="invoiceID" value="<?php echo $invoiceId; ?>">
                        <input type="hidden" name="status" value="disputed">
                        <button type="submit" class="btn btn-sm btn-outline-warning">Dispute</button>
                    </form>
                <?php endif; ?>
                <?php if ((string) $invoice['status'] !== 'cancelled'): ?>
                    <form method="post" action="/venues/invoice-save" data-confirm="Cancel this invoice?" data-confirm-destructive="true">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="set-status">
                        <input type="hidden" name="invoiceID" value="<?php echo $invoiceId; ?>">
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Cancel invoice</button>
                    </form>
                <?php endif; ?>
                <?php if (App::isAdmin() === true): ?>
                    <form method="post" action="/venues/invoice-save"
                          data-confirm="Permanently delete this invoice? This cannot be undone. It is refused while payments exist."
                          data-confirm-destructive="true">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="invoiceID" value="<?php echo $invoiceId; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete invoice</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<a href="/venues/invoices" class="btn btn-outline-secondary mt-3"><i class="fa-solid fa-arrow-left me-1"></i>Back to invoices</a>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
