<?php
// Path: _apps/noticeboard/api/qr.php
/**
 * GET /api/noticeboard/qr?data=<url>
 * Returns a QR image for the supplied text/URL. Uses the portal's own
 * Portal\Core\Qr encoder. If you stand up the dedicated CueRCode service,
 * swap the body to proxy/redirect to it (kept here as the single seam so the
 * front-end never changes).
 *
 * @package   Portal\Apps\Noticeboard
 * @see       https://github.com/MWBMPartners/CueRCode
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\ApiResponse;
use Portal\Core\Qr;

Auth::ensureSession();
ApiResponse::requireAuth();
ApiResponse::requireEnabled('api.noticeboard.qr.enabled');

$data = (string) ($_GET['data'] ?? '');
if ($data === '' || strlen($data) > 1024) {
    ApiResponse::error('Missing or oversized data parameter', 422);
}

// Only allow our own deep links to be encoded (prevents open QR-redirector abuse).
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
if ($host !== '' && str_contains($data, '://') === true && str_contains($data, $host) === false) {
    ApiResponse::error('QR data must reference this site', 422);
}

// --- Option A: portal's built-in encoder (default) -------------------------
// Adjust to Portal\Core\Qr's real signature if it differs in your version.
$png = Qr::pngBytes($data, 320);          // e.g. (string $text, int $size): string
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $png;
exit();

// --- Option B: dedicated CueRCode service ----------------------------------
// header('Location: https://cuercode.internal/api/qr?fmt=png&data=' . rawurlencode($data), true, 302);
// exit();
