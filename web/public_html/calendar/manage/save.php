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
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

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
    http_response_code(403);
    exit('Invalid CSRF token.');
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
 */
$processUpload = function (string $fieldName) use ($uploadsDir, $slug): ?string {
    if (isset($_FILES[$fieldName]) === false || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $file     = $_FILES[$fieldName];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed  = ['jpg', 'jpeg', 'png', 'svg', 'gif', 'webp', 'pdf'];

    if (in_array($ext, $allowed, true) === false) {
        return null;
    }

    $newName = $slug . '-' . $fieldName . '-' . time() . '.' . $ext;
    $dest    = $uploadsDir . DIRECTORY_SEPARATOR . $newName;

    if (move_uploaded_file($file['tmp_name'], $dest) === true) {
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
// ➕ Create event
// -----------------------------------------------------------------------------
if ($action === 'create') {
    // 🔍 Ensure slug is unique
    $originalSlug = $slug;
    $counter = 1;
    while (true) {
        $stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventSlug = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('s', $slug);
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

    $stmt = $mysqli->prepare(
        'INSERT INTO tblEvents ('
        . 'eventName, eventSlug, description, startDateTime, endDateTime, timezone, isAllDay, '
        . 'categoryID, typeID, seriesID, status, isPublic, isFeatured, '
        . 'locationName, locationAddress, locationWebURL, locationGeoLat, locationGeoLng, '
        . 'locationW3W, locationPhone, locationEmail, '
        . 'hostOrgName, partnerOrgs, heroImage, posterImage, profileImage, '
        . 'createdByID, updatedByID, siteID'
        . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    if ($stmt === false) {
        $_SESSION['flash_msg']  = 'Database error: ' . $mysqli->error;
        $_SESSION['flash_type'] = 'danger';
        header('Location: /calendar/manage');
        exit();
    }

    $endDt = $endDateTime !== '' ? $endDateTime : null;

    $stmt->bind_param(
        'ssssssiiiisissssddssssssssiii',
        $eventName, $slug, $description, $startDateTime, $endDt, $timezone, $isAllDay,
        $categoryID, $typeID, $seriesID, $status, $isPublic, $isFeatured,
        $locationName, $locationAddress, $locationWebURL, $locationGeoLat, $locationGeoLng,
        $locationW3W, $locationPhone, $locationEmail,
        $hostOrgName, $partnerOrgs, $heroImage, $posterImage, $profileImage,
        $userId, $userId, $siteId
    );
    $stmt->execute();
    $newEventId = $stmt->insert_id;
    $stmt->close();

    Logger::activity('EventCreated', 'Created event: ' . $eventName . ' (ID:' . $newEventId . ')', $userId);

    $_SESSION['flash_msg']  = 'Event "' . $eventName . '" created successfully.';
    $_SESSION['flash_type'] = 'success';
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
    header('Location: /calendar/manage');
    exit();
}

// 🚫 Unknown action
$_SESSION['flash_msg']  = 'Unknown action.';
$_SESSION['flash_type'] = 'warning';
header('Location: /calendar/manage');
exit();
