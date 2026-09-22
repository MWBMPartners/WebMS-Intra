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
use Portal\Core\Geocoder;
use Portal\Core\GeoLocation;
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
    header('Location: /calendar/manage');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /calendar/manage');
    exit();
}

$action = $_POST['action'] ?? '';

// -----------------------------------------------------------------------------
// 📋 Collect form data
// -----------------------------------------------------------------------------
$eventName     = trim($_POST['eventName'] ?? '');
$description   = trim($_POST['description'] ?? '');
$startDateTime = trim($_POST['startDateTime'] ?? '');
$endDateTime   = trim($_POST['endDateTime'] ?? '');
$timezone      = trim($_POST['timezone'] ?? 'Europe/London');
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
        header('Location: /calendar/manage');
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
    header('Location: /calendar/manage');
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
    header('Location: /calendar/manage');
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
        header('Location: /calendar/manage');
        exit();
    }

    $stmt->bind_param($insertTypes, ...$insertValues);
    $stmt->execute();
    $newEventId = $stmt->insert_id;
    $stmt->close();

    Logger::activity('EventCreated', 'Created event: ' . $eventName . ' (ID:' . $newEventId . ')', $userId);

    $_SESSION['flash_msg']  = 'Event "' . $eventName . '" created successfully.' . ($geoWarning !== null ? ' ' . $geoWarning : '');
    $_SESSION['flash_type'] = 'success';
    $appendVenueCoverageFlash($startDateTime, $endDt, $timezone);
    header('Location: /calendar/manage');
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
        header('Location: /calendar/manage');
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
    header('Location: /calendar/manage');
    exit();
}

// 🚫 Unknown action
$_SESSION['flash_msg']  = 'Unknown action.';
$_SESSION['flash_type'] = 'warning';
header('Location: /calendar/manage');
exit();
