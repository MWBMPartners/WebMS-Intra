<?php
// Path: _apps/geo/lookup.php
/**
 * -----------------------------------------------------------------------------
 * Geo — manual "Look up coordinates" AJAX proxy 📍 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Session-authed UI helper — deliberately outside `api/*` (same rationale
 * as `w3w-suggest.php`). Explicit user action — IGNORES `geo.autoGeocode`
 * (that flag only gates the SILENT auto-fill-on-save path); this endpoint
 * always attempts a lookup when called.
 *
 * Resolution order: a valid `w3w` input + API on -> What3Words::
 * convertToCoordinates(); else Geocoder::forward($address, $countryCode).
 *
 * @package   Portal\Geo
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Geocoder;
use Portal\Core\GeoLocation;
use Portal\Core\What3Words;

Auth::ensureSession();
Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'reason' => 'method_not_allowed']);
    exit;
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    echo json_encode(['ok' => false, 'reason' => 'csrf']);
    exit;
}

// 🔑 THE TOKEN WAS JUST SPENT (#1134). Auth::verifyCsrf() replaces the session's
//    one form token every time it accepts one (#768), and the page this answer
//    goes to goes on to post an ordinary form (Save). Every answer from here
//    on therefore carries the replacement as a top-level `csrfToken`, which the
//    page script hands to Portal.rememberCsrf() so every carrier on the page
//    is written. ONE closure makes every answer, so an exit added later cannot
//    forget it. The two answers ABOVE (wrong method, refused token) are given
//    before anything was spent, so they stay as they were. This endpoint stays
//    outside `api/*`, so it does not use ApiResponse's `meta.csrfToken`.
$answer = static function (array $body): never {
    $body['csrfToken'] = Auth::csrfToken();
    echo json_encode($body);
    exit;
};

$w3wRaw = trim((string) ($_POST['w3w'] ?? ''));
$w3w = $w3wRaw !== '' ? GeoLocation::validateW3W($w3wRaw) : null;

if ($w3w !== null && What3Words::isConfigured() === true) {
    $coords = What3Words::convertToCoordinates($w3w);
    if ($coords !== null) {
        $answer(['ok' => true, 'lat' => $coords['lat'], 'lng' => $coords['lng'], 'source' => 'w3w', 'formatted' => null]);
    }
}

$address = trim((string) ($_POST['address'] ?? ''));
if ($address === '') {
    // 🧩 No free-text address supplied — build one from structured parts,
    // mirroring GeoLocation::normaliseAddress() -> formatAddress().
    $parts = GeoLocation::normaliseAddress([
        'line1'       => $_POST['addressLine1'] ?? null,
        'line2'       => $_POST['addressLine2'] ?? null,
        'city'        => $_POST['addressCity'] ?? null,
        'region'      => $_POST['addressRegion'] ?? null,
        'postcode'    => $_POST['addressPostcode'] ?? null,
        'countryCode' => $_POST['countryCode'] ?? null,
    ]);
    $address = GeoLocation::formatAddress($parts);
}
$address = mb_substr($address, 0, 500);

if ($address === '') {
    $answer(['ok' => false, 'reason' => 'not_found']);
}

$countryCode = isset($_POST['countryCode']) ? trim((string) $_POST['countryCode']) : null;
// 🏠 Whether the address is somebody's home or a public place (D-183), as the
//    form's button says (`portal_location_input()`'s `addressKind`). ONLY the
//    exact word 'public' makes it public; anything else, a missing field or a
//    non-string (`addressKind[]=x`) is a home, the safe direction.
$addressKind = ($_POST['addressKind'] ?? null) === Geocoder::KIND_PUBLIC ? Geocoder::KIND_PUBLIC : Geocoder::KIND_HOME;
$result = Geocoder::forward($address, $countryCode !== '' ? $countryCode : null, $addressKind);

if ($result === null) {
    $answer(['ok' => false, 'reason' => 'not_found']);
}

$answer([
    'ok'        => true,
    'lat'       => $result['lat'],
    'lng'       => $result['lng'],
    'source'    => $result['source'],
    'formatted' => $result['formatted'],
]);
