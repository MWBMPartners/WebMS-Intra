<?php
// Path: _apps/venues/invoice-download.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Download an Invoice's Scanned Attachment 🧾
 * -----------------------------------------------------------------------------
 * Streams the single inline attachment from PORTAL_ROOT/_uploads/venues/
 * invoices/ (outside the webroot). Same anti-oracle streaming contract as
 * `agreement-download.php` (02 §12 items 1+4, this handler's own owned
 * security items): site-scoped fetch, `basename()` before touching the
 * filesystem, `is_file()` check, uniform 404 (NEVER 403) for any miss.
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

// 🛡️ Manager gate — invoice attachments are a manager-only surface (02 §7).
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

$invoiceId = (int) ($_GET['id'] ?? 0);
$siteId    = Site::id();

if ($invoiceId <= 0) {
    Router::renderError(404);
    return;
}

$db = App::db();
$stmt = $db->prepare('SELECT filePath, fileName, mimeType FROM tblVenueInvoices WHERE invoiceID = ? AND siteID = ? LIMIT 1');
if ($stmt === false) {
    Router::renderError(404);
    return;
}
$stmt->bind_param('ii', $invoiceId, $siteId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($invoice === null || $invoice === false || (string) ($invoice['filePath'] ?? '') === '') {
    Router::renderError(404);
    return;
}

// 📁 basename() the stored path before touching the filesystem — the
// stored name is always a server-generated `bin2hex(random_bytes(16)).ext`
// (see `Venues::saveInvoice()`), never client input; this is defence in
// depth, not the primary control.
$diskPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'venues'
    . DIRECTORY_SEPARATOR . 'invoices' . DIRECTORY_SEPARATOR . basename((string) $invoice['filePath']);

if (is_file($diskPath) === false) {
    Router::renderError(404);
    return;
}

$mimeType = (string) ($invoice['mimeType'] ?? 'application/octet-stream');
$fileName = (string) ($invoice['fileName'] ?? basename($diskPath));

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . addcslashes($fileName, '"') . '"');
header('Content-Length: ' . (string) filesize($diskPath));
header('Cache-Control: no-cache, must-revalidate');
readfile($diskPath);
exit();
