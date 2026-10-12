<?php
// Path: _apps/admin/settings/organisation/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Organisation address save handler 💾 (#456 Chunk A)
 *       — and the "Web addresses" card of the same page (#500)
 * -----------------------------------------------------------------------------
 * TWO FORMS POST HERE. The page has the postal address form and, since #500,
 * a second form, "Web addresses": the portal's own web address (the
 * `site.url` setting) and the support e-mail address. The second form sends
 * a hidden field `form=web-addresses`. It is handled FIRST and then stops,
 * before any postal-address code runs: that form carries no postal fields,
 * and letting it fall through would save an empty postal address over the
 * real one. Both forms pass the same three gates below (administrator, form
 * token, global administrator). One address serves both forms so that no
 * new address had to be registered.
 *
 * The portal's address is checked by PortalAddress::check(), the same code
 * that judges the stored value every time it is read, so nothing can be
 * saved here that the portal would then ignore. Both fields are checked
 * before either is saved: a refusal changes nothing.
 *
 * THE DASHBOARD'S "USE THIS ADDRESS" BUTTON posts here too (#500), as the
 * same form with the portal's address alone. It passes every gate the card
 * passes and gets the same activity-log line. Because it sends no support
 * address, a support address that was NOT SENT is left as it is; only a box
 * that was sent, empty or not, changes that setting.
 *
 * The rest of this header is about the postal address form.
 *
 * Normalises the address via `GeoLocation::normaliseAddress()`, validates
 * coords/W3W (invalid non-empty W3W is rejected with a flash — nothing
 * saved; empty is fine), applies the W3W dual-mode verify/fill contract
 * (§A1: API off/unavailable never blocks a save), and best-effort forward-
 * geocodes when `Geocoder::autoEnabled()` is on and coords are still empty.
 *
 * WHO MAY SAVE HERE
 * -----------------------------------------------------------------------------
 * Every setting this handler saves is portal-wide: it is written with nothing
 * in the siteID column, so it is the value EVERY organisation on this
 * installation uses. That is the organisation's postal address, map position
 * and what3words address.
 *
 * Until 11 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of any one
 * organisation could overwrite the address that every organisation on the
 * installation shows. The owner decided that settings affecting every
 * organisation are for a global administrator only. Anybody else is now
 * refused, is told why on screen, and nothing is saved.
 *
 * This may be the wrong way round. An address and map position look like
 * something each organisation ought to have for itself, and the page calls it
 * the organisation's address. Making it per-organisation would change what
 * every existing installation shows, so it needs its own decision. For now it
 * is simply reserved for a global administrator, which is safe either way.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.5.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Geocoder;
use Portal\Core\GeoLocation;
use Portal\Core\Logger;
use Portal\Core\PortalAddress;
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

// 🚧 Portal-wide settings are for a global administrator only — see "WHO MAY
//    SAVE HERE" in the file header. The refusal gives the reason rather than a
//    bare "forbidden": an administrator who presses Save and is told nothing
//    assumes the portal is broken and tries again. It comes after the
//    form-token check, so a forged request from another website cannot fill
//    the activity log with refusals. It also comes before the checks on what
//    was typed in: there is no point telling somebody their input is wrong
//    when they may not save it at all. $reject (above) shows the reason and
//    saves nothing.
//
//    What this cannot do: the page that shows the form
//    (web/_apps/admin/settings/organisation/index.php) was not changed, so an
//    administrator of one organisation still sees a Save button there. This
//    refusal is what actually enforces the rule.
if (App::isRootAdmin() === false) {
    Logger::activity(
        'SettingsGroupSaveRefused',
        'Refused: portal-wide settings group "organisation" may only be changed by a global administrator',
        $_SESSION['user_id'] ?? null
    );
    $reject(
        'These settings are portal-wide: they apply to every '
            . 'organisation on this installation, not only yours. Only a global '
            . 'administrator can change them. Your own organisation\'s settings '
            . 'are on the main Settings page and are unaffected.'
    );
}

// 💾 One installation-wide setting: change the row that is there, or add it.
//    Defined here, above both forms, because both use it. (It used to sit
//    further down, when the postal address was the only form.)
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

// -----------------------------------------------------------------------------
// 🌐 The "Web addresses" form (#500). See the file header: it is handled
//    here and stops, so that none of the postal-address code below runs.
// -----------------------------------------------------------------------------
if (($_POST['form'] ?? '') === 'web-addresses') {
    // A form field is text or it is nothing: a hand-made request can send a
    // list where text is expected, and a list is read as empty.
    $text = static fn (mixed $value): string => is_string($value) === true ? $value : '';

    $checked = PortalAddress::check($text($_POST['portalAddress'] ?? ''));
    if ($checked['problem'] !== '') {
        $reject('Nothing was saved. ' . $checked['problem']);
    }
    // The support address changes only when its box was SENT. The card
    // always sends it, empty or not. The dashboard's one-press button sends
    // the portal's address alone; before this test a request without the
    // box was read as "empty the box", and the button would have wiped a
    // support address somebody had typed.
    $supportSent  = array_key_exists('supportEmail', $_POST) === true;
    $supportEmail = trim($text($_POST['supportEmail'] ?? ''));
    if ($supportEmail !== ''
        && (strlen($supportEmail) > 254 || filter_var($supportEmail, FILTER_VALIDATE_EMAIL) === false)) {
        $reject('Nothing was saved. The support e-mail address is not an e-mail address: type it as name@example.org, or leave the box empty to show none.');
    }

    $upsert($db, PortalAddress::SETTING, $checked['address']);
    if ($supportSent === true) {
        $upsert($db, 'portal.support.email', $supportEmail);
    }
    PortalAddress::forget();

    // The address is not a secret (it is in every link the portal sends); the
    // support address is somebody's mailbox, so the record says only whether
    // one is set. The account is passed in: the activity log records WHO only
    // when it is told, and who changed where every e-mailed link points is
    // the question somebody will ask of this record.
    Logger::activity(
        'PortalAddressSaved',
        'Web addresses saved. The portal\'s address: ' . ($checked['address'] === '' ? 'cleared (not set)' : $checked['address'])
            . '. The support e-mail address: ' . ($supportSent === false ? 'not changed' : ($supportEmail === '' ? 'cleared' : 'set')) . '.',
        isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null
    );

    $message = 'Web addresses saved.';
    $type    = 'success';
    if ($checked['address'] === '') {
        $message .= ' The portal\'s address is not set, so links in e-mails will use the address of whatever request causes the e-mail.';
        $type     = 'warning';
    } elseif (str_starts_with($checked['address'], 'http://') === true) {
        $message .= ' This address begins with http, not https: what people send to it is not encrypted. Use https for a real installation.';
        $type     = 'warning';
    }
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $redirect . '#web-addresses', true, 302);
    exit();
}

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
