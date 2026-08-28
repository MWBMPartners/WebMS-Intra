<?php
// Path: _apps/venues/invoice-pdf.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Printable Invoice PDF 🧾
 * -----------------------------------------------------------------------------
 * Builds a CSS-Grid invoice document (NEVER `<table>`, even in PDF HTML —
 * house rule, AssetRegister.php l.7098-7100: grid is dompdf's most
 * reliable primitive AND honours the no-`<table>` convention everywhere)
 * and streams it via `Pdf::create()`. Falls back to the SAME HTML as a
 * print-CSS page with an auto-`window.print()` hint when dompdf isn't
 * installed (`labels-pdf.php` l.192-207 pattern) — never a bare 500.
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
use Portal\Core\Pdf;
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

$invoiceId = (int) ($_GET['id'] ?? 0);
$siteId    = Site::id();

$invoice = $invoiceId > 0 ? Venues::getInvoice($invoiceId, $siteId) : null;
if ($invoice === null) {
    Router::renderError(404);
    return;
}

$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$money = static fn (int $pence): string => '£' . number_format($pence / 100, 2);

$paidPence = 0;
foreach ($invoice['payments'] as $p) {
    $paidPence += (int) $p['amountPence'];
}
$outstandingPence = (int) $invoice['amountPence'] - $paidPence;

// -----------------------------------------------------------------------------
// 🧾 Build the CSS-Grid invoice document.
// -----------------------------------------------------------------------------
$linesRows = '';
foreach ($invoice['lines'] as $line) {
    $linesRows .= '<div class="row"><div>' . $h($line['bookingDate']) . '</div><div>'
        . $h($line['bookingNotes'] ?? '') . '</div><div class="num">'
        . ($line['amountPence'] !== null ? $money((int) $line['amountPence']) : '&mdash;') . '</div></div>';
}
if ($linesRows === '') {
    $linesRows = '<div class="row"><div colspan="3" class="muted">No individual bookings allocated.</div></div>';
}

$paymentRows = '';
foreach ($invoice['payments'] as $p) {
    $paymentRows .= '<div class="row"><div>' . $h($p['paidDate']) . '</div><div>' . $h($p['method']) . '</div><div>'
        . $h($p['reference'] ?? '') . '</div><div class="num">' . $money((int) $p['amountPence']) . '</div></div>';
}
if ($paymentRows === '') {
    $paymentRows = '<div class="row"><div colspan="4" class="muted">No payments recorded.</div></div>';
}

$css = '
body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #212529; font-size: 12px; }
.doc { max-width: 720px; margin: 0 auto; }
.header { display: grid; grid-template-columns: 1fr auto; align-items: start; margin-bottom: 24px; }
.brand { font-size: 18px; font-weight: bold; }
.title { font-size: 22px; font-weight: bold; text-align: right; }
.meta { display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px 24px; margin-bottom: 20px; }
.meta div span.label { color: #6c757d; display: block; font-size: 10px; text-transform: uppercase; }
.section-title { font-weight: bold; margin: 20px 0 6px; border-bottom: 1px solid #dee2e6; padding-bottom: 4px; }
.grid { width: 100%; }
.grid .row { display: grid; grid-template-columns: 1fr 2fr 1fr; padding: 4px 0; border-bottom: 1px solid #f1f3f5; }
.grid.payments .row { grid-template-columns: 1fr 1fr 1fr 1fr; }
.grid .row.head { font-weight: bold; border-bottom: 2px solid #212529; }
.num { text-align: right; }
.muted { color: #6c757d; }
.totals { display: grid; grid-template-columns: 1fr 1fr; margin-top: 16px; width: 260px; margin-left: auto; }
.totals div.label { color: #6c757d; }
.totals div.value { text-align: right; }
.totals .grand { font-weight: bold; font-size: 14px; border-top: 1px solid #212529; padding-top: 4px; }
';

$html = '<!doctype html><html><head><meta charset="utf-8"><title>Invoice</title><style>' . $css . '</style></head><body>'
    . '<div class="doc">'
    . '<div class="header"><div class="brand">' . $h(Site::productName()) . '</div><div class="title">INVOICE</div></div>'
    . '<div class="meta">'
    . '<div><span class="label">Venue</span>' . $h($invoice['venueName']) . '</div>'
    . '<div><span class="label">Reference</span>' . $h($invoice['invoiceRef'] ?? '#' . $invoiceId) . '</div>'
    . '<div><span class="label">Issue date</span>' . $h($invoice['issueDate']) . '</div>'
    . '<div><span class="label">Due date</span>' . $h($invoice['dueDate'] ?? '—') . '</div>'
    . '<div><span class="label">Billing period</span>' . $h(($invoice['periodStart'] ?? '—') . ' to ' . ($invoice['periodEnd'] ?? '—')) . '</div>'
    . '<div><span class="label">Status</span>' . $h($invoice['status']) . '</div>'
    . '</div>'
    . (!empty($invoice['description']) ? '<p>' . $h($invoice['description']) . '</p>' : '')
    . '<div class="section-title">Allocated bookings</div>'
    . '<div class="grid"><div class="row head"><div>Date</div><div>Notes</div><div class="num">Amount</div></div>' . $linesRows . '</div>'
    . '<div class="section-title">Payments</div>'
    . '<div class="grid payments"><div class="row head"><div>Date</div><div>Method</div><div>Reference</div><div class="num">Amount</div></div>' . $paymentRows . '</div>'
    . '<div class="totals">'
    . '<div class="label">Invoice total</div><div class="value">' . $money((int) $invoice['amountPence']) . '</div>'
    . '<div class="label">Paid to date</div><div class="value">' . $money($paidPence) . '</div>'
    . '<div class="label grand">Outstanding</div><div class="value grand">' . $money($outstandingPence) . '</div>'
    . '</div>'
    . (!empty($invoice['notes']) ? '<div class="section-title">Notes</div><p>' . nl2br($h($invoice['notes'])) . '</p>' : '')
    . '</div></body></html>';

// -----------------------------------------------------------------------------
// 📁 Ensure the temp output directory exists (outside the webroot).
// -----------------------------------------------------------------------------
$tmpDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'venues' . DIRECTORY_SEPARATOR . 'tmp';
if (is_dir($tmpDir) === false) {
    mkdir($tmpDir, 0755, true);
}
$pdfPath = $tmpDir . DIRECTORY_SEPARATOR . 'invoice-' . $invoiceId . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.pdf';

$generatedPath = Pdf::create($html, $pdfPath);

if ($generatedPath !== false) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="invoice-' . $invoiceId . '.pdf"');
    header('Content-Length: ' . (string) filesize($generatedPath));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($generatedPath);
    @unlink($generatedPath);
    exit();
}

// -----------------------------------------------------------------------------
// 🛟 FALLBACK — dompdf isn't installed. Render the same HTML as a browser
// print-CSS page instead of 500ing (labels-pdf.php l.192-207 pattern).
// -----------------------------------------------------------------------------
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice <?php echo $h($invoice['invoiceRef'] ?? '#' . $invoiceId); ?></title>
<style><?php echo $css; ?>
@media print { .no-print { display: none; } }
</style>
</head>
<body onload="window.print();">
<div class="no-print" style="margin-bottom:16px;"><em>PDF rendering is unavailable on this server — use your browser's Print dialog (Ctrl/Cmd+P) to save as PDF.</em></div>
<div class="doc">
<div class="header"><div class="brand"><?php echo $h(Site::productName()); ?></div><div class="title">INVOICE</div></div>
<div class="meta">
<div><span class="label">Venue</span><?php echo $h($invoice['venueName']); ?></div>
<div><span class="label">Reference</span><?php echo $h($invoice['invoiceRef'] ?? '#' . $invoiceId); ?></div>
<div><span class="label">Issue date</span><?php echo $h($invoice['issueDate']); ?></div>
<div><span class="label">Due date</span><?php echo $h($invoice['dueDate'] ?? '—'); ?></div>
</div>
<div class="section-title">Allocated bookings</div>
<div class="grid"><div class="row head"><div>Date</div><div>Notes</div><div class="num">Amount</div></div><?php echo $linesRows; ?></div>
<div class="section-title">Payments</div>
<div class="grid payments"><div class="row head"><div>Date</div><div>Method</div><div>Reference</div><div class="num">Amount</div></div><?php echo $paymentRows; ?></div>
<div class="totals">
<div class="label">Invoice total</div><div class="value"><?php echo $money((int) $invoice['amountPence']); ?></div>
<div class="label">Paid to date</div><div class="value"><?php echo $money($paidPence); ?></div>
<div class="label grand">Outstanding</div><div class="value grand"><?php echo $money($outstandingPence); ?></div>
</div>
</div>
</body>
</html>
