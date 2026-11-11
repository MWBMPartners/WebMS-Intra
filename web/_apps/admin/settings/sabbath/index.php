<?php
// Path: public_html/admin/settings/sabbath/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Sabbath quiet hours dedicated settings page 🕯️
 * -----------------------------------------------------------------------------
 * Friendlier UI for the 8 portal.sabbath.* settings shipped with #231.
 *
 * WHO MAY SAVE HERE, AND WHY IT IS NARROWER THAN WHO MAY LOOK
 * -----------------------------------------------------------------------------
 * The form on this page saves portal-wide rows (the INSERT below puts NULL in
 * the siteID column), so the quiet-hours window applies to EVERY organisation
 * on the installation.
 *
 * Until 13 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of one
 * organisation could switch quiet hours on or off, or move the window, for
 * every organisation — holding back, or releasing, everybody's non-urgent
 * email. The owner decided that settings affecting every organisation are for
 * a global administrator only.
 *
 * An administrator of one organisation can still open the page and see the
 * current window. They see the form read-only: the reason on the page, every
 * field disabled and no Save button. That is a courtesy only. The refusal in
 * the handler below is what enforces the rule, because a form can be sent
 * without ever opening the page.
 *
 * Probably the wrong way round, and deliberately left alone. A window worked
 * out from a timezone, a latitude and a longitude describes where ONE
 * organisation meets, and web/_core/Sabbath.php itself calls these settings
 * the "org default". The settings loader (web/_core/bootstrap.php) already
 * lets an organisation's own row override the portal-wide one. But saving per
 * organisation from this page would change what every existing installation
 * does, so it needs its own decision. Reserving the portal-wide save for a
 * global administrator is safe either way.
 *
 * THE BOXES SHOW THE INSTALLATION'S ROW, AND A SAVE CHANGES ONLY WHAT WAS SENT (#936)
 * -----------------------------------------------------------------------------
 * Because the save writes the installation's row, the boxes are filled from that
 * row (InstallationSettings::layer()), never from the merged settings (#674), and
 * "is this the value already stored?" is asked of that row too. A setting is
 * written only when its field ARRIVED (#677); the two switches only when the
 * form's `switches_shown` marker arrived, because an unticked box sends nothing.
 * A value the page cannot use is refused with its reason, and nothing is saved.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/251
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/495
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/782
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/935
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/936
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\InstallationSettings;
use Portal\Core\Logger;
use Portal\Core\Sabbath;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ Looking is allowed for any administrator; saving is not. See the note in
//    the file header for why. Worked out once and used by both the handler and
//    the form below, so the two can never disagree.
$mayChangePortalWideSettings = App::isRootAdmin();

// 🏢 WHAT IS STORED INSTALLATION-WIDE, and the only thing the boxes are filled from (#936,
//    the #674 rule). The save below writes the row with no organisation (`siteID` NULL), so
//    the boxes must show THAT row. WHAT WAS WRONG: they were filled from the merged
//    settings, where an organisation's own row has already won; a global administrator who
//    opened this page inside such an organisation saw ITS values, pressed Save without
//    touching anything, and made them every organisation's.
$storedSabbath = InstallationSettings::layer()['portal']['sabbath'] ?? [];
$storedSabbath = is_array($storedSabbath) === true ? $storedSabbath : [];
$storedAtStart = $storedSabbath; // as start-up read it, before any save below

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔑 The form token is checked exactly ONCE per request, and only then is
    //    the person's permission looked at.
    //
    //    WHAT WAS WRONG: the first version of this handler asked
    //    Auth::verifyCsrf() in the refusal branch AND again in the save branch.
    //    A successful check replaces the token with a new one (see
    //    Auth::verifyCsrf() in web/_core/Auth.php — it does that so a captured
    //    form cannot be replayed). So for a global administrator the first
    //    check passed and used the token up, and the second check then compared
    //    the form against a token that no longer existed and failed. The save
    //    never ran, nothing was written and no message appeared: Save looked as
    //    if it worked and did nothing.
    //
    //    Asking once and keeping the answer in a variable cannot go wrong that
    //    way, however the branches below are rearranged later.
    $tokenIsValid = Auth::verifyCsrf($_POST['csrf_token'] ?? '');

    if ($tokenIsValid === false) {
        // 🚫 Missing, wrong or expired token (for example the page was left
        //    open for a long time, or the request came from another website).
        //    This used to be ignored in silence, which reads as "Save is
        //    broken". Nothing is written and nothing is logged, so a forged
        //    request from another website cannot fill the activity log.
        $flash = 'This form had expired or was not sent from this page, so nothing '
            . 'has been changed. Please try again.';
        $flashType = 'danger';
    } elseif ($mayChangePortalWideSettings === false) {
        // 🚧 Refused BEFORE anything is written: every key on this page is
        //    portal-wide, so this one check covers the whole form. The refusal
        //    is worded rather than a bare "forbidden", because an administrator
        //    who presses Save and is told nothing assumes the portal is broken
        //    and tries again. It sits after the form-token check, so a forged
        //    request from another website cannot fill the activity log with
        //    refusals.
        $flash = 'These settings are portal-wide: they apply to every '
            . 'organisation on this installation, not only yours. Only a global '
            . 'administrator can change them. Nothing has been changed.';
        $flashType = 'danger';
        Logger::activity(
            'SettingsGroupSaveRefused',
            'Refused: portal-wide settings group "sabbath" may only be changed by a global administrator',
            $_SESSION['user_id'] ?? null
        );
    } else {
        // 💾 Only a global administrator with a valid token reaches this point.
        //
        //    ASK WHETHER EACH FIELD ARRIVED (#936, #677). A setting is written ONLY when its
        //    field was sent. WHAT WAS WRONG: a save that did not send the latitude, the
        //    longitude or an offset stored 0 (a place in the Gulf of Guinea, or no offset),
        //    and one without `method` stored "fixed", so a form missing a field quietly changed
        //    a setting nobody touched. #782 had fixed this for the zone only (check N17).
        //
        //    And compare with what is STORED INSTALLATION-WIDE, never with the merged
        //    settings (#674): this page writes the installation's row, so "is this the value
        //    already there?" is a question about that row. See $storedSabbath below.
        $db      = App::db();
        $values  = [];
        $refusals = [];
        $arrived = static fn (string $field): bool => array_key_exists($field, $_POST) === true;
        // A posted LIST is not text: null here, and refused below wherever text is needed.
        $posted  = static fn (string $field): ?string => is_string($_POST[$field] ?? null) === true ? trim((string) $_POST[$field]) : null;
        $stored  = static fn (string $key): string => is_scalar($storedSabbath[$key] ?? null) === true ? trim((string) $storedSabbath[$key]) : '';

        // 🔘 The two switches. A tick-box that is not ticked sends NOTHING, so "not sent"
        //    cannot be told from "switched off" by the box itself. The form therefore sends a
        //    marker, `switches_shown`, beside them, and the switches are written only when the
        //    marker arrived (the NotifyPrefs::shownMarker() pattern, #667). A request without
        //    it leaves both switches exactly as they are.
        if ($arrived('switches_shown') === true) {
            $values['portal.sabbath.enabled']         = isset($_POST['enabled']) === true ? '1' : '0';
            $values['portal.sabbath.bypass_critical'] = isset($_POST['bypass_critical']) === true ? '1' : '0';
        }

        // 🧮 The method. One of the two the page offers, or exactly what is stored already;
        //    anything else is REFUSED rather than silently made "fixed".
        if ($arrived('method') === true) {
            $methodWanted = $posted('method');
            if ($methodWanted === 'fixed' || $methodWanted === 'sunset_calc') {
                $values['portal.sabbath.method'] = $methodWanted;
            } elseif ($methodWanted === null || $methodWanted !== $stored('method')) {
                $refusals[] = 'the way the window is worked out must be one of the two offered';
            }
        }

        // 📍 THE PLACE (#935). EMPTY is a real answer, like the zone's: it means "each
        //    organisation's own map position" (Sabbath::place()), and it is what a new
        //    installation ships with. Otherwise a number Sabbath::coordinate() accepts (the
        //    ONE test, so the page cannot store what the window then ignores), or exactly what
        //    is stored already, so a plain Save over a value this page drew is never refused.
        //    Both or neither: half a place is no place, and storing one would read as a
        //    place set while the window used none.
        $placeArrived = false;
        foreach (['location_lat' => 90.0, 'location_lng' => 180.0] as $field => $limit) {
            if ($arrived($field) === false) {
                continue;
            }
            $placeArrived = true;
            $text = $posted($field);
            if ($text === '' || ($text !== null && Sabbath::coordinate($text, $limit) !== null) || ($text !== null && $text === $stored($field))) {
                $values['portal.sabbath.' . $field] = $text;
            } else {
                $refusals[] = $field === 'location_lat'
                    ? 'the latitude must be a number from -90 to 90, or left empty'
                    : 'the longitude must be a number from -180 to 180, or left empty';
            }
        }
        if ($placeArrived === true && $refusals === []) {
            $latAfter = (string) ($values['portal.sabbath.location_lat'] ?? $stored('location_lat'));
            $lngAfter = (string) ($values['portal.sabbath.location_lng'] ?? $stored('location_lng'));
            if (($latAfter === '') !== ($lngAfter === '')) {
                $refusals[] = 'give both the latitude and the longitude, or leave both empty';
            }
        }

        // ⏱️ The two offsets, in whole minutes, no more than a day either way. An empty box
        //    is no offset (0). Anything else is REFUSED, unless it is what is stored already.
        foreach (['start_offset_minutes' => 'start', 'end_offset_minutes' => 'end'] as $field => $word) {
            if ($arrived($field) === false) {
                continue;
            }
            $text = $posted($field);
            if ($text === '') {
                $values['portal.sabbath.' . $field] = '0';
            } elseif ($text !== null && preg_match('/^[+-]?\d{1,4}$/', $text) === 1 && abs((int) $text) <= 1440) {
                $values['portal.sabbath.' . $field] = (string) ((int) $text);
            } elseif ($text === null || $text !== $stored($field)) {
                $refusals[] = 'the ' . $word . ' offset must be a whole number of minutes from -1440 to 1440';
            }
        }

        if ($arrived('timezone') === true) {
            $values['portal.sabbath.timezone'] = $posted('timezone') ?? '';
        }
        // 🕰️ THE ZONE (#782). EMPTY is a real answer here, not a missing one: it means
        //    "each organisation's own time zone" (Sabbath::zone() then asks Site::zoneFor(),
        //    which falls back to Site::timezone(), #604), and it is what a new installation
        //    ships with. Anything else must be a zone name PHP's main list knows
        //    (Site::isZoneName()) — or be exactly what is stored already, so a plain Save over
        //    a value this page itself drew is never refused (ask whether the field CHANGED,
        //    #667, #677). Otherwise NOTHING is saved and the reason is shown.
        //    WHAT WAS WRONG: an unknown zone was silently replaced with "Europe/London", for
        //    every customer anywhere, and the page offered London pre-selected. A posted LIST
        //    is not text and counts as empty, which is the organisation's own zone.
        //    AND ASK WHETHER THE FIELD ARRIVED (#677). Empty is an instruction here ("each
        //    organisation's own"), so a form that did not send the field at all must not be read
        //    as that instruction: the zone is then left exactly as it is and not written. The
        //    first version of #782 stored '' for a missing field, which would have moved a chosen
        //    zone to the organisation's without anybody choosing it (found by the independent check).
        //    "What is stored already" is the INSTALLATION's row (#936): it used to be read from
        //    the merged settings, so inside an organisation with a zone of its own an unknown
        //    zone matching THAT organisation's row was accepted and written for everybody.
        $zoneArrived = $arrived('timezone');
        $zoneNow    = $stored('timezone');
        $zoneWanted = (string) ($values['portal.sabbath.timezone'] ?? $zoneNow);
        $zoneOk     = $zoneArrived === false || $zoneWanted === '' || Site::isZoneName($zoneWanted) === true || $zoneWanted === $zoneNow;
        if ($zoneOk === false) {
            // 🕰️ Refused BEFORE the first row is written, so nothing on this page has changed.
            $flash = 'That time zone is not one this portal knows, so nothing has been changed. '
                . 'Choose one from the list, or choose "Each organisation\'s own time zone".';
            $flashType = 'danger';
        } elseif ($refusals !== []) {
            // 🚫 Refused BEFORE the first row is written, with every reason at once, so
            //    nothing on this page has changed and the administrator can fix it all in one go.
            $flash = 'Nothing has been changed: ' . implode('; ', $refusals) . '.';
            $flashType = 'danger';
        } else {
            try {
                foreach ($values as $key => $val) {
                    $stmt = $db->prepare(
                        "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) "
                        . "VALUES (NULL, ?, ?, ?, 0) "
                        . "ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)"
                    );
                    if ($stmt !== false) {
                        $stmt->bind_param('sss', $key, $val, $val);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                $flash = 'Sabbath settings saved.';
                $flashType = 'success';
                // 🪞 The boxes below are drawn from what is stored, and start-up read that
                //    before this save; so what was just written is laid over it, or the page
                //    would show the old values beside "saved".
                foreach ($values as $key => $val) {
                    $storedSabbath[substr($key, strlen('portal.sabbath.'))] = $val;
                }
            } catch (\Throwable $e) {
                $flash = 'Save failed: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    }
}

// 🏢 Every box from the INSTALLATION's row ($storedSabbath, above), never the merged
//    settings (#936). The window shown further down is the one in force for THIS
//    organisation, from Sabbath itself; the two can differ, and the page says so.
$shown = static fn (string $key, string $none): string => is_scalar($storedSabbath[$key] ?? null) === true ? trim((string) $storedSabbath[$key]) : $none;
$enabled        = $shown('enabled', '0') === '1';
$method         = $shown('method', 'fixed');
// 🕰️ What is STORED ('' means each organisation's own zone, #782), and the clock that
//    really applies to THIS organisation, from Sabbath::zone() — the one answer.
$timezone       = $shown('timezone', '');
// 📍 Shown as stored, as TEXT (#935): '' means each organisation's own map position. It
//    used to be cast to a number, so an empty setting showed as 0 and the next Save stored 0.
$lat            = $shown('location_lat', '');
$lng            = $shown('location_lng', '');
$startOffset    = (int) $shown('start_offset_minutes', '0');
$endOffset      = (int) $shown('end_offset_minutes', '0');
$bypassCritical = $shown('bypass_critical', '1') === '1';

// 🏢 Does THIS organisation hold Sabbath rows of its own that differ from the installation's?
//    Those are where the merged settings (start-up's) differ from the installation's row as
//    start-up read it. Then the window in force here is not what the boxes say, and the page
//    says so rather than leave the two to contradict each other in silence (the
//    admin/transcription precedent, #674). Nothing on this page writes such a row.
$merged = App::settings()['portal']['sabbath'] ?? [];
$merged = is_array($merged) === true ? $merged : [];
$orgOwn = [];
foreach ($merged as $key => $val) {
    $installationVal = is_scalar($storedAtStart[$key] ?? null) === true ? (string) $storedAtStart[$key] : null;
    if (is_scalar($val) === true && (string) $val !== $installationVal) {
        $orgOwn[$key] = $val;
    }
}
$ownRowsDiffer = $orgOwn !== [];

// 📍 What is IN FORCE for this organisation (#935): the installation's row as it stands now
//    (including anything just saved, which start-up has not seen) with this organisation's
//    own rows over it. Sunset with no place known is worked out by the fixed method, and
//    that is said in words below, never left to be guessed.
$inForce       = array_replace($storedSabbath, $orgOwn);
$methodInForce = is_scalar($inForce['method'] ?? null) === true ? (string) $inForce['method'] : 'fixed';
$placeInForce  = Sabbath::place(null, $inForce);
$enabledInForce = is_scalar($inForce['enabled'] ?? null) === true && (string) $inForce['enabled'] === '1';
// 🕰️ The clock the window is worked out on here, from the same settings as the window.
$zoneInUse     = Sabbath::zone(null, $inForce);

// 🪞 Preview: current/next window, from what is in force NOW (including a save just made).
//    windowDetails() also says when an edge is 18:00 because the sun does not set (#935).
$winDetails  = Sabbath::windowDetails(time(), null, $inForce);
$winStart    = $winDetails['start'];
$winEnd      = $winDetails['end'];
$winNoSunset = $winDetails['noSunset'];

$pageTitle   = 'Sabbath quiet hours';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Settings' => Site::url('admin/settings'), 'Sabbath' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();

// All zone names the portal knows: the SAME list Site::isZoneName() tests (#782).
$allTimezones = Site::zoneNames();
// ⚠️ A stored value that is not on that list (an old "US/Eastern", say) is still OFFERED, and
//    selected, because a <select> with nothing selected is a vote for its FIRST option — which is
//    now "each organisation's own" — so the next Save would quietly change it (#763's lesson).
$storedOffList = $timezone !== '' && in_array($timezone, $allTimezones, true) === false;
?>

<h1 class="mb-3"><i class="fa-solid fa-moon me-2"></i>Sabbath quiet hours</h1>
<p class="text-muted">Defer non-urgent emails / notifications between Friday and Saturday windows. Critical alerts can optionally bypass.</p>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php
// 🔒 Added to every control when the person looking may not save. This is a
//    COURTESY, not the control: a disabled field is only a hint to the browser
//    and anybody can send the form anyway. The refusal in the save handler at
//    the top of this file is what actually enforces the rule.
$readOnlyAttr = $mayChangePortalWideSettings === false ? ' disabled' : '';
?>

<?php if ($mayChangePortalWideSettings === false): ?>
    <!-- 👀 Read-only notice. Shown instead of letting somebody fill the form in
         and only find out it was refused after they pressed Save. -->
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>
        These settings apply to <strong>every organisation</strong> on this
        installation, not only yours, so only a global administrator can change
        them. You can see the current values here.
    </div>
<?php endif; ?>

<?php if ($ownRowsDiffer === true): ?>
    <?php // 🏢 #936: the boxes show the installation's settings, and this organisation holds some
          //    of its own, so what is in force here differs from the boxes. Said, not hidden. ?>
    <div class="alert alert-warning" data-sabbath-own-rows>
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        This organisation has Sabbath settings of its own, which this page does not show or change.
        The boxes below are the settings for the whole installation; the window shown here is the one
        in force for this organisation.
    </div>
<?php endif; ?>

<?php if ($methodInForce === 'sunset_calc' && $placeInForce === null): ?>
    <?php // 📍 #935: sunset needs a place and none is known. The window is worked out by the fixed
          //    method instead (Sabbath::computeWindow()), and this is the sentence that says so. ?>
    <?php // Said exactly (round 1 of the independent check): "no latitude and longitude" was
          // untrue when half a place, or a value that is not a number, is stored. ?>
    <?php $placeTyped = trim((string) ($inForce['location_lat'] ?? '')) !== '' || trim((string) ($inForce['location_lng'] ?? '')) !== ''; ?>
    <div class="alert alert-warning" data-sabbath-no-place>
        <i class="fa-solid fa-location-dot me-2"></i>
        Sunset is chosen, but no usable place is known for this organisation:
        <?php echo $placeTyped === true
            ? 'the latitude and longitude in force here are not a usable pair (both are needed, each a number in range),'
            : 'no latitude and longitude are given here,'; ?>
        and its organisation settings have no usable map position. Until there is one, quiet hours use the
        fixed times instead: 18:00 on Friday to 18:00 on Saturday, with the offsets below.
        Set a place below, or this organisation's map position at
        <a href="<?php echo htmlspecialchars(Site::url('admin/settings/organisation'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Settings, Organisation</a>.
    </div>
<?php elseif ($methodInForce === 'sunset_calc' && $placeInForce !== null): ?>
    <div class="alert alert-light border" data-sabbath-place-from="<?php echo htmlspecialchars((string) $placeInForce['from'], ENT_QUOTES, 'UTF-8'); ?>">
        <i class="fa-solid fa-location-dot me-2"></i>
        Sunset is worked out at <?php echo htmlspecialchars(sprintf('%.4F, %.4F', $placeInForce['lat'], $placeInForce['lng']), ENT_QUOTES, 'UTF-8'); ?>,
        <?php echo $placeInForce['from'] === 'organisation'
            ? 'this organisation\'s own map position (from Admin, Settings, Organisation).'
            : 'the place given below.'; ?>
    </div>
<?php endif; ?>

<?php if ($enabledInForce === true): ?>
    <div class="alert alert-info">
        <strong>Current window (your settings):</strong>
        <?php // 🕰️ Shown on the clock the window is worked out on (#782). It used to be printed on
              //    PHP's clock, which is the ORGANISATION's, beside the name of the setting's zone —
              //    two different clocks in one line whenever they differed. ?>
        <?php echo htmlspecialchars((new \DateTimeImmutable('@' . $winStart))->setTimezone($zoneInUse)->format('D j M H:i'), ENT_QUOTES, 'UTF-8'); ?>
        → <?php echo htmlspecialchars((new \DateTimeImmutable('@' . $winEnd))->setTimezone($zoneInUse)->format('D j M H:i'), ENT_QUOTES, 'UTF-8'); ?>
        (<?php echo htmlspecialchars($zoneInUse->getName(), ENT_QUOTES, 'UTF-8'); ?>)
        <?php if ($winNoSunset === true): ?>
            <?php // 🌌 #935 round 3: an 18:00 here is not a sunset, and the preview says so. ?>
            <div class="mt-1" data-sabbath-no-sunset>
                The sun does not set that Friday or Saturday at this place, so 18:00 is used for that edge
                of the window.
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <?php // 🔘 #936: says "the two switches below were drawn", so an unticked box (which sends
          //    nothing) is read as OFF only when this form really sent it. ?>
    <input type="hidden" name="switches_shown" value="1">

    <div class="card mb-3">
        <div class="card-body">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?php echo $enabled === true ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                <label class="form-check-label" for="enabled"><strong>Enable Sabbath quiet hours</strong></label>
            </div>
            <p class="form-text">When enabled, non-critical email notifications scheduled during the window are deferred until after.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Window calculation</h2>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="m_fixed" name="method" value="fixed" <?php echo $method === 'fixed' ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                <label class="form-check-label" for="m_fixed">Fixed local time (Friday 18:00 → Saturday 18:00)</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="m_sunset" name="method" value="sunset_calc" <?php echo $method === 'sunset_calc' ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                <label class="form-check-label" for="m_sunset">Sunset calculation from latitude / longitude</label>
                <?php // 🌌 #935 round 3: said BEFORE anybody chooses it, not only when it happens. ?>
                <div class="form-text">
                    Where the sun does not set on a Friday or a Saturday (polar day or night, far north or
                    south), that edge of the window uses 18:00 on that day instead, with the offsets.
                </div>
            </div>

            <div class="row g-2 mt-3">
                <div class="col-md-6">
                    <label class="form-label small" for="sabbath-zone">Time zone</label>
                    <select id="sabbath-zone" name="timezone" class="form-select form-select-sm"<?php echo $readOnlyAttr; ?>>
                        <?php // 🕰️ FIRST, and what a new installation ships with (#782): each organisation's
                              //    own zone, as chosen at Admin, Organisations. The name in brackets is the
                              //    organisation you are in now, so it is clear what "own" means here. ?>
                        <option value="" <?php echo $timezone === '' ? 'selected' : ''; ?>>
                            Each organisation's own time zone (here: <?php echo htmlspecialchars(Site::timezone(), ENT_QUOTES, 'UTF-8'); ?>)
                        </option>
                        <?php if ($storedOffList === true): ?>
                            <option value="<?php echo htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8'); ?>" selected>
                                <?php echo htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8'); ?> (saved earlier; not on the current list)
                            </option>
                        <?php endif; ?>
                        <?php foreach ($allTimezones as $tz): ?>
                            <option value="<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $tz === $timezone ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="sabbath-lat">Latitude</label>
                    <input type="number" step="any" min="-90" max="90" id="sabbath-lat" name="location_lat" class="form-control form-control-sm" value="<?php echo htmlspecialchars($lat, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $readOnlyAttr; ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="sabbath-lng">Longitude</label>
                    <input type="number" step="any" min="-180" max="180" id="sabbath-lng" name="location_lng" class="form-control form-control-sm" value="<?php echo htmlspecialchars($lng, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $readOnlyAttr; ?>>
                </div>
                <div class="col-12">
                    <?php // 📍 #935: empty is a real answer, as for the zone. Nothing is built in. ?>
                    <div class="form-text">
                        For sunset only. Leave both empty to use each organisation's own map position,
                        set at Admin, Settings, Organisation. With neither set, the fixed times are used.
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Start offset (mins)</label>
                    <input type="number" name="start_offset_minutes" class="form-control form-control-sm" value="<?php echo (int) $startOffset; ?>"<?php echo $readOnlyAttr; ?>>
                    <div class="form-text">Negative = earlier than sunset</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">End offset (mins)</label>
                    <input type="number" name="end_offset_minutes" class="form-control form-control-sm" value="<?php echo (int) $endOffset; ?>"<?php echo $readOnlyAttr; ?>>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="bypass" name="bypass_critical" <?php echo $bypassCritical === true ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                <label class="form-check-label" for="bypass"><strong>Critical alerts bypass quiet hours</strong></label>
            </div>
            <p class="form-text">When on, Critical/Fatal severity alerts (from #229) still fire during the window. Non-critical alerts always defer.</p>
        </div>
    </div>

    <?php if ($mayChangePortalWideSettings === true): ?>
        <button type="submit" class="btn btn-primary">Save</button>
    <?php endif; ?>
    <a href="<?php echo htmlspecialchars(Site::url('admin/settings'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Cancel</a>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
