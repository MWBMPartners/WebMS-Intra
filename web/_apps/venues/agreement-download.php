<?php
// Path: _apps/venues/agreement-download.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Download an Agreement File 📎
 * -----------------------------------------------------------------------------
 * Streams an uploaded agreement file from PORTAL_ROOT/_uploads/venues/
 * agreements/ (outside the webroot — only ever reachable through this
 * handler). Mirrors `_apps/assets/resource-download.php`'s anti-oracle
 * contract (02 §12 items 1+4, this handler's own owned security items):
 *
 *   - The file row is fetched SITE-SCOPED (`fileID = ? AND siteID = ?`) —
 *     a foreign or non-existent id gets the SAME 404 as any other miss.
 *   - `basename()` is applied to the DB-stored `filePath` before it ever
 *     touches the filesystem — defence-in-depth, since `filePath` is
 *     always a server-generated `bin2hex(random_bytes(16)).ext` name
 *     (see `Venues::attachAgreementFile()`), never client input.
 *   - `is_file()` failure (file missing on disk) is ALSO a plain 404 —
 *     never a distinct error that would let a caller distinguish "wrong
 *     id" from "id right, file missing" (no existence oracle).
 *   - NEVER a 403 for any of the above — uniform 404 throughout.
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

// 🛡️ Manager gate — agreement files are a manager-only surface (02 §7).
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

$fileId = (int) ($_GET['id'] ?? 0);
$siteId = Site::id();

if ($fileId <= 0) {
    Router::renderError(404);
    return;
}

$db = App::db();
$stmt = $db->prepare('SELECT * FROM tblVenueAgreementFiles WHERE fileID = ? AND siteID = ? LIMIT 1');
if ($stmt === false) {
    Router::renderError(404);
    return;
}
$stmt->bind_param('ii', $fileId, $siteId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($file === null || $file === false) {
    Router::renderError(404);
    return;
}

// 📁 basename() the stored path before touching the filesystem — see
// file header's anti-oracle note. Never client input in the first place.
$diskPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'venues'
    . DIRECTORY_SEPARATOR . 'agreements' . DIRECTORY_SEPARATOR . basename((string) $file['filePath']);

if (is_file($diskPath) === false) {
    Router::renderError(404);
    return;
}

$mimeType = (string) ($file['mimeType'] ?? 'application/octet-stream');
$fileName = (string) ($file['fileName'] ?? basename($diskPath));

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . addcslashes($fileName, '"') . '"');
header('Content-Length: ' . (string) filesize($diskPath));
header('Cache-Control: no-cache, must-revalidate');
readfile($diskPath);
exit();
