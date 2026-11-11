<?php
// Path: _apps/admin/settings/organisation/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Organisation / Site HQ address 🏠 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * The site-wide "where is this organisation" address — feeds public pages
 * / feeds / branding that need a physical HQ location. Stored as nine
 * global (`siteID IS NULL`) `org.*` settings keys (the `portal.sabbath.
 * location_lat/lng` precedent) rather than a `tblSites` column, so a
 * multi-site install gets free per-site overrides via the existing
 * `App::settingForSite()` pattern if ever needed later.
 *
 * THE "WEB ADDRESSES" CARD (#500) is the second form on this page: the
 * portal's own web address (the `site.url` setting, read and judged only by
 * Portal\Core\PortalAddress) and the support e-mail address. Both are for
 * the whole installation. Anybody who may open this page sees them; only a
 * global administrator is shown a Save button, because the save handler
 * refuses anybody else and a button that can only be refused is no button.
 * (The postal address form above it still shows its button to everybody;
 * that is older and was left alone.)
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
use Portal\Core\InstallationSettings;
use Portal\Core\Auth;
use Portal\Core\Countries;
use Portal\Core\Currency;
use Portal\Core\PortalAddress;
use Portal\Core\Router;
use Portal\Core\Site;

require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-input.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-display.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-map-assets.php';

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$values = [
    'line1'       => (string) (App::settings('org.address.line1') ?? ''),
    'line2'       => (string) (App::settings('org.address.line2') ?? ''),
    'city'        => (string) (App::settings('org.address.city') ?? ''),
    'region'      => (string) (App::settings('org.address.region') ?? ''),
    'postcode'    => (string) (App::settings('org.address.postcode') ?? ''),
    // 🌍 The country this organisation IS in (its own, else the installation's),
    //    for the preview below only. The country BOX is drawn separately and
    //    reads the organisation's OWN row: see $countryOwn.
    'countryCode' => Countries::ofOrganisation(Site::id()),
    'lat'         => (string) (App::settings('org.latitude') ?? ''),
    'lng'         => (string) (App::settings('org.longitude') ?? ''),
    'w3w'         => (string) (App::settings('org.what3words') ?? ''),
];
$hasCoords = $values['lat'] !== '' && $values['lng'] !== '';

// 🌍 THE COUNTRY BOX (#942, D-133). Each organisation chooses its own country,
//    starting from the installation's (which the installer asked for).
//
//    The box shows ONLY this organisation's own choice, never the merged
//    value, for the reason the support e-mail box below gives (#674, #677):
//    the merged value is the installation's where the organisation has none,
//    and a Save would then copy it into the organisation's own row, so a later
//    change to the installation's country would reach this organisation never
//    again. "Same as the installation" is the first choice and is what an
//    organisation that has not chosen sees selected.
//
//    It used to show the merged value, or 'GB' when there was none, for every
//    customer anywhere — so pressing Save made the organisation British.
$countryOwn          = Countries::ownOf(Site::id());
$countryInstallation = Countries::ofInstallation();
$countryChoices      = Countries::choices();
// What the organisation's own row HOLDS. Usually the same as $countryOwn; it
// differs only for text that is not two capital letters (say "GBR", typed into
// the general Settings editor), which counts as "not chosen" everywhere and so
// the installation's country is in force.
$countryOwnText      = Countries::ownStoredText(Site::id());
$countryOwnUnusable  = $countryOwnText !== '' && $countryOwn === '';
// Text the organisation holds that is not on the list is offered as a choice
// too, and selected. Without it NO option would carry `selected`, or the wrong
// one would, and a select with none selected is a vote for the first option
// (#763) — here "Same as the installation", so the next Save would quietly
// delete the organisation's own row with nobody told. The save handler accepts
// the stored text back unchanged, so the rest of the address can still be saved.
$countrySelected     = $countryOwnText !== '' ? $countryOwnText : '';
if ($countrySelected !== '' && isset($countryChoices[$countrySelected]) === false) {
    $countryChoices = [$countrySelected => $countrySelected . ' (not on the portal\'s list)'] + $countryChoices;
}
$countryInForce = $countryOwn !== '' ? $countryOwn : $countryInstallation;

// 🕰️ A value that follows from the country: the organisation's TIME ZONE
//    (D-133: pre-selected from the country, never forced). It is kept on the
//    organisation's own row and changed at Admin, Organisations (#604), so
//    this page does not change it: it says when the two do not agree, and
//    which zone the country would suggest, and leaves the decision to whoever
//    sets the zone.
$zoneNow       = Site::timezone(Site::id());
$zoneCountry   = Countries::ofZone($zoneNow);
$zoneSuggested = $countryInForce !== '' ? Countries::onlyZone($countryInForce) : '';
$zoneAdvice    = '';
if ($countryInForce !== '' && $zoneCountry !== $countryInForce
    && in_array($zoneNow, Countries::zonesOf($countryInForce), true) === false
) {
    $zoneAdvice = 'This organisation\'s time zone is ' . $zoneNow . ', which is not in '
        . Countries::name($countryInForce) . '.'
        . ($zoneSuggested !== '' ? ' ' . Countries::name($countryInForce) . ' uses ' . $zoneSuggested . '.' : '')
        . ' If the time zone is wrong, ' . (App::isUmbrellaAdmin() === true ? 'change it' : 'ask a global administrator to change it')
        . ' at Admin, Organisations; times on every page follow it.';
}

// 💷 THE CURRENCY BOX (#966, D-133). One currency per organisation: its own
//    when it has chosen one, else the installation's, else none (amounts are
//    then plain numbers, never pounds).
//
//    The box shows the organisation's OWN row (#674): showing the currency in
//    force would copy the installation's into this organisation's own row on
//    a Save that touched nothing. It is pre-selected from the country (D-133)
//    ONLY when no currency is in force at all — the setting-up case.
//    Otherwise the page ADVISES when the currency and the country disagree,
//    as it does for the time zone.
//
//    THE CURRENCY CAN CHANGE (D-139). Every amount keeps the currency it was
//    recorded in, so a change applies to new records only and relabels
//    nothing. (Round 1 of #966 locked it once money was recorded; the owner
//    chose per-record currencies instead, and the lock is gone.)
//
//    AMOUNTS WITH NO CURRENCY. A row saved while no currency was chosen, or
//    restored from a backup older than migration 283, holds ''. The page
//    counts them and offers to label them with a currency the administrator
//    CHOOSES (Currency::labelUnlabelled()): only a person knows what money an
//    old figure was, so nothing is guessed.
$currencyOwn          = Currency::ownOf(Site::id());
$currencyInstallation = Currency::ofInstallation();
$currencyOwnText      = Currency::ownStoredText(Site::id());
$currencyOwnUnusable  = $currencyOwnText !== '' && $currencyOwn === '';
$currencyInForce      = $currencyOwn !== '' ? $currencyOwn : $currencyInstallation;
$currencyChoices      = Currency::choices();
$currencySelected     = $currencyOwnText;
if ($currencySelected !== '' && isset($currencyChoices[$currencySelected]) === false) {
    $currencyChoices = [$currencySelected => $currencySelected . ' (not a currency the portal can use)'] + $currencyChoices;
}
$currencyCountryUses  = $countryInForce !== '' ? Currency::ofCountry($countryInForce) : '';
$currencySuggested    = $countryInForce !== '' ? Currency::suggestFor($countryInForce) : '';
$currencyPreselected  = false;
if ($currencyInForce === '' && $currencySelected === '' && $currencySuggested !== '') {
    $currencySelected    = $currencySuggested;
    $currencyPreselected = true;
}
$currencyUnlabelled = Currency::unlabelledCount($mysqli, Site::id());
$currencyAdvice = '';
if ($currencyCountryUses !== '' && $currencySuggested === '') {
    $currencyAdvice = Countries::name($countryInForce) . ' uses ' . $currencyCountryUses . '. '
        . Currency::whyNotChoosable($currencyCountryUses);
} elseif ($currencySuggested !== '' && $currencyInForce !== '' && $currencyInForce !== $currencySuggested) {
    $currencyAdvice = 'This organisation\'s currency is ' . $currencyInForce . ', and '
        . Countries::name($countryInForce) . ' uses ' . $currencySuggested . '.';
}

// 🌐 The "Web addresses" card. Everything about the portal's address comes
//    from PortalAddress, so this card, the dashboard and every e-mailed link
//    go by one answer.
$mayChangeAddresses = App::isRootAdmin();
$addressState       = PortalAddress::state();
$addressSaved       = PortalAddress::saved();
$addressInUse       = PortalAddress::fromRequest();
$addressForHere     = PortalAddress::base();
$ownAddressEach     = Site::isMultisiteEnabled() === true && Site::detectionMode() === Site::MODE_HOST;
// #903: the passkey name an administrator set, if any, so the help under the
// address box says what passkeys really follow.
$passkeyName        = \Portal\Core\PasskeyAddress::nameSetByAdministrator();
// 📧 The support e-mail address, read from THIS ORGANISATION'S OWN ROW — and
//    deliberately NOT from the merged settings.
//
//    WHY, because reading the merged value here looks right and is the #674
//    fault mirrored. The merged value is the organisation's own where it has
//    one and the INSTALLATION'S where it has not. Save writes the
//    organisation's row. So an organisation with no row of its own would open
//    the card, see the installation's address sitting in the box, press Save
//    without touching anything — and now hold its own copy of it. The hosting
//    provider changes the installation's address later and it reaches that
//    organisation never again. One press of Save per organisation produces
//    exactly the outcome migration 231 refuses to cause by copying rows down.
//    Found by the independent check of 30 October 2026, not by reading.
//
//    So the BOX holds only what this organisation has set, and what it would
//    inherit is shown beside it as words. An empty box therefore means
//    "inherit", not "show none" — see the handler, which deletes the row.
$supportOwnRow = (static function (): ?string {
    $stmt = App::db()->prepare('SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = ? LIMIT 1');
    if ($stmt === false) {
        return null;
    }
    $key  = 'portal.support.email';
    $site = Site::id();
    $stmt->bind_param('si', $key, $site);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? null : trim((string) $row['settingValue']);
})();
$supportEmail       = $supportOwnRow ?? '';
// What this organisation would fall back to.
//
//    THROUGH InstallationSettings::layer(), NOT `$SETTINGS_INSTALLATION`. The
//    settings loader does keep that array beside `$SETTINGS` — but **a page
//    inherits only `$mysqli` and `$SETTINGS`** and nothing else, which is the
//    standing rule in `.claude/rules/app-pages.md`. So reading it here gave
//    `null` every time, the help text silently took its "there is nothing to
//    fall back to" branch, and the page told administrators the opposite of
//    the truth. It looked right in review and was caught by a failing check in
//    section O of access-pages-selftest.php — which is the only reason this
//    comment can be specific.
$supportInherited   = trim((string) (InstallationSettings::layer()['portal']['support']['email'] ?? ''));

// 🔒 ONE lock, on ONE of the two boxes, because this card now holds settings at
//    two levels (#677). The portal's own web address is installation-wide and
//    decides where every e-mailed link points, so it stays with a global
//    administrator (#500). The support e-mail address belongs to the
//    organisation, so any administrator of it may set it and its box is never
//    locked — there is deliberately no second variable for that, because a
//    constant empty string reads like a switch somebody could turn.
//
//    Remember that **a disabled control is not submitted at all**, which is
//    what makes this safe rather than merely tidy: an ordinary administrator's
//    Save carries no `portalAddress` field, the handler sees it was not sent
//    and leaves `site.url` alone. Were it merely read-only instead of
//    disabled, the browser would send the value back and the handler would
//    rewrite a setting that person may not change.
$addressesLocked    = $mayChangeAddresses === false ? ' disabled' : '';
$e                  = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

// 🗺️ Preview map — only when coords are already set (opt-in page-scoped
//    CSP widening, #386 precedent).
if ($hasCoords === true) {
    $cspImgExtra = 'https://*.tile.openstreetmap.org';
}

$pageTitle   = 'Organisation Address';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Settings' => Site::url('admin/settings'), 'Organisation' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-house-chimney me-2"></i>Organisation Address</h1>
<p class="text-secondary">
    <?php /* This said the address "feeds public pages, feeds, and branding ... for this install".
              Both halves were wrong by #677: searched on 30 October 2026, NOTHING outside this page
              reads any of the nine `org.*` keys, and the address belongs to this organisation rather
              than to the installation. Said plainly instead, so nobody fills it in expecting it to
              appear somewhere. */ ?>
    This organisation's postal address and map position. They are recorded here for its own
    records; nothing in the portal displays them to members yet.
</p>

<div class="card mb-4">
    <div class="card-body">
        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/settings/organisation/save'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <?php portal_location_input([
                'values'      => $values,
                'compact'     => false,
                'lookup'      => true,
                'w3wSuggest'  => true,
                // 🌍 The country is a list drawn just below, not the shared
                //    form's two-letter box (#942).
                'showCountry' => false,
            ]); ?>
            <div class="row g-3 mt-0">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="countryCode"><?php echo $e(t('location.country')); ?></label>
                    <select class="form-select" id="countryCode" name="countryCode" aria-describedby="countryHelp">
                        <option value=""<?php echo $countrySelected === '' ? ' selected' : ''; ?>><?php echo $e($countryInstallation !== ''
                            ? 'Same as the installation: ' . Countries::name($countryInstallation)
                            : 'Same as the installation (none chosen yet)'); ?></option>
                        <?php foreach ($countryChoices as $code => $countryName): ?>
                            <option value="<?php echo $e((string) $code); ?>"<?php echo (string) $code === $countrySelected ? ' selected' : ''; ?>><?php echo $e($countryName); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="countryHelp">
                        <?php if ($countryInstallation !== ''): ?>
                            Each organisation can be in its own country. &ldquo;Same as the installation&rdquo; follows the
                            installation&rsquo;s country, <?php echo $e(Countries::name($countryInstallation)); ?>, and goes on
                            following it if that changes.
                        <?php else: ?>
                            Each organisation can be in its own country. No country was chosen for the whole installation, so
                            &ldquo;Same as the installation&rdquo; means no country is known: new venues then need one typed in.
                        <?php endif; ?>
                    </div>
                    <?php if ($countryOwnUnusable === true): ?>
                        <div class="alert alert-warning small mt-2 mb-0" role="note" data-country-unusable><?php echo $e(
                            'This organisation\'s country is stored as "' . $countryOwnText . '", which is not a two-letter country code, '
                            . 'so the portal ignores it and uses '
                            . ($countryInstallation !== '' ? 'the installation\'s country, ' . Countries::name($countryInstallation) : 'no country at all')
                            . '. Choose a country from the list and save to put it right. Saving with it unchanged keeps it as it is.'
                        ); ?></div>
                    <?php endif; ?>
                    <?php if ($zoneAdvice !== ''): ?>
                        <div class="alert alert-warning small mt-2 mb-0" role="note"><?php echo $e($zoneAdvice); ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="currency">Currency</label>
                    <select class="form-select" id="currency" name="currency" aria-describedby="currencyHelp">
                        <option value=""<?php echo $currencySelected === '' ? ' selected' : ''; ?>><?php echo $e($currencyInstallation !== ''
                            ? 'Same as the installation: ' . $currencyInstallation
                            : 'Same as the installation (none chosen)'); ?></option>
                        <?php foreach ($currencyChoices as $code => $currencyName): ?>
                            <option value="<?php echo $e((string) $code); ?>"<?php echo (string) $code === $currencySelected ? ' selected' : ''; ?>><?php echo $e($currencyName); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="currencyHelp">
                        Every amount this organisation records, shows and takes in payments is in this currency.
                        <?php if ($currencyPreselected === true): ?>
                            Suggested from the country, <?php echo $e(Countries::name($countryInForce)); ?>: <strong>check it, and save to keep it.</strong>
                        <?php elseif ($currencyInForce === ''): ?>
                            No currency is chosen yet, so amounts are shown as plain numbers and no payment can be taken.
                        <?php endif; ?>
                        Changing it affects new records only: every amount already recorded keeps the currency it was recorded in.
                    </div>
                    <?php if ($currencyOwnUnusable === true): ?>
                        <div class="alert alert-warning small mt-2 mb-0" role="note"><?php echo $e(
                            'This organisation\'s currency is stored as "' . $currencyOwnText . '", which is not a currency the portal can use, '
                            . 'so ' . ($currencyInstallation !== '' ? 'the installation\'s, ' . $currencyInstallation . ',' : 'no currency') . ' is used instead.'
                        ); ?></div>
                    <?php endif; ?>
                    <?php if ($currencyAdvice !== ''): ?>
                        <div class="alert alert-warning small mt-2 mb-0" role="note" data-currency-advice><?php echo $e($currencyAdvice); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save address</button>
            </div>
        </form>
        <?php if ($currencyUnlabelled === null): ?>
            <div class="alert alert-warning small mt-3 mb-0" role="note" data-currency-unlabelled="unknown">
                The portal could not count whether any amounts were saved with no currency. Nothing has been changed; try again later.
            </div>
        <?php elseif ($currencyUnlabelled > 0): ?>
            <?php // 🏷️ A separate small form, so labelling is a deliberate act of its own (#966, D-139). ?>
            <form method="post" action="<?php echo $e(Site::url('admin/settings/organisation/save')); ?>" class="border rounded p-3 mt-3" data-currency-unlabelled="<?php echo (int) $currencyUnlabelled; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="form" value="label-currency">
                <p class="small mb-2"><strong><?php echo (int) $currencyUnlabelled; ?> amount<?php echo $currencyUnlabelled === 1 ? ' was' : 's were'; ?> saved with no currency</strong>
                    (before a currency was chosen, or from an older backup). They are shown as plain numbers and counted on their own in every total.
                    If you know what money they were, choose it here. This labels only those amounts; nothing that already has a currency changes, and it cannot be undone from this page.</p>
                <div class="d-flex gap-2 align-items-end flex-wrap">
                    <div>
                        <label class="form-label small" for="labelCurrency">They were in</label>
                        <select class="form-select form-select-sm" id="labelCurrency" name="labelCurrency" required>
                            <option value="" selected>Choose a currency</option>
                            <?php foreach (Currency::choices() as $code => $currencyName): ?>
                                <option value="<?php echo $e((string) $code); ?>"><?php echo $e($currencyName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Label them</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($hasCoords === true): ?>
    <div class="card mb-4">
        <div class="card-header">Preview</div>
        <div class="card-body">
            <?php portal_location_display([
                'address' => $values,
                'lat'     => (float) $values['lat'],
                'lng'     => (float) $values['lng'],
                'w3w'     => $values['w3w'] !== '' ? $values['w3w'] : null,
                'showMap' => true,
                'mapId'   => 'orgAddressMap',
            ]); ?>
        </div>
    </div>
<?php endif; ?>

<!-- 🌐 Web addresses (#500). One column on a phone, two from a large screen up;
     the long example addresses may break across lines (text-break) so nothing
     runs off the side of a narrow screen. -->
<div class="card mb-4" id="web-addresses">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-globe me-2"></i>Web addresses</h2>
        <p class="text-secondary small">
            <?php /* This said "These two are for the whole installation: every organisation on it
                      uses them." Since #677 that is true of the portal's address and FALSE of the
                      support address, which belongs to this organisation — and the old sentence sat
                      a few lines above the new note saying the opposite. */ ?>
            The address of the portal is for the whole installation: every organisation on it uses it.
            The support e-mail address is this organisation's own.
        </p>

        <?php if ($addressState === PortalAddress::STATE_UNUSABLE): ?>
            <div class="alert alert-warning small" role="alert">
                What is stored as the portal's address is not a usable web address, so the portal is ignoring it.
                Type the address again below and save.
            </div>
        <?php elseif ($addressState === PortalAddress::STATE_UNREADABLE): ?>
            <div class="alert alert-danger small" role="alert">
                The portal's address could not be read from the database just now, so e-mails are being sent without links.
                Try this page again; if it keeps happening, look at Admin, Errors.
            </div>
        <?php elseif ($addressState === PortalAddress::STATE_SET && $addressInUse !== '' && $addressForHere !== $addressInUse): ?>
            <div class="alert alert-warning small text-break" role="alert">
                Links in e-mails begin with <code><?php echo $e($addressForHere); ?></code>,
                but you are using the portal at <code><?php echo $e($addressInUse); ?></code> right now.
                If the second one is right,
                <?php echo $mayChangeAddresses === true ? 'correct the address below' : 'ask a global administrator to correct the address below'; ?><?php echo $ownAddressEach === true ? ', or this organisation\'s own address at Admin, Organisations' : ''; ?>.
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/settings/organisation/save'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="form" value="web-addresses">
            <div class="row g-3">
                <div class="col-12 col-lg-6">
                    <label for="portalAddress" class="form-label">The address of this portal</label>
                    <input type="text" inputmode="url" class="form-control" id="portalAddress" name="portalAddress"
                           maxlength="255" autocomplete="off" autocapitalize="none" spellcheck="false"
                           value="<?php echo $e($addressSaved); ?>" aria-describedby="portalAddressHelp"<?php echo $addressesLocked; ?>>
                    <div id="portalAddressHelp" class="form-text text-break">
                        The address people type to reach this portal, with nothing after the name:
                        for example <code>https://portal.example.org</code>.
                        Every link in an e-mail the portal sends begins with it.
                        <?php /* #903: what passkeys are tied to (Portal\Core\PasskeyAddress). Said here
                                 because this box is where a change that strands them is made — but ONLY
                                 when passkeys really follow this box. They do not when an administrator
                                 has set a passkey name of their own (`auth.webauthn.rpID`), and in "own
                                 address each" mode they follow each organisation's own name, this box
                                 giving only the http/https part and the port. The first version printed
                                 the warning unconditionally, which was untrue in both (independent check,
                                 round 1). Round 2 then found each branch's wording untrue in a case it
                                 did not name: a passkey also needs the page to be AT the portal's address
                                 exactly (the origin check compares it whole), the default organisation in
                                 "own address each" mode with no name of its own follows THIS box, and an
                                 EMPTY box means passkeys follow whatever name people use. Each branch now
                                 says only what Portal\Core\PasskeyAddress really does in that state. */ ?>
                        <?php if ($passkeyName !== ''): ?>
                            Passkeys are tied to the name <code><?php echo $e($passkeyName); ?></code>, set for them
                            separately (the <code>auth.webauthn.rpID</code> setting). They work only on the portal's
                            own address, and only while that address is <code><?php echo $e($passkeyName); ?></code>
                            or a name under it: before changing it to a name outside that, change the passkey setting
                            too, and members will have to add their passkeys again.
                        <?php elseif ($ownAddressEach === true): ?>
                            <?php /* Round 4: two sentences about changing only the http/https part or the
                                     port were added here and removed again — they told an administrator who
                                     had recorded a REAL move to undo it. A passkey is tied to the name alone,
                                     so no passkey is lost that way; nothing more is said about it. */ ?>
                            Passkeys follow each organisation's own address (Admin, Organisations), and the portal's
                            address (this box, or while it is empty the name people use) for an organisation with none
                            of its own: changing any of those to a different name stops the passkeys made at the old
                            name working, and they have to be added again.
                        <?php elseif ($addressSaved !== ''): ?>
                            Passkeys are tied to the name in it and work only at this address: if you change it to a
                            different name, passkeys members made at the old name stop working and have to be added
                            again.
                        <?php else: ?>
                            While it is empty, passkeys are tied to the name people use to reach the portal. Setting it
                            to a different name stops the passkeys made at the old name working, and they have to be
                            added again.
                        <?php endif; ?>
                        <?php if ($addressSaved === ''): ?>
                            <strong>It is not set.</strong> Until it is, each link uses the address of whatever
                            request caused the e-mail, and a visitor can choose that.
                        <?php endif; ?>
                        <?php if ($addressInUse !== ''): ?>
                            You are using the portal at <code><?php echo $e($addressInUse); ?></code> right now.
                        <?php endif; ?>
                        <?php if ($ownAddressEach === true): ?>
                            Each organisation here has an address of its own (Admin, Organisations). A link for an
                            organisation uses that organisation's name, with the http or https part, and any port,
                            taken from this address.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <label for="supportEmail" class="form-label">Support e-mail address</label>
                    <input type="email" class="form-control" id="supportEmail" name="supportEmail" maxlength="254"
                           autocomplete="off" value="<?php echo $e($supportEmail); ?>" aria-describedby="supportEmailHelp">
                    <div id="supportEmailHelp" class="form-text">
                        Shown on the Help page "Getting Support" and at the foot of e-mails the portal sends,
                        for this organisation.
                        <?php if ($supportInherited !== ''): ?>
                            Leave it empty and this organisation uses the installation's address,
                            <code><?php echo $e($supportInherited); ?></code>.
                        <?php else: ?>
                            Leave it empty to show none: the installation has no address to fall back to,
                            so the Help page then tells people to ask an administrator.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <?php /* 💾 The button is here for every administrator now (#677), because the
                          support e-mail address on this card belongs to the organisation. Before,
                          the whole card was read-only for anybody but a global administrator, so
                          an administrator could not set the support address their own members are
                          told to write to. The portal's address above is still theirs alone, and
                          the note says so rather than leaving somebody to wonder why one box is
                          greyed out. */ ?>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save web addresses</button>
                <?php if ($mayChangeAddresses === false): ?>
                    <p class="text-secondary small mb-0 mt-2">
                        The support e-mail address is this organisation's own, so you can change it here.
                        The address of the portal applies to every organisation on this installation,
                        so only a global administrator can change that one.
                    </p>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<a href="<?php echo htmlspecialchars(Site::url('admin/settings'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Settings</a>

<?php
portal_location_map_assets(App::cspNonce());
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
