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
 * The organisation's COUNTRY is its own choice, starting from the
 * installation's (#942, D-133): see "THE ORGANISATION'S OWN COUNTRY" below.
 * It used to be stored as 'GB' whenever the box was left empty.
 *
 * Normalises the address via `GeoLocation::normaliseAddress()`, validates
 * coords/W3W (invalid non-empty W3W is rejected with a flash — nothing
 * saved; empty is fine), applies the W3W dual-mode verify/fill contract
 * (§A1: API off/unavailable never blocks a save), and best-effort forward-
 * geocodes when `Geocoder::autoEnabled()` is on and coords are still empty.
 *
 * WHO MAY SAVE WHAT, AND AT WHICH LEVEL (#677, 30 October 2026)
 * -----------------------------------------------------------------------------
 * This handler now writes at TWO levels, and every call to $upsert() says
 * which one it means.
 *
 *   THE ORGANISATION'S OWN ROW (siteID = Site::id())
 *     org.address.line1 / line2 / city / region / postcode / countryCode,
 *     org.latitude, org.longitude, org.what3words, portal.support.email.
 *     Any administrator of the organisation may set these: they are that
 *     organisation's own data and nobody else's.
 *
 *   THE INSTALLATION'S ROW (siteID NULL)
 *     site.url alone — the portal's own web address, which decides where
 *     every e-mailed link points (#500). A global administrator only.
 *
 * WHAT IT USED TO DO, because the reasoning is worth keeping. Every setting
 * here was written with nothing in siteID, so one organisation's postal
 * address WAS every organisation's, on a page called Organisation settings.
 * Until 11 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a single organisation too, so any one of them could
 * overwrite what every organisation showed. The answer then was to reserve the
 * whole page for a global administrator — safe, but the wrong way round, and
 * this file's header said so and asked for a decision. The owner gave it on
 * 30 October 2026: the address belongs to each organisation, migration 231
 * copies the existing installation-wide values down, and the global-
 * administrator gate narrows to the one setting that really is shared.
 *
 * BE PRECISE ABOUT WHAT THE OLD BEHAVIOUR COST, because #677 overstated it and
 * measuring corrected it. The issue said a second organisation's pages would
 * display the first's address. They would not have: searched on 30 October
 * 2026, NOTHING outside this page reads any of the nine org.* keys — not a
 * member page, not a public page, not an e-mail. GivingRetention only mentions
 * org.address.countryCode in a comment explaining why it does not read it. The
 * support e-mail WAS read, by Mailer, and did appear in every organisation's
 * e-mail footer. So the real cost was an address stored at the wrong level, a
 * Save button that lied, and a fault that would have become a leak the moment
 * anything read it.
 *
 * THE GATE ASKS WHAT WAS SENT, NOT WHO IS ASKING ALONE. See
 * $installationWideFieldWasSent() below: the page disables the portal-address
 * box for anybody who may not change it, and a disabled control is not
 * submitted, so reading its value would see "empty" and clear the setting for
 * everybody.
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
use Portal\Core\Countries;
use Portal\Core\Currency;
use Portal\Core\Geocoder;
use Portal\Core\GeoLocation;
use Portal\Core\Logger;
use Portal\Core\PortalAddress;
use Portal\Core\Router;
use Portal\Core\Site;
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

$redirect = Site::url('admin/settings/organisation');

$reject = static function (string $message) use ($redirect): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect, true, 302);
    exit();
};

// 🚧 Does this request try to change something INSTALLATION-WIDE?
//
//    Since #677 this page writes at two levels. The postal address, the map
//    position, the what3words address and the support e-mail all belong to the
//    organisation being administered, so an ordinary administrator may set
//    them — they are that organisation's own data and nobody else's. The only
//    thing left that every organisation shares is the portal's own web address
//    (`site.url`), which decides where every e-mailed link points, and that
//    stays with a global administrator (#500).
//
//    IT ASKS WHETHER THE FIELD WAS SENT, NOT WHAT IT CONTAINS. The page
//    disables that box for anybody who may not change it, and **a disabled
//    control is not submitted at all** — so for an ordinary administrator the
//    field is simply absent, this answers false, and the save goes ahead with
//    the fields that are theirs. If it instead read the value, an absent box
//    would read as empty, which `PortalAddress::check()` accepts as "clear
//    it", and an ordinary administrator pressing Save would have WIPED the
//    portal's address for every organisation. That is not hypothetical: it is
//    the shape of #667, where a disabled control silently changed a payment
//    provider.
//
//    The dashboard's one-press "use this address" button sends `portalAddress`
//    alone, so it is correctly treated as an installation-wide change.
$installationWideFieldWasSent = static function (): bool {
    return ($_POST['form'] ?? '') === 'web-addresses'
        && array_key_exists('portalAddress', $_POST) === true;
};

// 🚧 Installation-wide settings are for a global administrator only — see "WHO
//    MAY SAVE HERE" in the file header. The refusal gives the reason rather than a
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
if (App::isRootAdmin() === false && $installationWideFieldWasSent() === true) {
    Logger::activity(
        'SettingsGroupSaveRefused',
        'Refused: portal-wide settings group "organisation" may only be changed by a global administrator',
        $_SESSION['user_id'] ?? null
    );
    $reject(
        'The address of the portal is one setting for the whole installation: it applies to every '
            . 'organisation on this installation, not only yours, so only a global '
            . 'administrator can change it. Nothing was saved. Everything else on '
            . 'this page — your postal address, map position, what3words address '
            . 'and support e-mail address — belongs to your own organisation and '
            . 'you can change it here.'
    );
}

// 💾 Save ONE setting, at a level the caller must name: change the row that is
//    there, or add it.
//
//    THE LEVEL IS AN ARGUMENT AND HAS NO DEFAULT (#677). It used to be
//    hard-coded to `siteID IS NULL` — the row every organisation uses — and
//    every one of the nine postal-address keys went in that way, on a page
//    called Organisation settings. A default here would be the same mistake
//    waiting to be repeated by the next caller, so each call says which level
//    it means and a reader can see it without going looking.
//
//      $siteId = null  — the INSTALLATION's row, used by every organisation
//                        that has none of its own. Only `site.url`.
//      $siteId = int   — that organisation's own row.
//
//    `siteID IS NULL` and `siteID = ?` cannot be written as one condition,
//    because MySQL never treats one empty value as equal to another: `siteID =
//    NULL` matches nothing at all. That is the same reason tblSettings carries
//    the derived `siteScope` column for its unique key (see migration 187).
$db = App::db();
$upsert = static function (mysqli $db, string $key, string $value, ?int $siteId): void {
    $find = $siteId === null
        ? 'SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1'
        : 'SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID = ? LIMIT 1';
    $stmt = $db->prepare($find);
    if ($stmt === false) {
        return;
    }
    if ($siteId === null) {
        $stmt->bind_param('s', $key);
    } else {
        $stmt->bind_param('si', $key, $siteId);
    }
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

        return;
    }
    $u = $db->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) VALUES (?, ?, 0, ?, NOW())');
    if ($u !== false) {
        $u->bind_param('ssi', $key, $value, $siteId);
        $u->execute();
        $u->close();
    }
};

// 🏠 Which organisation this page is being used for. Read once, so the whole
//    handler cannot disagree with itself if the session changed under it.
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 🏷️ The "label amounts with no currency" form (#966, D-139). It is handled
//    here and stops. The administrator chooses the currency; nothing guesses
//    it, and only rows holding '' are touched (Currency::labelUnlabelled()).
// -----------------------------------------------------------------------------
if (($_POST['form'] ?? '') === 'label-currency') {
    $labelCode = Currency::fromPosted($_POST['labelCurrency'] ?? null);
    if ($labelCode === null || $labelCode === '') {
        $reject('Nothing was labelled. Choose the currency those amounts were in.');
    }
    $labelled = Currency::labelUnlabelled($db, $siteId, $labelCode);
    if ($labelled === null) {
        $reject('Nothing was labelled: the amounts could not be updated. Nothing was changed; try again.');
    }
    Logger::activity('OrgAmountsLabelled', $labelled . ' amount(s) saved with no currency were labelled ' . $labelCode . '.');
    $_SESSION['flash_msg']  = $labelled . ' amount' . ($labelled === 1 ? ' was' : 's were') . ' labelled ' . $labelCode . '.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . $redirect, true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 🌐 The "Web addresses" form (#500). See the file header: it is handled
//    here and stops, so that none of the postal-address code below runs.
// -----------------------------------------------------------------------------
if (($_POST['form'] ?? '') === 'web-addresses') {
    // A form field is text or it is nothing: a hand-made request can send a
    // list where text is expected, and a list is read as empty.
    $text = static fn (mixed $value): string => is_string($value) === true ? $value : '';

    // 🌐 The portal's address changes only when its box was SENT, for exactly
    //    the same reason as the support address below — and getting this wrong
    //    was a real fault, caught by section O of access-pages-selftest.php
    //    and not by reading the code.
    //
    //    Since #677 an ordinary administrator may use this card, for the
    //    support address alone; the portal-address box is disabled for them,
    //    and a disabled control is not submitted. The first version of that
    //    change guarded only WHO MAY SEND the field, then wrote the result
    //    unconditionally — so an ordinary administrator saving their support
    //    address sent no `portalAddress`, `?? ''` gave an empty string,
    //    PortalAddress::check() accepts empty as "no address", and the save
    //    CLEARED `site.url` FOR EVERY ORGANISATION. Every link in every
    //    e-mail would then have been built from whatever host the next
    //    request arrived at, which a visitor can choose (#500).
    //
    //    So: ask whether the field arrived, never what it contains. The
    //    dashboard's one-press button sends it alone and still works.
    $addressSent = array_key_exists('portalAddress', $_POST) === true;
    $checked     = PortalAddress::check($text($_POST['portalAddress'] ?? ''));
    if ($addressSent === true && $checked['problem'] !== '') {
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

    if ($addressSent === true) {
        $upsert($db, PortalAddress::SETTING, $checked['address'], null);
    }
    if ($supportSent === true) {
        // 🏠 The support address belongs to THE ORGANISATION now (#677, owner
        //    30 October 2026), falling back to the installation's where an
        //    organisation has not set one. Mailer reads it from the merged
        //    settings, which already does that fall-back, so nothing there
        //    changed. The fall-back is also why migration 231 leaves the
        //    installation's row alone: copying it down to everybody would
        //    mean changing it later reached nobody.
        //
        //    AN EMPTY BOX REMOVES THE ROW, so the organisation inherits again.
        //    It does NOT store an empty string, and the difference matters: a
        //    stored empty value WINS over the installation's address, because
        //    the merge only falls back when there is no row at all. So an
        //    organisation that had ever cleared this box would have masked the
        //    installation's address for ever, with no way back from this page
        //    — which is not "falling back to the installation's" at all. Found
        //    by the independent check of 30 October 2026, which traced it
        //    through a real failing assertion in portal-address-selftest.php.
        if ($supportEmail === '') {
            $drop = $db->prepare('DELETE FROM tblSettings WHERE settingKey = ? AND siteID = ?');
            if ($drop !== false) {
                $key = 'portal.support.email';
                $drop->bind_param('si', $key, $siteId);
                $drop->execute();
                $drop->close();
            }
        } else {
            $upsert($db, 'portal.support.email', $supportEmail, $siteId);
        }
    }
    PortalAddress::forget();

    // The address is not a secret (it is in every link the portal sends); the
    // support address is somebody's mailbox, so the record says only whether
    // one is set. The account is passed in: the activity log records WHO only
    // when it is told, and who changed where every e-mailed link points is
    // the question somebody will ask of this record.
    Logger::activity(
        'PortalAddressSaved',
        'Web addresses saved. The portal\'s address: '
            . ($addressSent === false
                ? 'not changed'
                : ($checked['address'] === '' ? 'cleared (not set)' : $checked['address']))
            . '. The support e-mail address: ' . ($supportSent === false ? 'not changed' : ($supportEmail === '' ? 'cleared' : 'set')) . '.',
        isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null
    );

    $message = 'Web addresses saved.';
    $type    = 'success';
    if ($addressSent === false) {
        // Nothing to say about an address this request never carried. Saying
        // "the portal's address is not set" here would be untrue for anybody
        // whose Save simply did not include that box.
        $message = 'Saved.';
    } elseif ($checked['address'] === '') {
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

// 🌍 THE ORGANISATION'S OWN COUNTRY (#942, D-133). Each organisation may be
//    in a different country, and chooses its own starting from the
//    installation's (asked by the installer).
//
//    IT USED TO BE 'GB' WHENEVER THE BOX WAS EMPTY: normaliseAddress() turned
//    a missing country into 'GB' and this handler stored that as the
//    organisation's own, so an organisation that never looked at the box was
//    British. Now the box is a list, and:
//
//      - "Same as the installation" (an empty value) REMOVES the
//        organisation's own row, so it follows the installation's country —
//        and follows it again if the installation's ever changes. It does not
//        store an empty string: a stored row wins over the installation's in
//        the merged settings, so an empty one would hide it (the support
//        e-mail address below works the same way, #677);
//      - a country on the portal's list (Countries) is stored as the
//        organisation's own;
//      - the organisation's OWN stored code, unchanged, is accepted even when
//        it is not on the list (only hand-written SQL can put one there), so
//        the address can still be saved; the page offers that code as a
//        choice for the same reason;
//      - anything else is refused with the reason, BEFORE anything is
//        written, so a refusal changes nothing.
//
//    IT ASKS WHETHER THE FIELD ARRIVED (#677): a request without it leaves
//    the country exactly as it was.
$countrySent      = array_key_exists('countryCode', $_POST) === true;
// The organisation's own row AS STORED: two letters off the list ("QQ"), or
// text that is not a code at all ("GBR"), is accepted back unchanged, because
// the page offers it back selected (see the page for why).
$countryOwnBefore = Countries::ownStoredText($siteId);
$countryChosen    = null;
if ($countrySent === true) {
    $countryChosen = Countries::fromPosted($_POST['countryCode']);
    if ($countryChosen === null && $countryOwnBefore !== '' && is_string($_POST['countryCode']) === true
        && trim($_POST['countryCode']) === $countryOwnBefore
    ) {
        $countryChosen = $countryOwnBefore;
    }
    if ($countryChosen === null) {
        $reject('Nothing was saved. The country chosen is not one the portal knows. Choose one from the list, or "Same as the installation".');
    }
}
// 💷 THE CURRENCY (#966, D-133), by the country's rules above:
//    - it is touched only when the field ARRIVED (#677): a Save from a form
//      that did not draw the box must leave the currency alone;
//    - "Same as the installation" ('') deletes the organisation's own row;
//    - a currency the portal can use is stored as the organisation's own;
//    - text that is not one, sent back exactly as the row holds it, is kept
//      unchanged; anything else is REFUSED, before anything is written.
//    A change applies to new records only: every amount keeps the currency it
//    was recorded in (D-139), so nothing is relabelled and there is no lock.
$currencySent      = array_key_exists('currency', $_POST) === true;
$currencyOwnBefore = Currency::ownStoredText($siteId);
$currencyChosen    = null;
if ($currencySent === true) {
    $currencyChosen = Currency::fromPosted($_POST['currency']);
    if ($currencyChosen === null && $currencyOwnBefore !== '' && is_string($_POST['currency']) === true
        && trim($_POST['currency']) === $currencyOwnBefore
    ) {
        $currencyChosen = $currencyOwnBefore;
    }
    if ($currencyChosen === null) {
        $reject('Nothing was saved. The currency chosen is not one the portal can use. Choose one from the list, or "Same as the installation".');
    }
}
// The country the map look-up is told about. Empty means "no hint", never a
// guess.
//   - A two-letter code just chosen: that code.
//   - "Same as the installation": the installation's (the organisation's own
//     row is about to go).
//   - Anything else, including the stored text accepted back unchanged:
//     Countries::ofOrganisation(), which IGNORES text that is not a two-letter
//     code. Passing the chosen value through instead sent "GBR" to the
//     geocoder as `countrycodes=GBR` (round 3 of #942's check): an ignored
//     value has to be ignored here too.
if ($countryChosen !== null && preg_match('/^[A-Z]{2}$/', $countryChosen) === 1) {
    $countryForLookup = $countryChosen;
} elseif ($countryChosen === '') {
    $countryForLookup = Countries::ofInstallation();
} else {
    $countryForLookup = Countries::ofOrganisation($siteId);
}

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
        $geocoded = Geocoder::forward($formatted, $countryForLookup !== '' ? $countryForLookup : null);
        if ($geocoded !== null) {
            $coords = ['lat' => $geocoded['lat'], 'lng' => $geocoded['lng']];
        }
    }
}

// 🔒 Everything below is written in ONE transaction (#966), so a failure part
//    way leaves the organisation's settings as they were rather than half
//    saved.
$db->begin_transaction();
try {
if ($currencyChosen !== null && $currencyChosen !== $currencyOwnBefore) {
    if ($currencyChosen === '') {
        $dropCurrency = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
        if ($dropCurrency !== false) {
            $currencyKey = Currency::SETTING;
            $dropCurrency->bind_param('si', $currencyKey, $siteId);
            $dropCurrency->execute();
            $dropCurrency->close();
        }
    } else {
        $upsert($db, Currency::SETTING, $currencyChosen, $siteId);
    }
}
$upsert($db, 'org.address.line1', (string) ($address['line1'] ?? ''), $siteId);
$upsert($db, 'org.address.line2', (string) ($address['line2'] ?? ''), $siteId);
$upsert($db, 'org.address.city', (string) ($address['city'] ?? ''), $siteId);
$upsert($db, 'org.address.region', (string) ($address['region'] ?? ''), $siteId);
$upsert($db, 'org.address.postcode', (string) ($address['postcode'] ?? ''), $siteId);
if ($countryChosen === '') {
    // "Same as the installation": remove this organisation's own row, so it
    // follows the installation's country (see the note above).
    $dropCountry = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
    if ($dropCountry !== false) {
        $countryKey = Countries::SETTING;
        $dropCountry->bind_param('si', $countryKey, $siteId);
        $dropCountry->execute();
        $dropCountry->close();
    }
} elseif ($countryChosen !== null) {
    $upsert($db, Countries::SETTING, $countryChosen, $siteId);
}
// 📍 A coordinate is stored as a PLAIN decimal (#935). `(string)` on a float writes
//    anything under 0.0001 with an exponent ('4.0E-5'), which is not what a person or
//    most readers expect of a latitude; the Sabbath window's first reader refused it
//    and silently used the fixed times. Seven places (the precision validateCoords()
//    rounds to), trailing zeros trimmed, and never "-0".
$plainCoordinate = static function (float $value): string {
    $text = rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');

    return $text === '-0' ? '0' : $text;
};
$upsert($db, 'org.latitude', $coords !== null ? $plainCoordinate((float) $coords['lat']) : '', $siteId);
$upsert($db, 'org.longitude', $coords !== null ? $plainCoordinate((float) $coords['lng']) : '', $siteId);
$upsert($db, 'org.what3words', $w3w ?? '', $siteId);
$db->commit();
} catch (\Throwable $saveFault) {
    // Nothing half-written: put it all back and let the fault be reported
    // as before (the error handler records it).
    $db->rollback();
    throw $saveFault;
}

Logger::activity(
    'OrgAddressSaved',
    'Organisation address updated. Country: '
        . ($countryChosen === null ? 'not changed' : ($countryChosen === '' ? 'same as the installation' : $countryChosen))
        . '. Currency: '
        . ($currencyChosen === null || $currencyChosen === $currencyOwnBefore ? 'not changed'
            : ($currencyChosen === '' ? 'same as the installation' : $currencyChosen))
        . '.'
);

$_SESSION['flash_msg']  = $warning !== null ? ('Address saved. ' . $warning) : 'Organisation address saved.';
$_SESSION['flash_type'] = $warning !== null ? 'warning' : 'success';
header('Location: ' . $redirect, true, 302);
exit();
