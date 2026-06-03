<?php
// Path: public_html/qr.php
/**
 * -----------------------------------------------------------------------------
 * QR Code endpoint 🔳
 * -----------------------------------------------------------------------------
 * Renders a QR code for any content string. Login-required by default to
 * prevent abuse as an arbitrary-content image generator; specific public
 * use cases (visitor capture form, calendar feed) can pre-render server-
 * side via Portal\Core\Qr directly instead of fetching this endpoint.
 *
 * Parameters:
 *   content   string  — text to encode (required, max 500 chars)
 *   size      int     — pixel size 64-1024 (default 256)
 *   format    string  — 'svg' (default) or 'png'
 *   ecc       string  — 'L'|'M'|'Q'|'H' (default 'M')
 *   fg / bg   hex     — colours (#000000 / #ffffff)
 *   caption   string  — optional caption below the QR (max 60 chars)
 *   provider  string  — 'local' (default) or 'cuercode' — if 'cuercode'
 *                       and configured, the content is first registered
 *                       with CueRCode and the returned tracking URL is
 *                       what gets encoded.
 *
 * @package   Portal
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/275
 * @link      https://github.com/MWBMPartners/CueRCode
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Qr;

Auth::ensureSession();
Auth::requireLogin();

$content = trim((string) ($_GET['content'] ?? ''));
if ($content === '' || strlen($content) > 500) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Bad content parameter');
}

$opts = [
    'size'    => (int) ($_GET['size']    ?? 256),
    'format'  => (string) ($_GET['format']  ?? 'svg'),
    'ecc'     => (string) ($_GET['ecc']     ?? 'M'),
    'fg'      => (string) ($_GET['fg']      ?? '#000000'),
    'bg'      => (string) ($_GET['bg']      ?? '#ffffff'),
    'caption' => substr((string) ($_GET['caption'] ?? ''), 0, 60),
];

// 🪞 If the URL forces provider=cuercode (and the org has configured CueRCode
//    credentials), resolve content via CueRCode to get the tracking URL.
$encodeContent = $content;
if (((string) ($_GET['provider'] ?? '')) === 'cuercode') {
    $encodeContent = Qr::resolveContent($content, (string) ($_GET['purpose'] ?? 'general'));
}

try {
    $result = Qr::generate($encodeContent, $opts);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('Could not generate QR: ' . $e->getMessage());
}

header('Content-Type: ' . $result['mime']);
header('Cache-Control: private, max-age=300');
echo $result['bytes'];
