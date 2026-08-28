<?php
// Path: _apps/venues/invoice-save.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Save / Allocate / Transition / Delete an Invoice 🧾
 * -----------------------------------------------------------------------------
 * POST-only handler behind `invoice.php`'s four sections. Every write
 * funnels through `Portal\Core\Venues` (the audit choke-point) — this
 * handler never touches `tblVenueInvoices`/`tblVenueInvoiceLines` directly.
 *
 * Actions:
 *   save        — full create/update via Venues::saveInvoice(), with an
 *                 optional single-file attachment. UPLOAD SECURITY (item 3):
 *                 finfo-sniffed MIME validated against the SAME allow-list
 *                 `Venues::saveInvoice()` itself accepts (pdf/png/jpeg/webp
 *                 — a four-entry subset of `Venues::AGREEMENT_FILE_MIME_EXT`
 *                 that deliberately excludes docx: invoices are scanned/PDF
 *                 documents, and validating against exactly the subset the
 *                 class will actually store means a validated upload can
 *                 never be silently dropped by a mime the handler accepted
 *                 but the class's own internal map does not recognise).
 *                 venueID is only honoured on CREATE — saveInvoice()'s own
 *                 UPDATE branch never touches the column.
 *   allocate    — Venues::allocateInvoiceLine().
 *   remove-line — Venues::removeInvoiceLine().
 *   set-status  — MANUAL family only {disputed, cancelled} (security item
 *                 11 — the paid family is machine-derived from payments and
 *                 is never reachable through this action).
 *   delete      — HARD delete, re-checks App::isAdmin() itself (security
 *                 item 12 — never relies on the rendering page's own gate).
 *                 Venues::deleteInvoice() refuses while payments exist.
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
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

// 🛡️ Invoice attachments are scanned/PDF documents only — the intersection
// of Venues::AGREEMENT_FILE_MIME_EXT with the four mimes Venues::
// saveInvoice() itself recognises (see file header). Defined unconditionally
// at file scope (PHP requires top-level `const` to execute before any
// early-return branch reaches it).
const VENUES_INVOICE_ALLOWED_MIME = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — re-checked regardless of the rendering page's gate.
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
$action    = (string) ($_POST['action'] ?? 'save');
$invoiceId = (int) ($_POST['invoiceID'] ?? 0);

$toPence = static function (string $raw): ?int {
    $v = trim($raw);
    if ($v === '' || is_numeric($v) === false) {
        return null;
    }
    return (int) round(((float) $v) * 100);
};

$backToInvoice = static function (int $id) use ($invoiceId): never {
    header('Location: /venues/invoice?id=' . ($id > 0 ? $id : $invoiceId));
    exit();
};

// -----------------------------------------------------------------------------
// 🗑️ Admin-only hard delete.
// -----------------------------------------------------------------------------
if ($action === 'delete') {
    if (App::isAdmin() !== true) {
        $_SESSION['flash_msg']  = 'Admin access is required to delete an invoice.';
        $_SESSION['flash_type'] = 'danger';
        $backToInvoice($invoiceId);
    }
    $result = Venues::deleteInvoice($invoiceId, $siteId, $userId);
    if ($result['ok'] === true) {
        $_SESSION['flash_msg']  = 'Invoice deleted.';
        $_SESSION['flash_type'] = 'success';
        header('Location: /venues/invoices');
        exit();
    }
    $_SESSION['flash_msg']  = $result['error'] ?? 'Could not delete invoice.';
    $_SESSION['flash_type'] = 'danger';
    $backToInvoice($invoiceId);
}

// -----------------------------------------------------------------------------
// 🚫 Manual status transition — disputed/cancelled ONLY.
// -----------------------------------------------------------------------------
if ($action === 'set-status') {
    $status = (string) ($_POST['status'] ?? '');
    if (in_array($status, Venues::INVOICE_MANUAL_STATUSES, true) === false
        || Venues::setInvoiceStatus($invoiceId, $siteId, $status, $userId) === false
    ) {
        $_SESSION['flash_msg']  = 'Could not update invoice status.';
        $_SESSION['flash_type'] = 'danger';
        $backToInvoice($invoiceId);
    }
    $_SESSION['flash_msg']  = 'Invoice marked ' . $status . '.';
    $_SESSION['flash_type'] = 'success';
    $backToInvoice($invoiceId);
}

// -----------------------------------------------------------------------------
// 🔗 Allocate a booking to this invoice.
// -----------------------------------------------------------------------------
if ($action === 'allocate') {
    $bookingId = (int) ($_POST['bookingID'] ?? 0);
    $amountPence = $toPence((string) ($_POST['amount'] ?? ''));
    $result = Venues::allocateInvoiceLine($siteId, $invoiceId, $bookingId, $amountPence, $userId);
    $_SESSION['flash_msg']  = $result['lineID'] > 0 ? 'Booking allocated.' : ($result['error'] ?? 'Could not allocate booking.');
    $_SESSION['flash_type'] = $result['lineID'] > 0 ? 'success' : 'danger';
    $backToInvoice($invoiceId);
}

// -----------------------------------------------------------------------------
// ➖ Remove an allocation line.
// -----------------------------------------------------------------------------
if ($action === 'remove-line') {
    $lineId = (int) ($_POST['lineID'] ?? 0);
    $ok = Venues::removeInvoiceLine($lineId, $siteId, $userId);
    $_SESSION['flash_msg']  = $ok === true ? 'Allocation removed.' : 'Allocation line not found.';
    $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
    $backToInvoice($invoiceId);
}

// -----------------------------------------------------------------------------
// 💾 Default action — full create/update, with an optional attachment.
// -----------------------------------------------------------------------------
$venueId = (int) ($_POST['venueID'] ?? 0);

// 🚦 Create-mode failures must NOT bounce to `?id=0` (that 404s in
// invoice.php) — fall back to the create form's own `?venue=` URL.
$backToForm = static function () use ($invoiceId, $venueId): never {
    if ($invoiceId > 0) {
        header('Location: /venues/invoice?id=' . $invoiceId);
    } else {
        header('Location: /venues/invoice?venue=' . $venueId);
    }
    exit();
};

$data = [
    'venueID'      => $venueId,
    'agreementID'  => (int) ($_POST['agreementID'] ?? 0),
    'invoiceRef'   => (string) ($_POST['invoiceRef'] ?? ''),
    'description'  => (string) ($_POST['description'] ?? ''),
    'periodStart'  => (string) ($_POST['periodStart'] ?? ''),
    'periodEnd'    => (string) ($_POST['periodEnd'] ?? ''),
    'issueDate'    => (string) ($_POST['issueDate'] ?? ''),
    'dueDate'      => (string) ($_POST['dueDate'] ?? ''),
    'amountPence'  => $toPence((string) ($_POST['amount'] ?? '')) ?? -1,
    'currency'     => (string) ($_POST['currency'] ?? 'GBP'),
    'notes'        => (string) ($_POST['notes'] ?? ''),
];

// 📎 Optional attachment — same finfo-sniff pipeline as agreement-files.php,
// intersected against Venues::saveInvoice()'s own narrower allow-list.
if (isset($_FILES['file']) === true && (int) $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['file'];
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_msg']  = 'File upload failed.';
        $_SESSION['flash_type'] = 'danger';
        $backToForm();
    }
    if (is_uploaded_file($file['tmp_name']) === false) {
        $_SESSION['flash_msg']  = 'Invalid upload.';
        $_SESSION['flash_type'] = 'danger';
        $backToForm();
    }

    $maxSize = (int) App::settings('venues.maxFileSize');
    if ($maxSize <= 0) {
        $maxSize = 10485760;
    }
    if ((int) $file['size'] > $maxSize) {
        $_SESSION['flash_msg']  = 'File exceeds maximum size of ' . round($maxSize / 1048576, 1) . ' MB.';
        $_SESSION['flash_type'] = 'danger';
        $backToForm();
    }

    $binary = file_get_contents($file['tmp_name']);
    if ($binary === false || strlen($binary) === 0) {
        $_SESSION['flash_msg']  = 'Uploaded file is empty or unreadable.';
        $_SESSION['flash_type'] = 'danger';
        $backToForm();
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        Logger::errorPlatform('PHP', 'Error', 'VENUES_INVOICE_FINFO', 'finfo_open failed', '');
        $_SESSION['flash_msg']  = 'Unable to inspect uploaded file.';
        $_SESSION['flash_type'] = 'danger';
        $backToForm();
    }
    $sniffedMime = finfo_buffer($finfo, $binary);
    finfo_close($finfo);
    if ($sniffedMime === false
        || in_array($sniffedMime, VENUES_INVOICE_ALLOWED_MIME, true) === false
        || array_key_exists($sniffedMime, Venues::AGREEMENT_FILE_MIME_EXT) === false
    ) {
        $_SESSION['flash_msg']  = 'Unsupported file type for invoice attachments (pdf, png, jpg or webp only)'
            . ($sniffedMime !== false ? ': ' . $sniffedMime : '') . '.';
        $_SESSION['flash_type'] = 'danger';
        $backToForm();
    }

    $data['tmp_name'] = (string) $file['tmp_name'];
    $data['name']     = (string) $file['name'];
    $data['size']     = (int) $file['size'];
    $data['mime']     = $sniffedMime;
}

$result = Venues::saveInvoice($siteId, $invoiceId, $data, $userId);

if (count($result['errors']) > 0 || $result['id'] <= 0) {
    $_SESSION['flash_msg']  = count($result['errors']) > 0 ? implode(' ', $result['errors']) : 'Could not save invoice.';
    $_SESSION['flash_type'] = 'danger';
    $backToForm();
}

$_SESSION['flash_msg']  = 'Invoice saved.';
$_SESSION['flash_type'] = 'success';
$backToInvoice((int) $result['id']);
