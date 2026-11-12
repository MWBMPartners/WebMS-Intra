<?php
// Path: public_html/calendar/manage/save.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Save Handler 💾
 * -----------------------------------------------------------------------------
 * Handles create and update POST actions for events. Validates input,
 * generates slugs, handles image uploads, and saves to tblEvents.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/436
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\EventCancelReason;
use Portal\Core\EventRecurrence;
use Portal\Core\Events;
use Portal\Core\Geocoder;
use Portal\Core\HiddenSeriesDates;
use Portal\Core\GeoLocation;
use Portal\Core\InstallationSettings;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;
use Portal\Core\What3Words;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\I18n;

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('calendar/manage'));
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar/manage'));
    exit();
}

$action = $_POST['action'] ?? '';

// -----------------------------------------------------------------------------
// 🕰️ The one button on Manage Events (#604): set this organisation's events
//    made before the time-zone upgrade, and still marked "Europe/London", to
//    the organisation's own zone. See Events::markedLondonBeforeUpgrade() for
//    which events and why. It is handled here, before any event form field
//    is read, because it posts none. Who may press it: whoever may manage
//    events here (the check at the top of this file), with a form token
//    (just above). The zone changes; no stored time does.
// -----------------------------------------------------------------------------
if ($action === 'zones-to-organisation') {
    try {
        $movedZone = Site::timezone();
        $moved     = Events::moveLondonMarkedToOrganisationZone($mysqli, Site::id());
        Logger::activity(
            'EventZonesSetToOrganisation',
            'Set the time zone of ' . $moved . ' event(s) made before the time-zone upgrade from "Europe/London" to the organisation\'s own, '
                . $movedZone . '. Their stored times were not changed.',
            isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null
        );
        $_SESSION['flash_msg']  = $moved === 0
            ? 'No event needed changing.'
            : $moved . ($moved === 1 ? ' event is' : ' events are') . ' now on this organisation\'s time zone (' . $movedZone . '). No stored time was changed.';
        $_SESSION['flash_type'] = 'success';
    } catch (\Throwable $e) {
        error_log('Manage Events could not set the old events\' time zone: ' . $e->getMessage());
        $_SESSION['flash_msg']  = 'The events\' time zone could not be changed, so nothing was changed. The reason is in the server\'s error log.';
        $_SESSION['flash_type'] = 'danger';
    }
    header('Location: ' . Site::url('calendar/manage'));
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Collect form data
// -----------------------------------------------------------------------------
$eventName     = trim($_POST['eventName'] ?? '');
$description   = trim($_POST['description'] ?? '');
$startDateTime = trim($_POST['startDateTime'] ?? '');
$endDateTime   = trim($_POST['endDateTime'] ?? '');
// The zone box as posted; '' when it was left out. What it may hold is decided
// under "THE EVENT'S TIME ZONE" below. (An absent box used to become
// "Europe/London", typed in here.)
$timezone      = trim((string) ($_POST['timezone'] ?? ''));
$isAllDay      = isset($_POST['isAllDay']) === true ? 1 : 0;
$categoryID    = ((int) ($_POST['categoryID'] ?? 0)) ?: null;
$typeID        = ((int) ($_POST['typeID'] ?? 0)) ?: null;
$seriesID      = ((int) ($_POST['seriesID'] ?? 0)) ?: null;
$status        = (string) ($_POST['status'] ?? 'draft');
// ⚠️ This went in UNCHECKED until #676: whatever was posted was bound straight
//    into the INSERT and the UPDATE. The form offers only the four valid
//    options, but a `<select>` is a courtesy to whoever fills it in, not a
//    boundary -- anything can be posted. So `status=archived` reached the
//    database, where (strict since #593) it failed with "Data truncated for
//    column 'status'", and on an older installation stored the EMPTY STRING,
//    which leaves an event with a blank status badge that no status filter can
//    find. These four are exactly `tblEvents.status`'s ENUM; an unrecognised
//    word becomes a draft, which is what this page has always done with a
//    missing one.
if (in_array($status, ['draft', 'published', 'cancelled', 'postponed'], true) === false) {
    $status = 'draft';
}
$isPublic      = isset($_POST['isPublic']) === true ? 1 : 0;
$isFeatured    = isset($_POST['isFeatured']) === true ? 1 : 0;

// ✍️ WHY IT IS CANCELLED OR POSTPONED (#998). `Portal\Core\EventCancelReason`
//    is the one rule and the one writer; this only collects what was posted.
//    Refused HERE, before any upload is stored, so a refusal leaves nothing
//    behind. "Did it ARRIVE" is kept apart from "what does it hold" (#677):
//    the Manage Events form always sends the box, so an empty box is a real
//    instruction to remove the reason, while a post that does not carry the
//    field at all leaves a kept reason alone.
$cancelReasonArrived = array_key_exists('cancelReason', $_POST);
$cancelReasonRefusal = EventCancelReason::whyRefused($cancelReasonArrived === true ? $_POST['cancelReason'] : null);
if ($cancelReasonRefusal !== null) {
    $_SESSION['flash_msg']  = $cancelReasonRefusal;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar/manage'));
    exit();
}
$cancelReason = $cancelReasonArrived === true ? EventCancelReason::normalise((string) $_POST['cancelReason']) : null;
// A reason typed (or left in the box) while choosing a status that carries
// none is not stored — and the message says so, because quietly dropping
// words somebody typed is how they come to believe members can read them.
$cancelReasonNotKept = $cancelReason !== null && EventCancelReason::carriesReason($status) === false;

// 📍 Location fields
$locationName    = trim($_POST['locationName'] ?? '');
$locationAddress = trim($_POST['locationAddress'] ?? '');
$locationWebURL  = trim($_POST['locationWebURL'] ?? '');
$locationPhone   = trim($_POST['locationPhone'] ?? '');
$locationEmail   = trim($_POST['locationEmail'] ?? '');

// 📍 #456 Chunk A — validated via GeoLocation (out-of-range coords ⇒ null,
// never a silent bad store); a non-empty invalid W3W is rejected with a
// flash + redirect BEFORE anything is saved (nothing else in this handler
// has side effects yet at this point).
$geoCoords = GeoLocation::validateCoords($_POST['locationGeoLat'] ?? null, $_POST['locationGeoLng'] ?? null);
$locationGeoLat = $geoCoords['lat'] ?? null;
$locationGeoLng = $geoCoords['lng'] ?? null;

$locationW3WRaw = trim($_POST['locationW3W'] ?? '');
$locationW3W = '';
if ($locationW3WRaw !== '') {
    $validatedW3W = GeoLocation::validateW3W($locationW3WRaw);
    if ($validatedW3W === null) {
        $_SESSION['flash_msg']  = t('location.w3w_invalid');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('calendar/manage'));
        exit();
    }
    $locationW3W = $validatedW3W;
}

$geoWarning = null;
if ($locationW3W !== '' && What3Words::isConfigured() === true) {
    $verifiedCoords = What3Words::convertToCoordinates($locationW3W);
    if ($verifiedCoords !== null) {
        if ($locationGeoLat === null || $locationGeoLng === null) {
            $locationGeoLat = $verifiedCoords['lat'];
            $locationGeoLng = $verifiedCoords['lng'];
        }
    } else {
        // ⚠️ Verification failure is a warning, never a rejection — the
        // save still succeeds with the stored string.
        $geoWarning = t('location.w3w_unverified');
    }
}

if (($locationGeoLat === null || $locationGeoLng === null) && Geocoder::autoEnabled() === true && $locationAddress !== '') {
    $geocoded = Geocoder::forward($locationAddress);
    if ($geocoded !== null) {
        $locationGeoLat = $geocoded['lat'];
        $locationGeoLng = $geocoded['lng'];
    }
}

// 🏢 Organisation fields
$hostOrgName = trim($_POST['hostOrgName'] ?? '');
$partnerOrgsStr = trim($_POST['partnerOrgs'] ?? '');
$partnerOrgs = null;
if ($partnerOrgsStr !== '') {
    $partnerOrgs = json_encode(array_map('trim', explode(',', $partnerOrgsStr)));
}

// 🔍 Validation
if ($eventName === '' || $startDateTime === '') {
    $_SESSION['flash_msg']  = 'Event name and start date/time are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar/manage'));
    exit();
}

// 📆 The end cannot come before the start.
//
//    This was accepted until now, and it caused real harm rather than merely
//    looking untidy. Several things work out how long ago an event finished by
//    reading its end time - including the clear-out that deletes children's
//    registration details a set number of days afterwards. An event moved into
//    the future while its old end date was left behind therefore looked, to
//    that clear-out, like an event that finished long ago, and its
//    registrations were eligible for deletion before it had even happened.
//
//    The clear-out now also protects itself by taking whichever of the two
//    times is later, so it is safe either way. This stops the bad data being
//    created in the first place, which is the better place to stop it.
//
//    An equal end and start is allowed: a moment in a diary is a reasonable
//    thing to record, and the comparison below is deliberately "before", not
//    "before or equal".
//    Compared as plain text, on purpose, and NOT by turning them into moments
//    in time. That looks like the lazy way round and is in fact the correct
//    one, for two separate reasons.
//
//    First, these are wall-clock times. The database column says so in as many
//    words: the time written on the poster on the wall, not a point on a
//    worldwide timeline. Comparing two wall-clock times needs no timezone, and
//    bringing one in can only introduce error.
//
//    Second, converting them actively breaks this check once a year. On the
//    morning the clocks go forward, one hour does not exist. Asked to read
//    01:45 on that date, PHP helpfully shifts it to 02:45 - so an event
//    starting 02:30 and "ending" 01:45 came out as ending LATER than it
//    started, and sailed through. Verified on this machine: 2026-03-29 01:45 in
//    Europe/London becomes 02:45.
//
//    Both values come from the same form, from date-and-time fields that always
//    produce the same fixed layout - year, month, day, hour, minute, in that
//    order, largest unit first. Text sorted that way sorts in time order too,
//    which is the whole reason dates are written that way round.
$normaliseWhen = static function (string $when): string {
    // The browser sends the date and time joined by a "T". The database uses a
    // space. Same value, so make them look the same before comparing.
    return trim(str_replace('T', ' ', $when));
};

$startWhen = $normaliseWhen($startDateTime);
$endWhen   = $normaliseWhen($endDateTime);

// Anything not in that layout is left to the existing handling rather than
// rejected here with a message about the wrong problem.
$looksLikeDateTime = static function (string $when): bool {
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $when) === 1;
};

if (
    $looksLikeDateTime($startWhen) === true
    && $looksLikeDateTime($endWhen) === true
    && $endWhen < $startWhen
) {
    $_SESSION['flash_msg']  = 'The event cannot end before it starts. Please check the dates.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar/manage'));
    exit();
}

// 🕰️ THE EVENT'S TIME ZONE (#604). The start and end above are times on a
//    clock, and `tblEvents.timezone` says which clock. The box used to be free
//    text, stored exactly as typed, so "London", "GMT+1" or a slip of the
//    finger was saved, and every reader then had to guess (most guessed
//    London). Now only a real zone name is stored:
//      - a name from PHP's main list (Site::isZoneName(), the list the form
//        offers) is taken;
//      - no zone at all means the ORGANISATION's own for a new event, and
//        "leave it as it is" for an existing one;
//      - an existing event keeps a zone it already has that is not on the
//        list but that PHP can still open (an old name such as
//        "US/Eastern"): it has always been shown on that clock;
//      - anything else is refused, naming the box, and nothing is saved.
//    It is never replaced by a guess. This sits before the uploads below, so
//    a refusal leaves nothing behind.
$storedZone = null;
// 🌐 The event's public-website fields as they are stored now (#493 P1), read
//    in the same statement as its zone, so "did it CHANGE" (#687) is judged
//    against the row being edited. A new event starts from OFF, no dates.
$storedWebsite = \Portal\Core\PublicWebsite::NEW_EVENT_VALUES;
$storedWebsiteFound = false;
// The row being edited, as stored (null for a new event, or when the number
// is not one of this organisation's own events). See #1000 below.
$zoneRow = null;
if ($action === 'update') {
    // The portal's own events only (`externalFeedID IS NULL`): an event copied in
    // from an outside calendar cannot be edited here at all (the UPDATE below
    // refuses one too), so its zone is none of this form's business.
    $zoneStmt = $mysqli->prepare('SELECT `timezone`, `showOnWebsite`, `websiteFrom`, `websiteUntil`, `isDeleted`, `seriesID` FROM `tblEvents` WHERE `eventID` = ? AND `siteID` = ? AND `externalFeedID` IS NULL LIMIT 1');
    if ($zoneStmt !== false) {
        $zoneEventId = (int) ($_POST['eventID'] ?? 0);
        $zoneSiteId  = Site::id();
        $zoneStmt->bind_param('ii', $zoneEventId, $zoneSiteId);
        $zoneStmt->execute();
        $zoneRow = $zoneStmt->get_result()->fetch_assoc();
        $zoneStmt->close();
        $storedZone = $zoneRow !== null ? (string) $zoneRow['timezone'] : null;
        if ($zoneRow !== null) {
            $storedWebsiteFound = true;
            $storedWebsite = [
                'showOnWebsite' => (int) $zoneRow['showOnWebsite'],
                'websiteFrom'   => $zoneRow['websiteFrom'] === null ? null : (string) $zoneRow['websiteFrom'],
                'websiteUntil'  => $zoneRow['websiteUntil'] === null ? null : (string) $zoneRow['websiteUntil'],
            ];
        }
    }

    // 🚫 NOT THIS ORGANISATION'S EVENT, OR A HIDDEN ONE (#1000) — refused
    //    HERE, before any upload is stored, so a refusal leaves nothing behind.
    //    This is the cheap first ask; the UPDATE below asks again under a row
    //    lock, because the date could be hidden between the two.
    //
    //    • Not found — a number that is not one of this organisation's own
    //      events (another organisation's, an imported one, or none). The
    //      update used to change 0 rows and then say "updated successfully",
    //      which was untrue; it now says nothing was saved. One answer for all
    //      of them, so it never tells anybody another organisation has an
    //      event with that number (#514's rule).
    //    • Hidden — this organisation's own date, so the words are truthful:
    //      bring it back first (`HiddenSeriesDates::editRefusalWords()`). A
    //      hidden date's status decides what it comes back as (D-145 to
    //      D-147), which is why it must not be changed while hidden. The
    //      answer lands on the series' bulk-edit page, where the "Hidden
    //      dates" card is, when it has a series.
    if ($zoneRow === null) {
        $_SESSION['flash_msg']  = 'That event could not be found here, so nothing was saved.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('calendar/manage'));
        exit();
    }
    if ((int) $zoneRow['isDeleted'] === 1) {
        $hiddenSeries = $zoneRow['seriesID'] === null ? null : (int) $zoneRow['seriesID'];
        $_SESSION['flash_msg']  = HiddenSeriesDates::editRefusalWords($hiddenSeries, true);
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . ($hiddenSeries !== null
            ? Site::url('calendar/manage/series-edit') . '?seriesID=' . $hiddenSeries
            : Site::url('calendar/manage')));
        exit();
    }
}
$keepsStoredZone = $storedZone !== null && Site::phpCanOpenZone($storedZone) === true
    && ($timezone === '' || $timezone === $storedZone);
if ($keepsStoredZone === true) {
    $timezone = $storedZone;
} elseif ($timezone === '' && $action === 'create') {
    $timezone = Site::timezone();
} elseif (Site::isZoneName($timezone) === false) {
    $_SESSION['flash_msg']  = $timezone === ''
        ? 'Choose a time zone for the event in the "Time zone" box. Nothing was saved.'
        : 'The "Time zone" box holds "' . mb_substr($timezone, 0, 64) . '", which is not a time zone name the portal knows. '
            . 'Choose one from the list. Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar/manage'));
    exit();
}

// 🌐 THE PUBLIC WEBSITE SWITCH AND DATES (#493 P1; D-057, D-077, D-078).
//    Judged by `PublicWebsite::judgeEventFields()` — the ONE judgement, which
//    the events API asks too — on facts gathered here:
//      - which fields ARRIVED (#677). The form puts a hidden `websiteFields`
//        marker beside its controls, and draws them only for somebody who may
//        publish; with the marker absent nothing here is touched, so an
//        ordinary Save by anybody else neither refuses nor clears it. With it
//        present an unticked box (which a browser sends as nothing) is "off";
//      - what is stored now (read with the zone, above);
//      - whether this person may publish, asked LIVE of the database.
//    A real change by somebody who may not is REFUSED with the reason and
//    recorded, never quietly dropped (#668). Sits before the uploads, so a
//    refusal leaves nothing behind.
$websiteArrived = [];
if (array_key_exists('websiteFields', $_POST) === true) {
    // The box's VALUE decides, not whether it arrived (round 3 of the check). A
    // browser sends nothing for an unticked box, so absent is "off"; but the
    // common pattern of a hidden "0" placed before the box posts "0" when it is
    // unticked, and testing presence read that as ON. The judgement refuses
    // anything that is not a plain true/false value, so nothing odd reads as on.
    $websiteArrived['showOnWebsite'] = array_key_exists('showOnWebsite', $_POST) === true ? $_POST['showOnWebsite'] : '0';
    foreach (['websiteFrom', 'websiteUntil'] as $websiteField) {
        if (array_key_exists($websiteField, $_POST) === true) {
            $websiteArrived[$websiteField] = is_string($_POST[$websiteField]) === true ? $_POST[$websiteField] : false;
        }
    }
} else {
    // A hand-made post carrying the fields WITHOUT the marker is still judged:
    // only a field that did not arrive at all is "leave it alone".
    // The box is passed as it arrived, as in the branch above, so an array is
    // refused as not true-or-false rather than read as "off" (round 4).
    foreach (\Portal\Core\PublicWebsite::EVENT_FIELDS as $websiteField) {
        if (array_key_exists($websiteField, $_POST) === true) {
            $websiteArrived[$websiteField] = ($websiteField === 'showOnWebsite' || is_string($_POST[$websiteField]) === true)
                ? $_POST[$websiteField] : false;
        }
    }
}
$websiteJudged = \Portal\Core\PublicWebsite::judgeEventFields(
    $websiteArrived,
    $storedWebsite,
    \Portal\Core\PublicWebsite::mayPublish($mysqli, (int) ($_SESSION['user_id'] ?? 0), Site::id())
);
if ($websiteJudged['ok'] === false) {
    if ($websiteJudged['kind'] === 'forbidden') {
        \Portal\Core\PublicWebsite::recordRefusal(
            $action === 'update' ? (int) ($_POST['eventID'] ?? 0) : null,
            $eventName,
            $websiteJudged,
            isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null,
            'the Manage Events form'
        );
    }
    $_SESSION['flash_msg']  = $websiteJudged['reason'];
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar/manage'));
    exit();
}
$websiteValues = $websiteJudged['values'];

// 🔤 THE WEB ADDRESS IT WOULD LIKE: NAME AND DATE (`Events::slugStem()`, #883).
//
//    This file made the stem itself until #883 — the same shape, the name
//    made address-safe and the start DATE after it — and `Events::slugStem()`
//    was lifted from it for the calendar file import (#877). Now there is one
//    copy, so an event made here, by the import, by the events API or by the
//    public suggestion form gets an address of the same shape.
//
// 🕐 TWO THINGS #777 FOUND HERE STILL HOLD, AND NOW LIVE IN `slugStem()`.
//    The date is SLICED out of the text, never resolved: `strtotime()` put a
//    reading of 23:00-23:59 on the NEXT DAY on the night the clocks go
//    forward at 23:00 (`America/Nuuk` and `America/Scoresbysund`, measured
//    across every IANA zone, round 10). And anything in the date part that is
//    not a digit becomes one hyphen: `$startDateTime` is shape-checked
//    nowhere in this file, MySQL accepts any punctuation as a DATETIME
//    delimiter, so `2026/06/01 10:00` used to be stored with the slash in its
//    address, which made the event unreachable at its own clean address
//    (round 11). `slugStem()` also cuts the name to 100 characters, which
//    this file never did: a name longer than about 188 characters made an
//    address too long for the 200-character column, and the INSERT failed.
//
//    `$slug` here is only the STEM: whether it is free is asked when the
//    event is saved (see "Create event" below). The image uploads below name
//    their files after the stem, as they always did.
$slugStem = Events::slugStem($eventName, I18n::wallClockFromInput($startDateTime) ?? $startDateTime);
$slug     = $slugStem;

// 🖼️ Handle image uploads
$uploadsDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'calendar';
if (is_dir($uploadsDir) === false) {
    mkdir($uploadsDir, 0755, true);
}

/**
 * 📸 Process a single image upload
 *
 * Security:
 *   - Extension allow-list (raster + PDF only — NO SVG; SVG can carry
 *     JavaScript and would XSS anyone viewing the event page).
 *   - 5 MB size cap (event images don't need to be bigger).
 *   - Server-side MIME check via finfo_file — confirms the file IS
 *     what its extension claims, blocking renamed-PHP-as-JPG attacks.
 *   - Stored filename is fully reconstructed from $slug + $fieldName +
 *     timestamp + extension — never trusts the client filename.
 */
$processUpload = function (string $fieldName) use ($uploadsDir, $slug): ?string {
    if (isset($_FILES[$fieldName]) === false || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $file = $_FILES[$fieldName];

    // 🛡️ Size cap — 5 MB
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return null;
    }

    // 🛡️ Extension allow-list. SVG explicitly excluded — see security note above.
    $ext     = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    if (in_array($ext, $allowed, true) === false) {
        return null;
    }

    // 🛡️ Server-side MIME sniff. Must match the extension's expected type.
    $finfo = function_exists('finfo_open') === true ? finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo === false) {
        return null;
    }
    $detectedMime = (string) finfo_file($finfo, (string) $file['tmp_name']);
    // ⚠️ No `finfo_close()`. It has done nothing since PHP 8.1 (the finfo
    //    object is freed when it goes out of scope) and PHP 8.5 deprecates it,
    //    so every picture saved here wrote a deprecation notice into the
    //    portal's error log. Found by #998's upload check (section U of
    //    tools/event-repeat-pages-selftest.php, check J02); the same call in
    //    seven other files is #1003.

    $expectedMime = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
    ];
    if (in_array($detectedMime, $expectedMime[$ext] ?? [], true) === false) {
        return null;
    }

    // 🏷️ Always reconstruct the stored filename — never trust the client's.
    $newName = $slug . '-' . $fieldName . '-' . time() . '.' . $ext;
    $dest    = $uploadsDir . DIRECTORY_SEPARATOR . $newName;

    if (move_uploaded_file((string) $file['tmp_name'], $dest) === true) {
        return $newName;
    }
    return null;
};

$heroImage    = $processUpload('heroImage');
$posterImage  = $processUpload('posterImage');
$profileImage = $processUpload('profileImage');

$userId = $_SESSION['user_id'] ?? null;

// 🌐 Multi-site scope
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 👁️ EventVisibility (#514 D5, fix round 1: checker finding 6 — a plan gap, not
// a slip in the original P3 build). Imported events are read-only, and so is
// any series that holds one — but before this fix, nothing here re-checked a
// posted seriesID at all. The manage form's own series picker
// (calendar/manage/index.php) no longer OFFERS an imported series, but that
// alone does not stop a forged POST naming one directly: reproduced before
// this fix, posting an imported series's own number created a brand-new,
// entirely ordinary OWN event silently inside an importer-managed series.
// This repeats the exact same NOT EXISTS test the picker and the series page
// itself both use, and — like the venue link resolved just below — silently
// drops an invalid choice to NULL rather than blocking the save with an
// error: choosing "no series" is always a valid choice, so a rejected one
// simply becomes that.
// -----------------------------------------------------------------------------
if ($seriesID !== null) {
    $stmtSeriesCheck = $mysqli->prepare(
        'SELECT s.seriesID FROM tblEventSeries s WHERE s.seriesID = ? AND s.siteID = ? '
        . '  AND NOT EXISTS (SELECT 1 FROM tblEvents xi WHERE xi.seriesID = s.seriesID AND xi.externalFeedID IS NOT NULL) '
        . 'LIMIT 1'
    );
    if ($stmtSeriesCheck !== false) {
        $stmtSeriesCheck->bind_param('ii', $seriesID, $siteId);
        $stmtSeriesCheck->execute();
        $validSeries = $stmtSeriesCheck->get_result()->fetch_assoc();
        $stmtSeriesCheck->close();
        if ($validSeries === null) {
            $seriesID = null;
        }
    } else {
        // Prepare failing is treated the same as "not found": never let a
        // database hiccup silently accept an unchecked series number.
        $seriesID = null;
    }
}

// -----------------------------------------------------------------------------
// 🏛️ Venue Bookings (#429, #436) — resolve the persisted venue/room links.
// Tri-state:
//   $venueLinkActive === false ⇒ Venues disabled/absent/threw ⇒ do NOT
//     touch tblEvents.venueID/roomID at all (UPDATE omits them entirely,
//     below) — an app toggle must never silently wipe an existing link.
//   $venueLinkActive === true  ⇒ write $eventVenueID / $eventRoomID
//     (either may be NULL — invalid/foreign/absent posts silently NULL,
//     never a save-blocking error; this is advisory metadata, not the
//     record of truth).
// Both are validated site+venue scoped (Venues::getVenue()/getRoom()) so
// an event can only ever link a venue/room belonging to ITS OWN site —
// never cross-tenant. roomID never survives without a venueID it
// validated against (clearing/changing the venue always re-validates the
// room, so a stale roomID from a DIFFERENT venue can't persist).
// -----------------------------------------------------------------------------
$venueLinkActive = false;
$eventVenueID    = null;
$eventRoomID     = null;
if (AppRegistry::isEnabled('venues') === true) {
    try {
        $venueLinkActive = true;
        $postedVenue = (int) ($_POST['venueID'] ?? 0);
        $postedRoom  = (int) ($_POST['roomID'] ?? 0);
        if ($postedVenue > 0 && Venues::getVenue($postedVenue, $siteId) !== null) {
            $eventVenueID = $postedVenue;
            if ($postedRoom > 0 && Venues::getRoom($postedRoom, $postedVenue, $siteId) !== null) {
                $eventRoomID = $postedRoom;
            }   // else: silently NULL — never a save-blocking error (advisory feature)
        }       // venue 0/foreign ⇒ both NULL (roomID never survives without its venue)
    } catch (\Throwable $e) {
        $venueLinkActive = false;
        $eventVenueID    = null;
        $eventRoomID     = null;
        error_log('Calendar save: venue link resolution failed: ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// 🏛️ Venue Bookings (#429, #436) Surface B — guarded post-save "is it
// booked?" advisory appended to the flash message. NEVER blocks the save
// (called only after the flash success message is already set). Uses the
// VALIDATED persisted values above (not raw POST) — validation already
// happened, so this closure only classifies + messages.
// -----------------------------------------------------------------------------
$appendVenueCoverageFlash = function (string $eventStart, ?string $eventEnd, string $eventTz) use ($eventVenueID, $eventRoomID): void {
    if ($eventVenueID === null) {
        // 🚪 No resolved venue link ⇒ suppressed — never surface the
        // dormant "no venue configured" sentinel in a save-success flash.
        return;
    }
    try {
        $coverage = Venues::classifyEventCoverage([
            'startDateTime' => $eventStart,
            'endDateTime'   => $eventEnd,
            'timezone'      => $eventTz,
        ], $eventVenueID, $eventRoomID);
        $coverageMsg = (string) ($coverage['message'] ?? '');
        if ($coverageMsg !== '') {
            $_SESSION['flash_msg'] = (string) ($_SESSION['flash_msg'] ?? '') . ' ' . $coverageMsg;
        }
    } catch (\Throwable $e) {
        error_log('Calendar save: venue coverage check failed: ' . $e->getMessage());
    }
};

// The one sentence for a reason that is not stored (#998), shared by create and
// update so the two cannot drift.
$reasonNotKeptWords = 'The reason for cancelling or postponing was not kept, because a reason is kept only while an event is cancelled or postponed.';

// -----------------------------------------------------------------------------
// ➕ Create event
// -----------------------------------------------------------------------------
if ($action === 'create') {
    $endDt = $endDateTime !== '' ? $endDateTime : null;

    // 📋 #436 — column/type/value lists built as arrays (rather than one
    // static literal string) so the optional venueID/roomID pair can be
    // spliced in ONLY when $venueLinkActive is true, with the placeholder
    // count, type-string, and bound-value count guaranteed to stay in
    // lockstep by construction (never hand-counted separately).
    $insertColumns = [
        'eventName', 'eventSlug', 'description', 'startDateTime', 'endDateTime', 'timezone', 'isAllDay',
        'categoryID', 'typeID', 'seriesID', 'status', 'isPublic', 'isFeatured',
        'locationName', 'locationAddress', 'locationWebURL', 'locationGeoLat', 'locationGeoLng',
        'locationW3W', 'locationPhone', 'locationEmail',
        'hostOrgName', 'partnerOrgs', 'heroImage', 'posterImage', 'profileImage',
        // 🌐 #493 P1: always written, from the judgement above — OFF and no
        //    dates unless somebody who may publish set them. Being in this
        //    list is what makes the repeat copies below carry them: the
        //    person who ticked it ticked it for the series they were making
        //    in this same request, and that is recorded in the log.
        'showOnWebsite', 'websiteFrom', 'websiteUntil',
    ];
    $insertTypes = 'ssssssiiiisissssddssssssssiss';
    $insertValues = [
        $eventName, $slug, $description, $startDateTime, $endDt, $timezone, $isAllDay,
        $categoryID, $typeID, $seriesID, $status, $isPublic, $isFeatured,
        $locationName, $locationAddress, $locationWebURL, $locationGeoLat, $locationGeoLng,
        $locationW3W, $locationPhone, $locationEmail,
        $hostOrgName, $partnerOrgs, $heroImage, $posterImage, $profileImage,
        $websiteValues['showOnWebsite'], $websiteValues['websiteFrom'], $websiteValues['websiteUntil'],
    ];

    // ✍️ #998: the reason, decided by `EventCancelReason::toStore()` from the
    //    status (NULL unless it is cancelled or postponed). In the INSERT
    //    itself, not written afterwards, because the dates a repeat makes in
    //    this same request are COPIED from this row's columns: they take its
    //    status, so they take the reason that goes with it.
    $insertColumns[] = 'cancelReason';
    $insertTypes    .= 's';
    $insertValues[]  = EventCancelReason::toStore($status, $cancelReason);

    if ($venueLinkActive === true) {
        $insertColumns[] = 'venueID';
        $insertColumns[] = 'roomID';
        $insertTypes    .= 'ii';
        $insertValues[]  = $eventVenueID;
        $insertValues[]  = $eventRoomID;
    }

    $insertColumns[] = 'createdByID';
    $insertColumns[] = 'updatedByID';
    $insertColumns[] = 'siteID';
    $insertTypes    .= 'iii';
    $insertValues[]  = $userId;
    $insertValues[]  = $userId;
    $insertValues[]  = $siteId;

    $insertPlaceholders = implode(', ', array_fill(0, count($insertColumns), '?'));

    // Imported events are read-only (#514 D5), but that rule is about READING and WRITING an
    // existing imported row, not about this INSERT: $insertColumns never lists externalFeedID, so
    // every row this statement creates takes the column's own default and is externalFeedID IS NULL
    // by construction. (Fix round 1, G7: corrected wording — today `cron/import-feeds.php` is the
    // only code that ever sets that column; #514 P6 REPLACES that importer with a new one, it is
    // not the first thing to set the column.) Marker text for
    // tools/audit-checks/check_event_visibility.py; no condition to add here.
    $stmt = $mysqli->prepare(
        'INSERT INTO tblEvents (' . implode(', ', $insertColumns) . ') VALUES (' . $insertPlaceholders . ')'
    );

    if ($stmt === false) {
        $_SESSION['flash_msg']  = t('error.db_with_detail', ['detail' => $mysqli->error]);
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('calendar/manage'));
        exit();
    }

    // 🔤 A FREE ADDRESS, ASKED OF THE DATABASE AT THE MOMENT OF SAVING (#883).
    //
    //    WHAT WAS HERE. A loop asked "is `stem`, `stem-1`, `stem-2` … taken"
    //    with NO upper bound, then sent the INSERT once. Two things wrong with
    //    that: a loop that can run for ever against a unique key is worse than
    //    a refusal; and between its answer and the INSERT another writer (a
    //    second administrator, an import, the API) could take the address, so
    //    the INSERT was refused and the administrator got an error page with
    //    nothing saved. It also had its own copy of the question, and copies
    //    drift. `Events::saveUnderFreeSlug()` is the one place now: the first
    //    free address of `stem`, `stem-2`, `stem-3` … (bounded, then a random
    //    ending), and if it is taken between asking and saving, the next one,
    //    `Events::SLUG_SAVE_TRIES` times in all.
    //
    // ⚠️ The second event of a name on a day is now `-2`, not `-1` — the shape
    //    the import, the series page and the API give. No installation exists
    //    yet, so no link anybody has kept changes; this is recorded for the
    //    day one does.
    $slugAt = (int) array_search('eventSlug', $insertColumns, true);
    try {
        $slug = Events::saveUnderFreeSlug(
            $mysqli,
            $siteId,
            $slugStem,
            static function (string $candidate) use ($stmt, $insertTypes, $insertValues, $slugAt): void {
                $values           = $insertValues;
                $values[$slugAt]  = $candidate;
                $stmt->bind_param($insertTypes, ...$values);
                if ($stmt->execute() !== true) {
                    throw new \mysqli_sql_exception((string) $stmt->error, (int) $stmt->errno);
                }
            }
        );
    } catch (\mysqli_sql_exception $refused) {
        $stmt->close();
        if (Events::slugWasTaken($refused) === false) {
            // Any other refusal goes where it always went: the portal's own
            // error handling. Only the address clash gets words of its own.
            throw $refused;
        }
        $_SESSION['flash_msg']  = 'The event was not saved: every web address tried for it was taken by another '
            . 'event at the moment it was being saved — most likely somebody else was adding an event with the '
            . 'same name on the same day at the same time. Nothing was saved. Check the calendar, then save it '
            . 'again if it is not there.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('calendar/manage'));
        exit();
    }
    $newEventId = $stmt->insert_id;
    $stmt->close();

    // -------------------------------------------------------------------------
    // 🔁 Repeating events (#569)
    // -------------------------------------------------------------------------
    // The portal could not make one at all before this: there was no control on
    // the form, and nothing in the whole codebase ever wrote
    // `tblRecurrenceRules`, so a weekly service had to be typed in date by
    // date. FEATURES.md promised the feature anyway.
    //
    // HOW THE DATES ARE MADE: `Portal\Core\EventRecurrence` works them out
    // from the rule and the first occurrence's WALL-CLOCK reading, so "every
    // Sunday at 10:00" stays 10:00 through a clock change. Its own header says
    // why that is the opposite rule from `IcsReader`, which reads other
    // people's calendars and must keep each occurrence the same EXACT length.
    //
    // HOW EACH DATE BECOMES AN EVENT: by COPYING THE ROW JUST INSERTED, with
    // only the start, end and slug changed. That is deliberate and is the
    // safest part of this: the copy uses the very column list this handler
    // built a few lines above, so an occurrence carries every field the first
    // one does — audience, venue, category, type, images, the lot — and a
    // column added to the form later is copied without anybody remembering to
    // come back here. Writing out a second INSERT with its own list would drift
    // the moment the two disagreed.
    //
    // WHAT IT DOES NOT DO: change an existing series. Each occurrence is an
    // ordinary event, so moving or cancelling ONE already works through the
    // normal edit and delete; shifting a whole run, or extending it, is its own
    // piece of work and the form does not offer it rather than half-offering.
    $repeatFrequency = (string) ($_POST['repeatFrequency'] ?? '');
    $repeatMade      = 0;
    $repeatNote      = '';
    $ruleWasWritten  = false;

    if (in_array($repeatFrequency, ['weekly', 'fortnightly', 'monthly', 'quarterly', 'yearly'], true) === true) {
        // The browser joins the date and time with a "T" and may leave the
        // seconds off; the generator wants a full reading.
        // 📌 ONE READING, SLICED ONCE. Every date part below comes out of this
        //    text rather than out of a moment built from it (#777 round 10).
        //    `I18n::wallClockFromInput()` is the ONE place a posted time
        //    becomes a stored reading; the `?? $toReading(...)` keeps the old
        //    normalisation for anything it refuses, so no form that worked
        //    before stops working.
        $toReading = static function (string $when): string {
            $when = trim(str_replace('T', ' ', $when));
            return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $when) === 1 ? $when . ':00' : $when;
        };
        $startReading = I18n::wallClockFromInput($startDateTime) ?? $toReading($startDateTime);

        $repeatDays = [];
        foreach ((array) ($_POST['repeatDays'] ?? []) as $day) {
            if (is_scalar($day) === true && preg_match('/^[0-6]$/', (string) $day) === 1) {
                $repeatDays[] = (string) (int) $day;
            }
        }

        $monthlyByWeekday = (string) ($_POST['repeatMonthlyMode'] ?? 'day') === 'weekday';
        $weekOfMonthRaw   = (string) ($_POST['repeatWeekOfMonth'] ?? '');
        $countRaw         = (string) ($_POST['repeatCount'] ?? '');
        $untilRaw         = trim((string) ($_POST['repeatUntil'] ?? ''));

        $rule = [
            'frequency'      => $repeatFrequency,
            'intervalVal'    => 1,
            'dayOfWeek'      => $repeatDays === [] ? null : implode(',', $repeatDays),
            // 🕐 SLICED, NOT RESOLVED -- see the slug above for the two zones
            //    where `date('j', strtotime(...))` names the wrong day. Here it
            //    cost more than a slug: the pattern was stored on the 29th, so
            //    `$firstWanted` disagreed with the typed start and lines
            //    692-700 then UPDATED the event onto the pattern's date, with
            //    the administrator told the first date had moved -- so every
            //    occurrence of the series fell a day late, permanently, and it
            //    read as deliberate.
            'dayOfMonth'     => $monthlyByWeekday === true ? null : (int) substr($startReading, 8, 2),
            'weekOfMonth'    => $monthlyByWeekday === true && preg_match('/^-?[1-5]$/', $weekOfMonthRaw) === 1
                ? (int) $weekOfMonthRaw
                : null,
            'monthOfYear'    => $repeatFrequency === 'yearly'
                ? (int) substr($startReading, 5, 2)
                : null,
            'startDate'      => substr($startReading, 0, 10),
            'endDate'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $untilRaw) === 1 ? $untilRaw : null,
            'maxOccurrences' => preg_match('/^\d+$/', $countRaw) === 1 && (int) $countRaw > 0 ? (int) $countRaw : null,
        ];

        // 🧢 HOW BIG A SERIES MAY BE is this installation's choice, and this
        //    organisation's within it — `calendar.repeat.maxOccurrences` and
        //    `calendar.repeat.maxYears`, set at /admin/settings/repeats.
        //
        //    BOTH LAYERS go in, and the order matters: the INSTALLATION'S
        //    value takes precedence. An organisation may hold itself to a
        //    lower ceiling, but can never raise its own above what the
        //    installation allows (the owner, 29 October 2026) — the two levels
        //    may be different people, a hosting provider and its customers.
        //    `$SETTINGS` is the merged answer and cannot express that on its
        //    own, which is why the settings loader keeps
        //    `$SETTINGS_INSTALLATION` beside it.
        //
        //    These were constants in the class until 29 October 2026. They are
        //    settings because 200 dates and three years are right for a church
        //    with one Sunday service and wrong for a school with a daily
        //    registration period, and this portal is a product rather than one
        //    customer's installation.
        $worked = EventRecurrence::datesFor(
            $rule,
            $toReading($startDateTime),
            $endDt === null ? null : $toReading((string) $endDt),
            $timezone,
            EventRecurrence::limitsFrom($SETTINGS, InstallationSettings::layerOrNull())
        );

        // 📍 THE EVENT JUST CREATED IS MOVED ONTO THE PATTERN'S FIRST DATE.
        //
        //    This block used to say "the first date IS the event just created,
        //    so only the rest are new" and take `array_slice($dates, 1)`. That
        //    is only true when the typed start happens to satisfy the pattern,
        //    and the form lets them disagree in one click — the weekday boxes
        //    and the monthly mode are independent of the date typed above them.
        //
        //    Measured by the independent check: start Wednesday 2028-10-04 with
        //    THURSDAY ticked gave the generator 10-05, 10-12, 10-19, 10-26. The
        //    slice threw away 10-05 — a real occurrence — and the diary kept the
        //    typed Wednesday, a day the pattern never produces. The rule row
        //    then described Thursdays while the series' earliest event was a
        //    Wednesday, so a subscriber expanding it saw 10-05, which does not
        //    exist in the portal, and never saw 10-04, which does.
        //
        //    So the pattern wins: if its first date is not the one typed, the
        //    event is moved onto it. Ticking "Thursday" means the event is on
        //    Thursdays — all of them, including the first. The person is told,
        //    because a date moving by itself would otherwise be a surprise.
        //
        //    The alternative was keeping BOTH the typed date and the pattern's
        //    dates. It was rejected: no single RRULE can describe "this
        //    Wednesday and then every Thursday", so the feed could never be
        //    made to agree with the diary.
        $generated   = $worked['dates'];
        $firstWanted = $generated[0]['start'] ?? null;
        $movedFirst  = '';

        if ($firstWanted !== null && $firstWanted !== $toReading($startDateTime)) {
            $firstEndWanted = $generated[0]['end'];
            $moveFirst      = $mysqli->prepare(
                'UPDATE tblEvents SET startDateTime = ?, endDateTime = ? '
                . 'WHERE eventID = ? AND externalFeedID IS NULL'
            );
            if ($moveFirst !== false) {
                $moveFirst->bind_param('ssi', $firstWanted, $firstEndWanted, $newEventId);
                try {
                    $moveFirst->execute();
                    if ($moveFirst->affected_rows > 0) {
                        $movedFirst = $firstWanted;
                    }
                } catch (\Throwable $e) {
                    Logger::errorPlatform(
                        'Calendar',
                        'Error',
                        'REPEAT_FIRST_MOVE_FAILED',
                        'the first date of a repeating event could not be moved onto the pattern, so no '
                        . 'repeat rule was recorded and the calendar feed will list each date on its own',
                        'eventID=' . $newEventId
                    );
                }
                $moveFirst->close();
            }

            // If it could not be moved, the diary does not match the pattern
            // and must not claim to. Nothing is published as a repeating
            // entry; the dates are still made.
            if ($movedFirst === '') {
                $firstWanted = null;
            }
        }

        // 📌 WHERE THE PATTERN PUT THIS DATE, recorded on the first occurrence
        //    (#669, migration 232). Written for BOTH cases — the date that was
        //    moved onto the pattern a moment ago and the one that already
        //    matched it — because the question it answers later is "is this
        //    date still where the pattern put it", and an unrecorded origin
        //    makes `SeriesRepattern` fall back to re-reading the rule.
        //
        //    A SEPARATE STATEMENT rather than a column on the move above, so
        //    the date that needed no moving is recorded too. Wrapped for the
        //    same reason every other statement in this block is: it runs after
        //    the event row is committed, and a lock-wait timeout here must not
        //    show an error page for an event that exists perfectly well.
        if ($firstWanted !== null) {
            $markFirst = $mysqli->prepare(
                'UPDATE tblEvents SET patternStart = startDateTime, patternEnd = endDateTime '
                . 'WHERE eventID = ? AND externalFeedID IS NULL'
            );
            if ($markFirst !== false) {
                $markFirst->bind_param('i', $newEventId);
                try {
                    $markFirst->execute();
                } catch (\Throwable $e) {
                    Logger::errorPlatform(
                        'Calendar',
                        'Warning',
                        'REPEAT_PATTERN_ORIGIN_UNRECORDED',
                        'the first date of a repeating series could not be marked with where its pattern put it, so a '
                        . 'later pattern change will fall back to re-reading the series rule for that date',
                        'eventID=' . $newEventId
                    );
                }
                $markFirst->close();
            }
        }

        $extra = array_slice($generated, 1);

        if ($extra !== []) {
            // 🗂️ A rule belongs to a SERIES, not to an event — that is how
            //    `tblRecurrenceRules` has always been shaped, and it is why
            //    `calendar/export.php` can already collapse a series with a
            //    rule into one VEVENT with an RRULE in the calendar feed. So
            //    writing the rule makes that existing feature start working
            //    too, without touching it.
            // 🛡️ THE VALIDATED SERIES, NEVER `$_POST` AGAIN. This read
            //    `(int) ($_POST['seriesID'] ?? 0)` and that was a real hole,
            //    measured by the independent check: the validation a few
            //    hundred lines above refuses a series belonging to another
            //    organisation, or one holding an imported event, by setting
            //    `$seriesID = null` (#514, D5). Reading the raw post here went
            //    straight past it — an administrator of one organisation posted
            //    another organisation's series number and the copies landed in
            //    THAT organisation's series, with a rule row owned by this one
            //    attached to it. There is no foreign key tying a series to a
            //    site, so the database accepted it.
            //
            //    The same route put ordinary events inside an importer-managed
            //    series, which is the precise thing #514 exists to prevent.
            $seriesIdForRule = $seriesID ?? 0;

            // 🗂️ Whether THIS request created the series, which decides
            //    whether a rule may be written at all. See the rule block
            //    below: a rule describes a whole series, so it may only exist
            //    where the series holds exactly the dates the pattern makes.
            $seriesWasCreatedHere = ($seriesIdForRule <= 0);

            if ($seriesIdForRule <= 0) {
                $seriesName = $eventName;
                $seriesSlug = $slug;
                $made = $mysqli->prepare(
                    'INSERT INTO tblEventSeries (siteID, seriesName, seriesSlug) VALUES (?, ?, ?)'
                );
                if ($made !== false) {
                    $made->bind_param('iss', $siteId, $seriesName, $seriesSlug);
                    try {
                        $made->execute();
                        $seriesIdForRule = (int) $made->insert_id;
                    } catch (\Throwable) {
                        // The slug is unique per organisation and the event's
                        // own slug already carries its date, so a clash means
                        // two events of the same name on the same day. Adding
                        // the event's number cannot clash with anything.
                        $seriesIdForRule = 0;
                    }
                    $made->close();
                }
                if ($seriesIdForRule <= 0) {
                    $seriesSlug = $slug . '-' . $newEventId;
                    $made = $mysqli->prepare(
                        'INSERT INTO tblEventSeries (siteID, seriesName, seriesSlug) VALUES (?, ?, ?)'
                    );
                    if ($made !== false) {
                        $made->bind_param('iss', $siteId, $seriesName, $seriesSlug);
                        $made->execute();
                        $seriesIdForRule = (int) $made->insert_id;
                        $made->close();
                    }
                }
                // The event already created has to join the series too, or the
                // first occurrence would sit outside its own run.
                if ($seriesIdForRule > 0) {
                    // `externalFeedID IS NULL` is not a formality here:
                    // FeedImporter is the ONE writer of an imported event
                    // (#514), so nothing in this handler may change one. It
                    // cannot happen today — the row was created a moment ago
                    // by this same request — but the guard costs nothing and
                    // states the rule where somebody would otherwise have to
                    // know it.
                    $join = $mysqli->prepare(
                        'UPDATE tblEvents SET seriesID = ? WHERE eventID = ? AND externalFeedID IS NULL'
                    );
                    if ($join !== false) {
                        $join->bind_param('ii', $seriesIdForRule, $newEventId);
                        // 🛡️ Wrapped: runs after the event row is committed, and
                        //    under strict mysqli a lock-wait timeout would show
                        //    an error page for an event that exists.
                        try {
                            $join->execute();
                        } catch (\Throwable $e) {
                            Logger::errorPlatform(
                                'Calendar',
                                'Error',
                                'REPEAT_SERIES_JOIN_FAILED',
                                'the first date of a repeating event could not be joined to its series, so the '
                                . 'calendar feed will list each date on its own',
                                'eventID=' . $newEventId . ' seriesID=' . $seriesIdForRule
                            );
                        }
                        $join->close();
                    }
                }
            }

            // 🧹 ATTACHING TO A SERIES SOMEBODY ELSE ALREADY MADE: any rule
            //    it carries stops being true the moment these dates join it,
            //    so it is removed and none is written.
            //
            //    `export.php` reads a series' rule with `ORDER BY ruleID LIMIT
            //    1` and then skips every other event of that series, so leaving
            //    a stale rule in place would publish the OLD pattern and make
            //    this run's dates vanish from every subscriber's calendar
            //    entirely. Leaving a SECOND rule row would do the same, because
            //    only the oldest is ever read. With no rule, the feed lists each
            //    event on its own: less tidy, always true.
            if ($seriesWasCreatedHere === false && $seriesIdForRule > 0) {
                // 🛡️ Wrapped for the same reason the rule INSERT is: this runs
                //    AFTER the event row is committed, mysqli is in strict mode
                //    (bootstrap sets MYSQLI_REPORT_STRICT|MYSQLI_REPORT_ERROR),
                //    and a lock-wait timeout here would give the member an
                //    error page for an event that was created perfectly well.
                $dropRule = $mysqli->prepare('DELETE FROM tblRecurrenceRules WHERE seriesID = ? AND siteID = ?');
                if ($dropRule !== false) {
                    $dropRule->bind_param('ii', $seriesIdForRule, $siteId);
                    try {
                        $dropRule->execute();
                    } catch (\Throwable $e) {
                        Logger::errorPlatform(
                            'Calendar',
                            'Error',
                            'REPEAT_RULE_STALE',
                            'the old repeat rule of a series these dates joined could not be removed, so the '
                            . 'calendar feed may describe that series by its previous pattern',
                            'eventID=' . $newEventId . ' seriesID=' . $seriesIdForRule
                        );
                    }
                    $dropRule->close();
                }
            }

            if ($seriesIdForRule > 0 && $seriesWasCreatedHere === true && $firstWanted !== null) {
                // 📝 THE RULE ROW DESCRIBES THE DATES THAT WERE REALLY MADE,
                //    not what the form asked for. This matters far more than it
                //    looks, and the first version got it wrong in two ways that
                //    the independent check measured.
                //
                //    `calendar/export.php` builds ONE repeating entry from this
                //    row for everybody who subscribes to the calendar. So this
                //    row is not a record of the request — it is a PUBLISHED
                //    PROMISE about what is in the diary, and if the two
                //    disagree, every subscriber's calendar is wrong.
                //
                //    What went wrong:
                //      • With both stop fields left empty — which the form
                //        invites — the row carried no count and no end date, so
                //        the feed sent `RRULE:FREQ=WEEKLY` with no COUNT and no
                //        UNTIL: a FOREVER-repeating event, while the diary held
                //        five dates.
                //      • Posting `repeatCount=400` past the form stored 400, so
                //        the feed published a 400-date series on behalf of an
                //        installation that had capped the organisation at five.
                //        That was a route past the installation's limit — not
                //        into `tblEvents`, but into what the installation
                //        publishes, which is worse.
                //
                //    So `maxOccurrences` is the number of dates the pattern
                //    produced, and `endDate` is deliberately NULL. Both are
                //    needed: `export.php` prefers UNTIL over COUNT when both
                //    are present, and an UNTIL taken from the request would
                //    overshoot a series our ceiling had already stopped.
                //    Leaving the asked-for end date out costs nothing a
                //    subscriber can see — COUNT pins the series exactly — and
                //    it is the only way the two cannot drift apart.
                //
                //    BE PRECISE about this number, because an earlier comment
                //    here was not: it is the number GENERATED, bound before the
                //    copies run. If a copy then fails, the whole rule row is
                //    REMOVED after the loop rather than left over-promising —
                //    see the clean-up at the end of this block. So a rule that
                //    survives does describe exactly what is in the diary; this
                //    one statement on its own does not guarantee that.
                $datesMade = count($generated);

                // 📅 AND ONLY THE FIELDS THAT APPLY TO THIS FREQUENCY.
                //    Writing all of them regardless was the other fault. A
                //    weekly series stored `dayOfMonth = 3`, which is meaningless
                //    for a weekly rule; worse, choosing "every month, the same
                //    date" while a weekday box happened to be ticked stored
                //    BOTH, and `export.php` prefers `dayOfWeek`, so the feed
                //    published "every Monday of every month" against one date a
                //    month in the diary. The form shows every box at once on
                //    purpose (no JavaScript), so a box that does not apply being
                //    filled in is the ordinary case, not a strange one.
                $byWeekday   = $monthlyByWeekday === true && $rule['weekOfMonth'] !== null;
                $isWeekly    = in_array($repeatFrequency, ['weekly', 'fortnightly'], true);
                $isYearly    = $repeatFrequency === 'yearly';

                $ruleDayOfWeek = null;
                if ($isWeekly === true) {
                    $ruleDayOfWeek = $rule['dayOfWeek'];
                } elseif ($byWeekday === true) {
                    // The generator uses only the FIRST ticked weekday for an
                    // nth-weekday pattern, so storing more would describe
                    // dates that were never made.
                    $firstDay      = $rule['dayOfWeek'] === null ? null : explode(',', (string) $rule['dayOfWeek'])[0];
                    $ruleDayOfWeek = $firstDay === null || $firstDay === '' ? null : $firstDay;
                }

                $ruleDayOfMonth  = ($isWeekly === false && $byWeekday === false) ? $rule['dayOfMonth'] : null;
                $ruleWeekOfMonth = $byWeekday === true ? $rule['weekOfMonth'] : null;
                $ruleMonthOfYear = $isYearly === true ? $rule['monthOfYear'] : null;
                // 📅 `endDate` is deliberately never written, so
                //    `tblRecurrenceRules.endDate` is a column nothing fills and the
                //    UNTIL branch of the feed's rule builder is unreachable. See the
                //    note above for why: UNTIL beats COUNT in that builder, and an
                //    UNTIL taken from the request would outlive a series our own
                //    ceiling had stopped.
                $ruleEndDate     = null;
                $ruleStartDate   = substr((string) $firstWanted, 0, 10);

                $ruleStmt = $mysqli->prepare(
                    'INSERT INTO tblRecurrenceRules '
                    . '(siteID, seriesID, frequency, intervalVal, dayOfWeek, dayOfMonth, weekOfMonth, monthOfYear, startDate, endDate, maxOccurrences) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($ruleStmt !== false) {
                    $ruleStmt->bind_param(
                        'iisisiiissi',
                        $siteId,
                        $seriesIdForRule,
                        $rule['frequency'],
                        $rule['intervalVal'],
                        $ruleDayOfWeek,
                        $ruleDayOfMonth,
                        $ruleWeekOfMonth,
                        $ruleMonthOfYear,
                        // 📅 The series' OWN first date, not the one typed on the
                        //    form. They differ whenever the first event was
                        //    moved onto the pattern, and this column is the one
                        //    field in the row that would otherwise still
                        //    describe a date the series does not contain.
                        //    Nothing reads it today (`export.php` does not
                        //    select it), which is exactly why it would have
                        //    stayed wrong unnoticed.
                        $ruleStartDate,
                        $ruleEndDate,
                        $datesMade
                    );
                    // 🛡️ Wrapped, unlike the first version. The series number
                    //    is validated now, so a missing series cannot arrive
                    //    through the form — but one can be deleted between that
                    //    check and this statement, and an uncaught foreign-key
                    //    error here gave HTTP 500 AFTER the event row was
                    //    already committed: the member saw an error page, one
                    //    event existed, no repeats, and nothing was rolled back
                    //    (measured). The event is real and worth keeping; the
                    //    rule failing is worth recording, not worth losing it
                    //    over.
                    try {
                        $ruleStmt->execute();
                        $ruleWasWritten = true;
                    } catch (\Throwable $e) {
                        Logger::errorPlatform(
                            'Calendar',
                            'Error',
                            'REPEAT_RULE_FAILED',
                            'the repeat rule of a new series could not be saved, so the calendar feed will '
                            . 'list each date on its own rather than as one repeating entry',
                            'eventID=' . $newEventId . ' seriesID=' . $seriesIdForRule
                        );
                    }
                    $ruleStmt->close();
                }
            }

            // 📋 MAKING THE DATES IS NOT CONDITIONAL ON WRITING A RULE, and
            //    keeping those two apart matters: this block sat INSIDE the
            //    rule condition for a while, so joining an existing series —
            //    where no rule may be written — silently created one event and
            //    no repeats at all. Caught by check M10.
            //
            //    The dates are what the person asked for. The rule is only how
            //    the calendar feed describes them, and it is absent whenever it
            //    could not describe them truthfully.
            if ($seriesIdForRule > 0) {
                // 📋 The copy. Every column the form writes, taken from the row
                //    just created, with the three that must differ replaced.
                $copyColumns = $insertColumns;
                // 📌 WHERE THE PATTERN PUT THIS DATE (#669, migration 232).
                //    Added here rather than to `$insertColumns`, because that
                //    list is what the FORM writes and a one-off event has no
                //    pattern — only a generated copy does.
                $copyColumns[] = 'patternStart';
                // 🕰️ And the finish the pattern gave it. BOTH are compared when
                //    a later pattern change asks "has anybody moved this date",
                //    because a move writes both — recording only the start let a
                //    hand-lengthened service be silently shortened again (#669).
                $copyColumns[] = 'patternEnd';
                $selectParts = [];
                foreach ($copyColumns as $column) {
                    if ($column === 'startDateTime' || $column === 'endDateTime' || $column === 'eventSlug'
                        || $column === 'patternStart' || $column === 'patternEnd'
                    ) {
                        $selectParts[] = '?';
                        continue;
                    }
                    if ($column === 'seriesID') {
                        $selectParts[] = '?';
                        continue;
                    }
                    $selectParts[] = '`' . $column . '`';
                }
                $copySql = 'INSERT INTO tblEvents (`' . implode('`, `', $copyColumns) . '`) '
                    // AND externalFeedID IS NULL: an imported event must
                    // never be COPIED either. That would make a portal-owned
                    // clone of somebody else's event, which no feed refresh
                    // would ever tidy up, and FeedImporter is the only thing
                    // allowed to create one (#514).
                    . 'SELECT ' . implode(', ', $selectParts) . ' FROM tblEvents WHERE eventID = ? AND externalFeedID IS NULL';
                $copyStmt = $mysqli->prepare($copySql);

                if ($copyStmt !== false) {
                    foreach ($extra as $index => $occurrence) {
                        $occStart = (string) $occurrence['start'];
                        $occEnd   = $occurrence['end'];
                        // 🔤 EACH DATE GETS ITS OWN ADDRESS, NAME AND ITS OWN
                        //    DATE, ASKED OF THE DATABASE (#883). It was the
                        //    FIRST date's address plus `-2`, `-3` … — so the
                        //    fourth Sunday's address named the first Sunday's
                        //    date — and it was never asked of the database, so
                        //    an address somebody already held made that one
                        //    date fail to save (caught below and logged, the
                        //    repeat rule then withdrawn). The value is filled
                        //    in by `Events::saveUnderFreeSlug()` below.
                        $occSlug   = '';
                        $occSlugAt = -1;
                        $params   = [];
                        foreach ($copyColumns as $column) {
                            if ($column === 'startDateTime') {
                                $params[] = $occStart;
                            } elseif ($column === 'endDateTime') {
                                $params[] = $occEnd;
                            } elseif ($column === 'eventSlug') {
                                // Its position among the PLACEHOLDERS, which is
                                // not its position in `$copyColumns`: most
                                // columns are copied by name, not bound.
                                $occSlugAt = count($params);
                                $params[]  = $occSlug;
                            } elseif ($column === 'seriesID') {
                                $params[] = $seriesIdForRule;
                            } elseif ($column === 'patternStart') {
                                // The same reading as the start, because the
                                // pattern is what put it there. They only
                                // differ once somebody moves the date by hand,
                                // which is exactly what makes the comparison
                                // worth storing.
                                $params[] = $occStart;
                            } elseif ($column === 'patternEnd') {
                                $params[] = $occEnd;
                            }
                        }
                        $params[] = $newEventId;
                        $types    = str_repeat('s', count($params) - 1) . 'i';
                        try {
                            Events::saveUnderFreeSlug(
                                $mysqli,
                                $siteId,
                                Events::slugStem($eventName, $occStart),
                                static function (string $candidate) use ($copyStmt, $types, $params, $occSlugAt): void {
                                    $params[$occSlugAt] = $candidate;
                                    $copyStmt->bind_param($types, ...$params);
                                    if ($copyStmt->execute() !== true) {
                                        throw new \mysqli_sql_exception((string) $copyStmt->error, (int) $copyStmt->errno);
                                    }
                                }
                            );
                            // affected_rows, not a blind increment: the
                            // `externalFeedID IS NULL` guard in the SELECT can
                            // match nothing, and reporting dates that were
                            // never created is the kind of untrue message this
                            // project treats as a fault in its own right.
                            if ($copyStmt->affected_rows > 0) {
                                $repeatMade++;
                            }
                        } catch (\Throwable $e) {
                            // One date that will not save must not lose the
                            // rest, and must not be hidden either.
                            Logger::errorPlatform(
                                'Calendar',
                                'Error',
                                'REPEAT_DATE_FAILED',
                                'one date of a repeating event could not be created',
                                'eventID=' . $newEventId . ' date=' . $occStart
                            );
                        }
                    }
                    $copyStmt->close();
                }
            }

            // 🧹 THE INVARIANT, ENFORCED AFTER THE FACT: a rule row may exist
            //    only where the series holds EXACTLY the dates that rule makes.
            //
            //    `maxOccurrences` above was bound before the copies ran, so it
            //    is the number of dates GENERATED. If any copy failed — each is
            //    caught and logged rather than losing the rest — the series has
            //    fewer rows than the pattern, and a `COUNT` matching the
            //    pattern would publish dates that are not in the diary while a
            //    missing middle date would still be sent. There is no way to
            //    describe that with one RRULE (#670), so the rule is removed and
            //    the feed lists each event on its own.
            if ($ruleWasWritten === true && ($repeatMade + 1) !== count($generated)) {
                // 🛡️ Wrapped: see the other DELETE above. A failure here is the
                //    worst of the three, because it would leave the
                //    over-promising rule in place AND show an error page — so
                //    the feed's own guard (export.php checks a rule against its
                //    series before using it) is what actually makes this safe.
                $dropRule = $mysqli->prepare('DELETE FROM tblRecurrenceRules WHERE seriesID = ? AND siteID = ?');
                if ($dropRule !== false) {
                    $dropRule->bind_param('ii', $seriesIdForRule, $siteId);
                    try {
                        $dropRule->execute();
                    } catch (\Throwable $e) {
                        // Recorded by the warning just below, which says the
                        // feed will fall back to listing each date.
                    }
                    $dropRule->close();
                }
                Logger::errorPlatform(
                    'Calendar',
                    'Warning',
                    'REPEAT_RULE_WITHDRAWN',
                    'not every date of a repeating series could be created, so its repeat rule was removed '
                    . 'and the calendar feed will list each date on its own rather than as one repeating entry',
                    'eventID=' . $newEventId . ' seriesID=' . $seriesIdForRule
                    . ' made=' . ($repeatMade + 1) . ' expected=' . count($generated)
                );
            }
        }

        $repeatNote = $repeatMade > 0
            ? ' ' . $repeatMade . ' more date(s) were added.'
            : ' No extra dates were added.';

        // 📍 Say it when the first date moved, and say WHY truthfully.
        //
        //    The pattern wins over the date typed above it (ticking Thursday
        //    means Thursdays), but a date changing by itself has to be stated
        //    or it looks like a fault.
        //
        // 🚨 THREE CAUSES, THREE SENTENCES, AND ONE PLACE DECIDES WHICH.
        //    `EventRecurrence::whyFirstDateMoved()` takes the reading that was
        //    TYPED and the reading that was STORED and answers which happened.
        //    It is shared with `event-recurrence-selftest.php`'s check J14, so
        //    the page and the test cannot disagree (#647) -- round 13 decided
        //    this inline here and re-implemented it in the test, and changing
        //    ONE WORD here then left every gate and all 251 HTTP checks green.
        //
        //    Two earlier versions of this got the REASON wrong, both measured:
        //
        //      • Round 12 showed one sentence for two causes, so a date moved
        //        by the missing hour was blamed on "the repeat you chose" --
        //        pointing the administrator at a control that was doing
        //        exactly what they asked.
        //      • Round 13 chose the branch from whether the TYPED reading was
        //        real, which is a different question from what was STORED and
        //        was wrong both ways round: London, typed 2026-03-29T01:30
        //        with Monday ticked, got the clocks sentence although 01:30
        //        exists on the 30th; and typed 2026-03-28T01:30 with Sunday
        //        ticked got the pattern sentence with no word about its hour
        //        moving to 02:30.
        if ($movedFirst !== '') {
            $typedReading = $toReading($startDateTime);
            $why          = EventRecurrence::whyFirstDateMoved($typedReading, $movedFirst, $timezone);

            $movedOn = I18n::formatClockDateTime($movedFirst, 'j F Y');
            $movedAt = I18n::formatClockDateTime($movedFirst, 'H:i');

            if ($why === 'gap') {
                // 🚨 THE DATE THIS NAMES MATTERS. An earlier version said the
                //    typed time "does not exist on that date" while naming the
                //    MOVED-TO date -- and in the two zones whose clocks go
                //    forward at 23:00, which is the case this branch was built
                //    for, the time exists perfectly well on that date.
                //    Measured: 23:30 on 29 March 2026 in America/Nuuk is a
                //    real reading; it is 28 March that has no 23:30. So the
                //    sentence names the TYPED date and time, where the claim
                //    is true, and the stored one separately.
                $repeatNote .= ' The first date was moved to ' . $movedOn
                    . ' at ' . $movedAt . ', because the clocks go forward through '
                    . I18n::formatClockDateTime($typedReading, 'H:i') . ' on '
                    . I18n::formatClockDateTime($typedReading, 'j F Y')
                    . ' in the time zone this event uses, so that time does not'
                    . ' exist. Any later dates keep the time you typed.';
            } elseif ($why === 'pattern and time') {
                $repeatNote .= ' The first date was moved to ' . $movedOn
                    . ' to match the repeat you chose, and its time became '
                    . $movedAt . ' because the clocks go forward through the'
                    . ' time you typed on that date.';
            } else {
                $repeatNote .= ' The first date was moved to ' . $movedOn
                    . ' to match the repeat you chose.';
            }
        }

        if ($worked['why'] !== '') {
            $repeatNote .= ' ' . $worked['why'];
        }
    }

    Logger::activity('EventCreated', 'Created event: ' . $eventName . ' (ID:' . $newEventId . ')', $userId);
    \Portal\Core\PublicWebsite::recordChange(
        (int) $newEventId,
        $eventName,
        $websiteJudged['changes'],
        $userId === null ? null : (int) $userId,
        'the Manage Events form, a new event' . ($repeatMade > 0 ? ' and the ' . $repeatMade . ' other dates of its series' : '')
    );

    $_SESSION['flash_msg']  = 'Event "' . $eventName . '" created successfully.' . $repeatNote . ($geoWarning !== null ? ' ' . $geoWarning : '')
        . ($cancelReasonNotKept === true ? ' ' . $reasonNotKeptWords : '');
    $_SESSION['flash_type'] = 'success';
    $appendVenueCoverageFlash($startDateTime, $endDt, $timezone);
    header('Location: ' . Site::url('calendar/manage'));
    exit();
}

// -----------------------------------------------------------------------------
// ✏️ Update event
// -----------------------------------------------------------------------------
if ($action === 'update') {
    $eventID = (int) ($_POST['eventID'] ?? 0);
    if ($eventID <= 0) {
        $_SESSION['flash_msg']  = 'Invalid event ID.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('calendar/manage'));
        exit();
    }

    // 📋 Build update fields (only update images if new ones uploaded)
    $setClauses = [
        'eventName = ?', 'description = ?', 'startDateTime = ?', 'endDateTime = ?',
        'timezone = ?', 'isAllDay = ?', 'categoryID = ?', 'typeID = ?', 'seriesID = ?',
        'status = ?', 'isPublic = ?', 'isFeatured = ?',
        'locationName = ?', 'locationAddress = ?', 'locationWebURL = ?',
        'locationGeoLat = ?', 'locationGeoLng = ?', 'locationW3W = ?',
        'locationPhone = ?', 'locationEmail = ?',
        'hostOrgName = ?', 'partnerOrgs = ?', 'updatedByID = ?'
    ];
    $endDt = $endDateTime !== '' ? $endDateTime : null;
    $paramTypes = 'sssssiiiisissssddsssssi';
    $paramValues = [
        $eventName, $description, $startDateTime, $endDt,
        $timezone, $isAllDay, $categoryID, $typeID, $seriesID,
        $status, $isPublic, $isFeatured,
        $locationName, $locationAddress, $locationWebURL,
        $locationGeoLat, $locationGeoLng, $locationW3W,
        $locationPhone, $locationEmail,
        $hostOrgName, $partnerOrgs, $userId
    ];

    if ($heroImage !== null) {
        $setClauses[]  = 'heroImage = ?';
        $paramTypes   .= 's';
        $paramValues[] = $heroImage;
    }
    if ($posterImage !== null) {
        $setClauses[]  = 'posterImage = ?';
        $paramTypes   .= 's';
        $paramValues[] = $posterImage;
    }
    if ($profileImage !== null) {
        $setClauses[]  = 'profileImage = ?';
        $paramTypes   .= 's';
        $paramValues[] = $profileImage;
    }

    // 🏛️ #436 — only touch venueID/roomID when the guard is active. When
    // Venues is disabled/absent/threw, these columns are OMITTED from the
    // SET list entirely — an app toggle must never silently wipe an
    // existing link (the write-path mirror of the render-path
    // byte-identical rule below).
    if ($venueLinkActive === true) {
        $setClauses[]  = 'venueID = ?';
        $setClauses[]  = 'roomID = ?';
        $paramTypes   .= 'ii';
        $paramValues[] = $eventVenueID;
        $paramValues[] = $eventRoomID;
    }

    // 🌐 #493 P1: only when the judgement found a CHANGE, which it allows
    //    only for somebody who may publish. Otherwise the three columns are
    //    left out of the SET entirely, so an edit can never clear them.
    if ($websiteJudged['changed'] === true) {
        $setClauses[]  = '`showOnWebsite` = ?';
        $setClauses[]  = '`websiteFrom` = ?';
        $setClauses[]  = '`websiteUntil` = ?';
        $paramTypes   .= 'iss';
        $paramValues[] = $websiteValues['showOnWebsite'];
        $paramValues[] = $websiteValues['websiteFrom'];
        $paramValues[] = $websiteValues['websiteUntil'];
    }

    $paramTypes   .= 'ii';
    $paramValues[] = $eventID;
    $paramValues[] = $siteId;

    // Imported events are read-only (#514 D5). Nothing in the portal can link to this form for an
    // imported event any more (manage/index.php's edit load and list both exclude them), but a posted
    // eventID is never trusted on its own, so the condition is repeated here: a forged post against an
    // imported event's id updates 0 rows rather than silently overwriting a row the next refresh from
    // the source calendar would just overwrite again anyway.
    //
    // ♻️ `isDeleted = 0` on the write itself too (#1000): a hidden date is
    //    changed only by bringing it back (`HiddenSeriesDates`).
    $sql = 'UPDATE tblEvents SET ' . implode(', ', $setClauses) . ' WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL';

    // 🔒 CHECK, THEN WRITE, UNDER ONE LOCK (#1000, #998). The refusal near the
    //    top of this file read the row without a lock, so another
    //    administrator could hide it (the Delete button, the pattern page, the
    //    API) between that read and this write. The transaction opens FIRST
    //    and the row is then read `FOR UPDATE` (#697: a lock outside a
    //    transaction is decoration), so "it is live" stays true until the
    //    commit, and the status and its reason land together.
    $reasonCleared    = 0;
    $refusedUnderLock = null;
    $mysqli->begin_transaction();
    try {
        $lockStmt = $mysqli->prepare(
            'SELECT `isDeleted`, `seriesID` FROM `tblEvents` '
            . 'WHERE `eventID` = ? AND `siteID` = ? AND `externalFeedID` IS NULL FOR UPDATE'
        );
        $lockStmt->bind_param('ii', $eventID, $siteId);
        $lockStmt->execute();
        $lockedRow = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();

        if ($lockedRow === null) {
            $refusedUnderLock = ['words' => 'That event could not be found here, so nothing was saved.', 'type' => 'danger', 'to' => Site::url('calendar/manage')];
        } elseif ((int) $lockedRow['isDeleted'] === 1) {
            $lockedSeries     = $lockedRow['seriesID'] === null ? null : (int) $lockedRow['seriesID'];
            $refusedUnderLock = [
                'words' => HiddenSeriesDates::editRefusalWords($lockedSeries, true),
                'type'  => 'warning',
                'to'    => $lockedSeries !== null
                    ? Site::url('calendar/manage/series-edit') . '?seriesID=' . $lockedSeries
                    : Site::url('calendar/manage'),
            ];
        }

        if ($refusedUnderLock === null) {
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param($paramTypes, ...$paramValues);
            $stmt->execute();
            $stmt->close();

            // ✍️ The reason, by its one writer, from the status the row now
            //    holds. The form always sends the box, so `$cancelReasonArrived`
            //    is true from it; a hand-made post without the field keeps a
            //    kept reason.
            $reasonCleared = EventCancelReason::write($mysqli, $siteId, [$eventID], $cancelReasonArrived, $cancelReason)['cleared'];
            $mysqli->commit();
        } else {
            $mysqli->rollback();
        }
    } catch (\Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }

    if ($refusedUnderLock !== null) {
        $_SESSION['flash_msg']  = $refusedUnderLock['words'];
        $_SESSION['flash_type'] = $refusedUnderLock['type'];
        header('Location: ' . $refusedUnderLock['to']);
        exit();
    }

    Logger::activity('EventUpdated', 'Updated event #' . $eventID . ': ' . $eventName, $userId);
    // Recorded only when the row was really found: a change judged against an
    // event that does not exist here (`$storedWebsiteFound` false) was judged
    // from OFF and wrote nothing, and saying it was published would be untrue.
    if ($storedWebsiteFound === true) {
        \Portal\Core\PublicWebsite::recordChange($eventID, $eventName, $websiteJudged['changes'], $userId === null ? null : (int) $userId, 'the Manage Events form');
    }

    $_SESSION['flash_msg']  = 'Event "' . $eventName . '" updated successfully.' . ($geoWarning !== null ? ' ' . $geoWarning : '')
        . ($cancelReasonNotKept === true || $reasonCleared > 0 ? ' ' . $reasonNotKeptWords : '');
    $_SESSION['flash_type'] = 'success';
    $appendVenueCoverageFlash($startDateTime, $endDt, $timezone);
    header('Location: ' . Site::url('calendar/manage'));
    exit();
}

// 🚫 Unknown action
$_SESSION['flash_msg']  = 'Unknown action.';
$_SESSION['flash_type'] = 'warning';
header('Location: ' . Site::url('calendar/manage'));
exit();
