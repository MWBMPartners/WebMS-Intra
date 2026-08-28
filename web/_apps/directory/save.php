<?php
// Path: public_html/directory/save.php
/**
 * Member Directory — POST handler for own profile save.
 *
 * @package   Portal\Directory
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/261
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GeoLocation;
use Portal\Core\What3Words;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$validVisibility = static fn (string $v): string =>
    in_array($v, ['private','team','members','public'], true) ? $v : 'private';

$bio     = trim((string) ($_POST['displayBio'] ?? ''));
$phone   = trim((string) ($_POST['displayPhone'] ?? ''));
$address = trim((string) ($_POST['displayAddress'] ?? ''));
$vName    = $validVisibility((string) ($_POST['visibilityName']    ?? 'members'));
$vEmail   = $validVisibility((string) ($_POST['visibilityEmail']   ?? 'private'));
$vPhone   = $validVisibility((string) ($_POST['visibilityPhone']   ?? 'private'));
$vAddress = $validVisibility((string) ($_POST['visibilityAddress'] ?? 'private'));
$vCoords  = $validVisibility((string) ($_POST['visibilityCoords']  ?? 'private'));
$vBio     = $validVisibility((string) ($_POST['visibilityBio']     ?? 'members'));
$vRoles   = $validVisibility((string) ($_POST['visibilityRoles']   ?? 'members'));

// 📍 #456 Chunk B — member's own coordinates + what3words. Pair-or-null
// (GeoLocation::validateCoords()); an out-of-range or half-supplied pair
// is silently dropped to NULL rather than fatalling the whole save. A
// non-empty INVALID W3W value, however, is rejected with a flash + the
// save aborted entirely — never silently drop what looks like a typo'd
// three-word address.
$coords = GeoLocation::validateCoords($_POST['latitude'] ?? null, $_POST['longitude'] ?? null);
$latVal = $coords['lat'] ?? null;
$lngVal = $coords['lng'] ?? null;

$w3wRaw = trim((string) ($_POST['what3words'] ?? ''));
$w3w    = null;
if ($w3wRaw !== '') {
    $w3w = GeoLocation::validateW3W($w3wRaw);
    if ($w3w === null) {
        $_SESSION['flash_msg']  = t('location.w3w_invalid');
        $_SESSION['flash_type'] = 'danger';
        header('Location: /directory/my-settings');
        exit();
    }
    // 🗺️ W3W dual-mode verify (contract §3 / What3Words.php save-path
    // contract): only when the API integration is configured — a failed
    // verify NEVER blocks the save (best-effort, warn only), and this
    // handler never auto-geocodes a member's address (no `geo.autoGeocode`
    // consultation anywhere in this file — coordinates here are ALWAYS
    // either hand-entered or filled by the member's own explicit "Look up
    // coordinates" click, which already returns a coordinate pair via
    // /geo/lookup before this form is even submitted).
    if (What3Words::isConfigured() === true) {
        $resolved = What3Words::convertToCoordinates($w3w);
        if ($resolved !== null) {
            if ($latVal === null && $lngVal === null) {
                $latVal = $resolved['lat'];
                $lngVal = $resolved['lng'];
            }
        } else {
            $_SESSION['flash_msg']  = t('location.w3w_unverified');
            $_SESSION['flash_type'] = 'warning';
        }
    }
}

try {
    $stmt = $db->prepare(
        'UPDATE tblUsers SET '
        . 'displayBio = ?, displayPhone = ?, displayAddress = ?, latitude = ?, longitude = ?, what3words = ?, '
        . 'visibilityName = ?, visibilityEmail = ?, visibilityPhone = ?, '
        . 'visibilityAddress = ?, visibilityCoords = ?, visibilityBio = ?, visibilityRoles = ? '
        . 'WHERE userID = ?'
    );
    if ($stmt !== false) {
        // 📍 #456 Chunk B — 10 -> 14 placeholders/vars (+lat[d], +lng[d],
        // +w3w[s], +visibilityCoords[s]). Recounted against the final SET
        // list above: bio(s) phone(s) address(s) lat(d) lng(d) w3w(s)
        // vName(s) vEmail(s) vPhone(s) vAddress(s) vCoords(s) vBio(s)
        // vRoles(s) userId(i) = 14.
        $stmt->bind_param(
            'sssddssssssssi',
            $bio, $phone, $address, $latVal, $lngVal, $w3w,
            $vName, $vEmail, $vPhone, $vAddress, $vCoords, $vBio, $vRoles,
            $userId
        );
        $stmt->execute();
        $stmt->close();
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Directory', 'Warning', 'SAVE', $e->getMessage(), '');
}

header('Location: /directory/my-settings');
exit();
