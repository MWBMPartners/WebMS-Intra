<?php
// Path: _apps/admin/settings/organisation/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Organisation address save handler 💾 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Normalises the address via `GeoLocation::normaliseAddress()`, validates
 * coords/W3W (invalid non-empty W3W is rejected with a flash — nothing
 * saved; empty is fine), applies the W3W dual-mode verify/fill contract
 * (§A1: API off/unavailable never blocks a save), and best-effort forward-
 * geocodes when `Geocoder::autoEnabled()` is on and coords are still empty.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Geocoder;
use Portal\Core\GeoLocation;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\What3Words;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$redirect = '/admin/settings/organisation';

$reject = static function (string $message) use ($redirect): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect, true, 302);
    exit();
};

$address = GeoLocation::normaliseAddress([
    'line1'       => $_POST['addressLine1'] ?? null,
    'line2'       => $_POST['addressLine2'] ?? null,
    'city'        => $_POST['city'] ?? null,
    'region'      => $_POST['region'] ?? null,
    'postcode'    => $_POST['postcode'] ?? null,
    'countryCode' => $_POST['countryCode'] ?? null,
]);

$coords = GeoLocation::validateCoords($_POST['latitude'] ?? null, $_POST['longitude'] ?? null);

$w3wRaw = trim((string) ($_POST['what3words'] ?? ''));
$w3w = null;
if ($w3wRaw !== '') {
    $w3w = GeoLocation::validateW3W($w3wRaw);
    if ($w3w === null) {
        $reject(t('location.w3w_invalid'));
    }
}

$warning = null;
if ($w3w !== null && What3Words::isConfigured() === true) {
    $verified = What3Words::convertToCoordinates($w3w);
    if ($verified !== null) {
        if ($coords === null) {
            $coords = $verified;
        }
    } else {
        // ⚠️ Verification failure is a warning, never a rejection — the
        // save still succeeds with the stored string (network failure
        // must never block a save).
        $warning = t('location.w3w_unverified');
    }
}

if ($coords === null && Geocoder::autoEnabled() === true) {
    $formatted = GeoLocation::formatAddress($address);
    if ($formatted !== '') {
        $geocoded = Geocoder::forward($formatted, $address['countryCode']);
        if ($geocoded !== null) {
            $coords = ['lat' => $geocoded['lat'], 'lng' => $geocoded['lng']];
        }
    }
}

$db = App::db();
$upsert = static function (mysqli $db, string $key, string $value): void {
    $stmt = $db->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
    if ($stmt === false) {
        return;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($id);
    $exists = $stmt->fetch() === true;
    $stmt->close();
    if ($exists === true) {
        $u = $db->prepare('UPDATE tblSettings SET settingValue = ?, updatedAt = NOW() WHERE settingID = ?');
        if ($u !== false) {
            $u->bind_param('si', $value, $id);
            $u->execute();
            $u->close();
        }
    } else {
        $u = $db->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) VALUES (?, ?, 0, NULL, NOW())');
        if ($u !== false) {
            $u->bind_param('ss', $key, $value);
            $u->execute();
            $u->close();
        }
    }
};

$upsert($db, 'org.address.line1', (string) ($address['line1'] ?? ''));
$upsert($db, 'org.address.line2', (string) ($address['line2'] ?? ''));
$upsert($db, 'org.address.city', (string) ($address['city'] ?? ''));
$upsert($db, 'org.address.region', (string) ($address['region'] ?? ''));
$upsert($db, 'org.address.postcode', (string) ($address['postcode'] ?? ''));
$upsert($db, 'org.address.countryCode', $address['countryCode']);
$upsert($db, 'org.latitude', $coords !== null ? (string) $coords['lat'] : '');
$upsert($db, 'org.longitude', $coords !== null ? (string) $coords['lng'] : '');
$upsert($db, 'org.what3words', $w3w ?? '');

Logger::activity('OrgAddressSaved', 'Organisation address updated');

$_SESSION['flash_msg']  = $warning !== null ? ('Address saved. ' . $warning) : 'Organisation address saved.';
$_SESSION['flash_type'] = $warning !== null ? 'warning' : 'success';
header('Location: ' . $redirect, true, 302);
exit();
