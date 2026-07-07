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
// 📏 QrEncoder tops out around version-10 byte-mode capacity (~250 chars) with
//    no explicit throw on overflow; cap conservatively so we fail loudly.
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
