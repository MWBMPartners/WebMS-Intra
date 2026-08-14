<?php
// Path: _apps/noticeboard/media.php
/**
 * -----------------------------------------------------------------------------
 * Noticeboard — Public Media Handler 🖼️ (#363)
 * -----------------------------------------------------------------------------
 * GET /noticeboard/media?f=<storedName>
 *
 * Streams an uploaded poster image/video back out by its server-generated
 * random token. Deliberately PUBLIC (tblRoutes.isProtected=0, no auth check
 * below) — posters are shareable via QR, so the media itself must render for
 * an anonymous scanner even though the board's editor page (/noticeboard)
 * stays login-gated. This handler serves BYTES ONLY; it never reveals
 * anything about the owning poster (title, site, schedule, etc.).
 *
 * 🛡️ Path-traversal / arbitrary-file-read hardening (defence in depth):
 *   1. `f` is validated against a strict `^[a-f0-9]{32}\.ext$` pattern BEFORE
 *      it touches anything — no dots, slashes, or any other character can
 *      ever reach the filesystem join below.
 *   2. Even a string that happens to match that pattern must ALSO exist as a
 *      row in tblNoticeboardUploads (the ledger upload.php writes) — a stray
 *      file that was never actually recorded is never served, so this isn't
 *      "trust the regex", it's "trust the regex AND the DB".
 *   3. The physical path is built by joining PORTAL_ROOT/_uploads/noticeboard/
 *      with ONLY the validated basename — client input never contributes a
 *      path segment, and basename() is applied again as a last-resort belt.
 *
 * @package   Portal\Apps\Noticeboard
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/363
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Router;

// 🛡️ Strict token format — 32 lowercase hex chars (bin2hex(random_bytes(16)))
//    + one of the allowlisted extensions. Anything else is rejected before
//    it ever reaches a filesystem call.
$requested = (string) ($_GET['f'] ?? '');
if (preg_match('/^[a-f0-9]{32}\.(png|jpg|jpeg|gif|webp|mp4|webm)$/', $requested) !== 1) {
    Router::renderError(404);
    return;
}

$db = App::db();

// 🔍 Resolve the token against the ledger. A filename that merely LOOKS
//    right but was never recorded (or was already cleaned up) 404s here.
$stmt = $db->prepare(
    'SELECT storedName, mimeType FROM tblNoticeboardUploads WHERE storedName = ? LIMIT 1'
);
if ($stmt === false) {
    Router::renderError(500);
    return;
}
$stmt->bind_param('s', $requested);
$stmt->execute();
$upload = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($upload === null) {
    Router::renderError(404);
    return;
}

// 🛡️ Re-derive the basename from the DB row (not the raw $_GET value) and
//    apply basename() again as a last-resort belt — the physical path never
//    incorporates anything the client supplied directly.
$storedName = basename((string) $upload['storedName']);
$filePath   = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR
            . 'noticeboard' . DIRECTORY_SEPARATOR . $storedName;

if (is_readable($filePath) === false) {
    Router::renderError(404);
    return;
}

// 📤 Serve — long-lived public cache is safe: the filename is random and
//    content-addressed in practice (a new upload always mints a new token).
$mimeType = (string) ($upload['mimeType'] ?? 'application/octet-stream');
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($filePath));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit();
