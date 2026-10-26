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

use Portal\Core\ApiAuth;
use Portal\Core\Auth;
use Portal\Core\ApiResponse;
use Portal\Core\Qr;

Auth::ensureSession();
ApiAuth::requireRead('noticeboard:read');
// api.noticeboard.qr.enabled is already gated per-site by
// ApiRouter::resolveEnabledFlag before this handler runs (#323 Phase 2); a
// second handler-level check via App::settings() would read the frozen
// host-site snapshot and could wrongly 403 a valid bearer request.

$data = (string) ($_GET['data'] ?? '');
// 📏 A link to a notice is short. 300 bytes is far more than one needs and far
//    less than Qr can draw (2,331 bytes at the usual level, since #623), so
//    anything longer is refused here in the API's own way and Qr's own
//    refusal is never reached. (This comment used to say the encoder stopped
//    near 250 characters without refusing. It stopped at 213 with an error.)
if ($data === '' || strlen($data) > 300) {
    ApiResponse::error('Missing or oversized data parameter', 422);
}

// 🛡️ Only allow our own deep links to be encoded (prevents open QR-redirector
//    abuse). Strict-parse the host so substring collisions like
//    `https://evil.com/?x=<our-host>` don't slip through.
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
if (str_contains($data, '://') === true) {
    $qrHost = (string) (parse_url($data, PHP_URL_HOST) ?? '');
    if ($host === '' || strcasecmp($qrHost, $host) !== 0) {
        ApiResponse::error('QR data must reference this site', 422);
    }
}

// 📸 Portal's built-in encoder — returns ['mime' => string, 'bytes' => string].
//    PNG when gd is loaded, SVG fallback otherwise; Content-Type follows suit.
$qr = Qr::generate($data, ['size' => 320, 'format' => 'png']);
header('Content-Type: ' . $qr['mime']);
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $qr['bytes'];
exit();

// --- Option B: dedicated CueRCode service ----------------------------------
// header('Location: https://cuercode.internal/api/qr?fmt=png&data=' . rawurlencode($data), true, 302);
// exit();
