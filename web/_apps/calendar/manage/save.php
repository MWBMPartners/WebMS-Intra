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
use Portal\Core\EventRecurrence;
use Portal\Core\Events;
use Portal\Core\Geocoder;
use Portal\Core\GeoLocation;
use Portal\Core\InstallationSettings;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;
use Portal\Core\What3Words;

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
$status        = $_POST['status'] ?? 'draft';
$isPublic      = isset($_POST['isPublic']) === true ? 1 : 0;
$isFeatured    = isset($_POST['isFeatured']) === true ? 1 : 0;

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
if ($action === 'update') {
    // The portal's own events only (`externalFeedID IS NULL`): an event copied in
    // from an outside calendar cannot be edited here at all (the UPDATE below
    // refuses one too), so its zone is none of this form's business.
    $zoneStmt = $mysqli->prepare('SELECT timezone FROM tblEvents WHERE eventID = ? AND siteID = ? AND externalFeedID IS NULL LIMIT 1');
    if ($zoneStmt !== false) {
        $zoneEventId = (int) ($_POST['eventID'] ?? 0);
        $zoneSiteId  = Site::id();
        $zoneStmt->bind_param('ii', $zoneEventId, $zoneSiteId);
        $zoneStmt->execute();
        $zoneRow = $zoneStmt->get_result()->fetch_assoc();
        $zoneStmt->close();
        $storedZone = $zoneRow !== null ? (string) $zoneRow['timezone'] : null;
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

// 🔤 Generate slug
$slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $eventName), '-'));
// 📅 Append date for uniqueness
$slug .= '-' . date('Y-m-d', strtotime($startDateTime));

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
    finfo_close($finfo);

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

// -----------------------------------------------------------------------------
// ➕ Create event
// -----------------------------------------------------------------------------
if ($action === 'create') {
    // 🔍 Ensure slug is unique
    $originalSlug = $slug;
    $counter = 1;
    while (true) {
        // 🌐 #339 — scope the uniqueness probe to the current site so a slug
        //    already taken on another site doesn't needlessly suffix this one
        //    (and so this isn't a cross-tenant existence oracle).
        $stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventSlug = ? AND siteID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('si', $slug, $siteId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($exists === null) {
                break;
            }
        }
        $slug = $originalSlug . '-' . $counter;
        $counter++;
    }

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
    ];
    $insertTypes = 'ssssssiiiisissssddssssssss';
    $insertValues = [
        $eventName, $slug, $description, $startDateTime, $endDt, $timezone, $isAllDay,
        $categoryID, $typeID, $seriesID, $status, $isPublic, $isFeatured,
        $locationName, $locationAddress, $locationWebURL, $locationGeoLat, $locationGeoLng,
        $locationW3W, $locationPhone, $locationEmail,
        $hostOrgName, $partnerOrgs, $heroImage, $posterImage, $profileImage,
    ];

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

    $stmt->bind_param($insertTypes, ...$insertValues);
    $stmt->execute();
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
        $toReading = static function (string $when): string {
            $when = trim(str_replace('T', ' ', $when));
            return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $when) === 1 ? $when . ':00' : $when;
        };

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
            'dayOfMonth'     => $monthlyByWeekday === true ? null : (int) date('j', (int) strtotime($toReading($startDateTime))),
            'weekOfMonth'    => $monthlyByWeekday === true && preg_match('/^-?[1-5]$/', $weekOfMonthRaw) === 1
                ? (int) $weekOfMonthRaw
                : null,
            'monthOfYear'    => $repeatFrequency === 'yearly'
                ? (int) date('n', (int) strtotime($toReading($startDateTime)))
                : null,
            'startDate'      => date('Y-m-d', (int) strtotime($toReading($startDateTime))),
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
                $selectParts = [];
                foreach ($copyColumns as $column) {
                    if ($column === 'startDateTime' || $column === 'endDateTime' || $column === 'eventSlug') {
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
                        $occSlug  = $slug . '-' . ($index + 2);
                        $params   = [];
                        foreach ($copyColumns as $column) {
                            if ($column === 'startDateTime') {
                                $params[] = $occStart;
                            } elseif ($column === 'endDateTime') {
                                $params[] = $occEnd;
                            } elseif ($column === 'eventSlug') {
                                $params[] = $occSlug;
                            } elseif ($column === 'seriesID') {
                                $params[] = $seriesIdForRule;
                            }
                        }
                        $params[] = $newEventId;
                        $types    = str_repeat('s', count($params) - 1) . 'i';
                        $copyStmt->bind_param($types, ...$params);
                        try {
                            $copyStmt->execute();
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

        // 📍 Say it when the first date moved. The pattern wins over the date
        //    typed above it (ticking Thursday means Thursdays), but a date
        //    changing by itself has to be stated or it looks like a fault.
        if ($movedFirst !== '') {
            $repeatNote .= ' The first date was moved to '
                . date('j F Y', (int) strtotime(substr($movedFirst, 0, 10)))
                . ' to match the repeat you chose.';
        }

        if ($worked['why'] !== '') {
            $repeatNote .= ' ' . $worked['why'];
        }
    }

    Logger::activity('EventCreated', 'Created event: ' . $eventName . ' (ID:' . $newEventId . ')', $userId);

    $_SESSION['flash_msg']  = 'Event "' . $eventName . '" created successfully.' . $repeatNote . ($geoWarning !== null ? ' ' . $geoWarning : '');
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

    $paramTypes   .= 'ii';
    $paramValues[] = $eventID;
    $paramValues[] = $siteId;

    // Imported events are read-only (#514 D5). Nothing in the portal can link to this form for an
    // imported event any more (manage/index.php's edit load and list both exclude them), but a posted
    // eventID is never trusted on its own, so the condition is repeated here: a forged post against an
    // imported event's id updates 0 rows rather than silently overwriting a row the next refresh from
    // the source calendar would just overwrite again anyway.
    $sql = 'UPDATE tblEvents SET ' . implode(', ', $setClauses) . ' WHERE eventID = ? AND siteID = ? AND externalFeedID IS NULL';

    $stmt = $mysqli->prepare($sql);
    if ($stmt !== false) {
        $stmt->bind_param($paramTypes, ...$paramValues);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('EventUpdated', 'Updated event #' . $eventID . ': ' . $eventName, $userId);

    $_SESSION['flash_msg']  = 'Event "' . $eventName . '" updated successfully.' . ($geoWarning !== null ? ' ' . $geoWarning : '');
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
