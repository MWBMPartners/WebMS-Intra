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
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

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
$locationGeoLat  = ($_POST['locationGeoLat'] ?? '') !== '' ? (float) $_POST['locationGeoLat'] : null;
$locationGeoLng  = ($_POST['locationGeoLng'] ?? '') !== '' ? (float) $_POST['locationGeoLng'] : null;
$locationW3W     = trim($_POST['locationW3W'] ?? '');

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

    $_SESSION['flash_msg']  = 'Event "' . $eventName . '" created successfully.';
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

    $sql = 'UPDATE tblEvents SET ' . implode(', ', $setClauses) . ' WHERE eventID = ? AND siteID = ?';

    $stmt = $mysqli->prepare($sql);
    if ($stmt !== false) {
        $stmt->bind_param($paramTypes, ...$paramValues);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('EventUpdated', 'Updated event #' . $eventID . ': ' . $eventName, $userId);

    $_SESSION['flash_msg']  = 'Event "' . $eventName . '" updated successfully.';
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
