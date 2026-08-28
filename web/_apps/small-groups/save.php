<?php
// Path: _apps/small-groups/save.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Group Save Handler 💾
 * -----------------------------------------------------------------------------
 * POST-only, CSRF-first, flash + redirect on every exit (venue-save.php
 * shape). Gates: create → manage-all (admin || groups_coordinator);
 * update → `SmallGroups::canManage()` on the group fetched
 * `WHERE groupID=? AND siteID=?` FIRST — a cross-tenant groupID is
 * indistinguishable from missing.
 *
 * Non-manage-all leaders: serviceTypeID/isActive/sortOrder are NOT read
 * from POST for the UPDATE — the leader-tier UPDATE statement simply
 * omits those three columns (the #436 "a toggle can never wipe an
 * existing link" discipline).
 *
 * bind_param arity — every statement below is a literal type-string with
 * an exact matching bound-argument count, verified against
 * check_bind_param_arity.py (the audit check is the source of truth, per
 * the #150 build spec):
 *   create INSERT               — 26 placeholders ('issssiissssssssssddssiiiii')
 *   manage-all UPDATE           — 23 SET + 2 WHERE = 25 ('sssiisssssssssddsssiiiiii')
 *   leader-tier UPDATE          — 20 SET + 2 WHERE = 22 ('sssisssssssssddsssiiii')
 *   optional geocode follow-up  — 6  ('ddssii')
 *
 * @package   Portal\SmallGroups
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/150
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GeoLocation;
use Portal\Core\Geocoder;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\SmallGroups;

Auth::ensureSession();
Auth::requireLogin();

if (SmallGroups::isEnabled() === false) {
    Router::renderError(404);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /small-groups');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /small-groups');
    exit();
}

$siteId = Site::id();
$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);

$action  = (string) ($_POST['action'] ?? 'create');
$groupId = (int) ($_POST['groupID'] ?? 0);
$isCreate = $action === 'create';

$isManageAll = App::isAdmin() === true || App::hasRole('groups_coordinator') === true;

$existing = null;
if ($isCreate === true) {
    if ($isManageAll === false) {
        Router::renderError(403);
        return;
    }
} else {
    $existing = SmallGroups::getGroup($siteId, $groupId);
    if ($existing === null) {
        $_SESSION['flash_msg']  = 'Group not found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /small-groups');
        exit();
    }
    if (SmallGroups::canManage($siteId, $groupId) === false) {
        Router::renderError(403);
        return;
    }
}

$backUrl = '/small-groups/manage' . ($isCreate === false ? '?id=' . $groupId : '');

// -----------------------------------------------------------------------------
// 🧹 Validation / normalisation
// -----------------------------------------------------------------------------
$groupName = trim((string) ($_POST['groupName'] ?? ''));
if ($groupName === '') {
    $_SESSION['flash_msg']  = 'Group name is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backUrl);
    exit();
}
$groupName = mb_substr($groupName, 0, 150);

$groupType = trim((string) ($_POST['groupType'] ?? 'small-group'));
if ($groupType === '') {
    $groupType = 'small-group';
}
$groupType = mb_substr($groupType, 0, 50);

$description = trim((string) ($_POST['description'] ?? ''));
$description = $description !== '' ? mb_substr($description, 0, 1000) : null;

$meetingDayRaw = trim((string) ($_POST['meetingDay'] ?? ''));
$meetingDay = null;
if ($meetingDayRaw !== '') {
    $d = (int) $meetingDayRaw;
    $meetingDay = ($d >= 1 && $d <= 7) ? $d : null;
}

$meetingTime = trim((string) ($_POST['meetingTime'] ?? ''));
$meetingTime = $meetingTime !== '' ? $meetingTime : null;

$meetingFrequency = (string) ($_POST['meetingFrequency'] ?? 'weekly');
if (in_array($meetingFrequency, ['weekly', 'fortnightly', 'monthly', 'adhoc'], true) === false) {
    $meetingFrequency = 'weekly';
}

$meetingNotes = trim((string) ($_POST['meetingNotes'] ?? ''));
$meetingNotes = $meetingNotes !== '' ? mb_substr($meetingNotes, 0, 500) : null;

$addressLine1 = trim((string) ($_POST['addressLine1'] ?? ''));
$addressLine1 = $addressLine1 !== '' ? mb_substr($addressLine1, 0, 255) : null;
$addressLine2 = trim((string) ($_POST['addressLine2'] ?? ''));
$addressLine2 = $addressLine2 !== '' ? mb_substr($addressLine2, 0, 255) : null;
$city = trim((string) ($_POST['city'] ?? ''));
$city = $city !== '' ? mb_substr($city, 0, 100) : null;
$region = trim((string) ($_POST['region'] ?? ''));
$region = $region !== '' ? mb_substr($region, 0, 100) : null;
$postcode = trim((string) ($_POST['postcode'] ?? ''));
$postcode = $postcode !== '' ? mb_substr($postcode, 0, 20) : null;

$countryCode = strtoupper(trim((string) ($_POST['countryCode'] ?? 'GB')));
if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
    $countryCode = 'GB';
}

// 📍 #456 — pre-validate a non-empty W3W so a typo flashes back rather
// than silently dropping (venue-save.php:102-108 pattern).
$w3wPosted = trim((string) ($_POST['what3words'] ?? ''));
if ($w3wPosted !== '' && GeoLocation::validateW3W($w3wPosted) === null) {
    $_SESSION['flash_msg']  = t('location.w3w_invalid');
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backUrl);
    exit();
}
$w3w = GeoLocation::validateW3W($w3wPosted);

$coords = GeoLocation::validateCoords($_POST['latitude'] ?? null, $_POST['longitude'] ?? null);
$latitude  = $coords['lat'] ?? null;
$longitude = $coords['lng'] ?? null;

$locationVisibility = (string) ($_POST['locationVisibility'] ?? 'members');
if (in_array($locationVisibility, ['leaders', 'members', 'site'], true) === false) {
    $locationVisibility = 'members';
}

$resourcesURL = trim((string) ($_POST['resourcesURL'] ?? ''));
$resourcesURL = $resourcesURL !== '' ? mb_substr($resourcesURL, 0, 500) : null;

$isOpenEnrolment = isset($_POST['isOpenEnrolment']) ? 1 : 0;

$capacityRaw = trim((string) ($_POST['capacity'] ?? ''));
$capacity = null;
if ($capacityRaw !== '') {
    $c = (int) $capacityRaw;
    $capacity = $c >= 1 ? $c : null;
}

// 🔗 serviceTypeID — manage-all only; site-scoped FK probe (record/save.php
// precedent) so a foreign/garbage id can never attach.
$serviceTypeId = null;
if ($isManageAll === true) {
    $requestedTypeId = (int) ($_POST['serviceTypeID'] ?? 0);
    if ($requestedTypeId > 0) {
        $chk = App::db()->prepare('SELECT 1 FROM tblAttendanceServiceTypes WHERE serviceTypeID = ? AND siteID = ? LIMIT 1');
        if ($chk !== false) {
            $chk->bind_param('ii', $requestedTypeId, $siteId);
            $chk->execute();
            $ok = $chk->get_result()->fetch_assoc() !== null;
            $chk->close();
            if ($ok === true) {
                $serviceTypeId = $requestedTypeId;
            }
        }
    }
}

$isActive  = ($existing === null || (int) $existing['isActive'] === 1) ? 1 : 0;
$sortOrder = $existing !== null ? (int) $existing['sortOrder'] : 0;
if ($isManageAll === true) {
    $isActive  = isset($_POST['isActive']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sortOrder'] ?? 0);
}

$db = App::db();

if ($isCreate === true) {
    // -------------------------------------------------------------------
    // 🔤 Slug — derived from the group name, unique PER SITE (#339
    // lesson), suffixed on collision.
    // -------------------------------------------------------------------
    $baseSlug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '-', $groupName), '-'));
    if ($baseSlug === '') {
        $baseSlug = 'group';
    }
    $slug = mb_substr($baseSlug, 0, 100);
    $suffix = 1;
    while (true) {
        $chk = $db->prepare('SELECT 1 FROM tblSmallGroups WHERE groupSlug = ? AND siteID = ? LIMIT 1');
        $chk->bind_param('si', $slug, $siteId);
        $chk->execute();
        $taken = $chk->get_result()->fetch_assoc() !== null;
        $chk->close();
        if ($taken === false) {
            break;
        }
        $suffix++;
        $slug = mb_substr($baseSlug, 0, 100 - strlen('-' . $suffix)) . '-' . $suffix;
    }

    $stmt = $db->prepare(
        'INSERT INTO tblSmallGroups '
        . '(siteID, groupName, groupSlug, groupType, description, serviceTypeID, meetingDay, meetingTime, '
        . 'meetingFrequency, meetingNotes, addressLine1, addressLine2, city, region, postcode, countryCode, '
        . 'latitude, longitude, what3words, locationVisibility, resourcesURL, isOpenEnrolment, capacity, '
        . 'isActive, sortOrder, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = 'Could not save the group.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backUrl);
        exit();
    }
    $createdByID = $userId > 0 ? $userId : null;
    // 26 placeholders: i s s s s i i s s s s s s s s s d d s s s i i i i i
    $stmt->bind_param(
        'issssiissssssssssddssiiiii',
        $siteId, $groupName, $slug, $groupType, $description, $serviceTypeId, $meetingDay, $meetingTime,
        $meetingFrequency, $meetingNotes, $addressLine1, $addressLine2, $city, $region, $postcode, $countryCode,
        $latitude, $longitude, $w3w, $locationVisibility, $resourcesURL, $isOpenEnrolment, $capacity,
        $isActive, $sortOrder, $createdByID
    );
    $stmt->execute();
    $groupId = (int) $stmt->insert_id;
    $stmt->close();
} elseif ($isManageAll === true) {
    // 23 SET + 2 WHERE = 25 placeholders.
    $stmt = $db->prepare(
        'UPDATE tblSmallGroups SET '
        . 'groupName = ?, groupType = ?, description = ?, serviceTypeID = ?, meetingDay = ?, meetingTime = ?, '
        . 'meetingFrequency = ?, meetingNotes = ?, addressLine1 = ?, addressLine2 = ?, city = ?, region = ?, '
        . 'postcode = ?, countryCode = ?, latitude = ?, longitude = ?, what3words = ?, locationVisibility = ?, '
        . 'resourcesURL = ?, isOpenEnrolment = ?, capacity = ?, isActive = ?, sortOrder = ? '
        . 'WHERE groupID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = 'Could not save the group.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backUrl);
        exit();
    }
    $stmt->bind_param(
        'sssiisssssssssddsssiiiiii',
        $groupName, $groupType, $description, $serviceTypeId, $meetingDay, $meetingTime,
        $meetingFrequency, $meetingNotes, $addressLine1, $addressLine2, $city, $region,
        $postcode, $countryCode, $latitude, $longitude, $w3w, $locationVisibility,
        $resourcesURL, $isOpenEnrolment, $capacity, $isActive, $sortOrder,
        $groupId, $siteId
    );
    $stmt->execute();
    $stmt->close();
} else {
    // 🚫 Leader tier — serviceTypeID/isActive/sortOrder OMITTED entirely
    // (not merely re-set to their old value): 20 SET + 2 WHERE = 22.
    $stmt = $db->prepare(
        'UPDATE tblSmallGroups SET '
        . 'groupName = ?, groupType = ?, description = ?, meetingDay = ?, meetingTime = ?, '
        . 'meetingFrequency = ?, meetingNotes = ?, addressLine1 = ?, addressLine2 = ?, city = ?, region = ?, '
        . 'postcode = ?, countryCode = ?, latitude = ?, longitude = ?, what3words = ?, locationVisibility = ?, '
        . 'resourcesURL = ?, isOpenEnrolment = ?, capacity = ? '
        . 'WHERE groupID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = 'Could not save the group.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backUrl);
        exit();
    }
    $stmt->bind_param(
        'sssisssssssssddsssiiii',
        $groupName, $groupType, $description, $meetingDay, $meetingTime,
        $meetingFrequency, $meetingNotes, $addressLine1, $addressLine2, $city, $region,
        $postcode, $countryCode, $latitude, $longitude, $w3w, $locationVisibility,
        $resourcesURL, $isOpenEnrolment, $capacity,
        $groupId, $siteId
    );
    $stmt->execute();
    $stmt->close();
}

// -----------------------------------------------------------------------------
// 🌍 Optional best-effort geocode — only when coords are empty, an address
// was given, and the site has opted in (existing geo.autoGeocode global;
// no new geocode settings). Never blocks the save.
// -----------------------------------------------------------------------------
if ($latitude === null && $addressLine1 !== null && Geocoder::autoEnabled() === true) {
    try {
        $addressLine = GeoLocation::formatAddress([
            'line1' => $addressLine1, 'line2' => $addressLine2, 'city' => $city,
            'region' => $region, 'postcode' => $postcode,
        ]);
        $geo = Geocoder::forward($addressLine, $countryCode);
        if ($geo !== null) {
            $geocodedAt    = date('Y-m-d H:i:s');
            $geocodeSource = (string) ($geo['source'] ?? 'unknown');
            $gLat = (float) $geo['lat'];
            $gLng = (float) $geo['lng'];
            $upd = $db->prepare(
                'UPDATE tblSmallGroups SET latitude = ?, longitude = ?, geocodedAt = ?, geocodeSource = ? '
                . 'WHERE groupID = ? AND siteID = ?'
            );
            if ($upd !== false) {
                // 6 placeholders: d d s s i i
                $upd->bind_param('ddssii', $gLat, $gLng, $geocodedAt, $geocodeSource, $groupId, $siteId);
                $upd->execute();
                $upd->close();
            }
        }
    } catch (\Throwable $e) {
        // Best-effort — never blocks the save.
    }
}

Logger::activity('SmallGroupSaved', ($isCreate === true ? 'Created' : 'Updated') . ' group: ' . $groupName, $userId > 0 ? $userId : null);

$_SESSION['flash_msg']  = $isCreate === true ? 'Group created.' : 'Group updated.';
$_SESSION['flash_type'] = 'success';
header('Location: /small-groups/group?id=' . $groupId);
exit();
