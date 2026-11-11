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
 *   create INSERT               — 30 placeholders ('issssiissssssssssddssiiiiiiiii')
 *   manage-all UPDATE           — 27 SET + 2 WHERE = 29 ('sssiisssssssssddsssiiiiiiiiii')
 *   leader-tier UPDATE          — 24 SET + 2 WHERE = 26 ('sssisssssssssddsssiiiiiiii')
 *
 * 🏛️ #809, D-128 — "NOT ANYBODY'S HOME". Whoever may set the place (everybody
 * who reaches this handler may) can mark it as nobody's home instead of naming
 * a host; a save asking for both is refused. The mark is worked out by the same
 * rule as the agreement and written in the same statement (`notAHome`).
 *   optional geocode follow-up  — 6  ('ddssii')
 *
 * 🏠 #809, D-120, D-124 — WHOSE HOME THE MEETING PLACE IS, AND WHETHER THEY
 * AGREED TO A MAP OF IT. A small group often meets in somebody's home, so the
 * place names its HOST (a member of the group, chosen on the form) and a map is
 * shown only where THAT host agreed. A leader names the host and cannot agree
 * on their behalf. Every statement that writes the place therefore writes
 * `hostUserID`, `placeEnteredByID` and `placeMapAgreed` IN THE SAME STATEMENT,
 * worked out by `SmallGroups::placeAgreementAfterSave()` from the row as it
 * stands, with the host resolved by `SmallGroups::hostFromPost()`. For an update that row is read again with
 * `FOR UPDATE` inside a transaction, so an agreement can never be attached to
 * a place somebody else changed a moment earlier (#647: a lock outside a
 * transaction is decoration, so the transaction starts first).
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
use Portal\Core\Capabilities;
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
    header('Location: ' . Site::url('small-groups'));
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('small-groups'));
    exit();
}

$siteId = Site::id();
$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);

$action  = (string) ($_POST['action'] ?? 'create');
$groupId = (int) ($_POST['groupID'] ?? 0);
$isCreate = $action === 'create';

// 🚪 #739: was `App::hasRole('groups_coordinator')`. It is now the
//    `groups.manage` capability, which this organisation may point at roles of
//    its own on the Roles page. It ships pointing at `groups_coordinator` and
//    falls open for a global administrator, so this answers exactly what it
//    answered before on an installation that has never opened that page.
$isManageAll = App::isAdmin() === true || App::can(Capabilities::GROUPS_MANAGE) === true;

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
        header('Location: ' . Site::url('small-groups'));
        exit();
    }
    if (SmallGroups::canManage($siteId, $groupId) === false) {
        Router::renderError(403);
        return;
    }
}

$backUrl = Site::url('small-groups/manage') . ($isCreate === false ? '?id=' . $groupId : '');

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

// 🏠 #809 — the host's own "a map of my home may be shown" box. It is drawn
// ONLY for the named host (D-124); the hidden `placeMapAsked` says it was
// DRAWN, because an unticked box is not submitted, so without the marker
// "unticked" and "never offered" would look the same and a save from a form
// without the box would withdraw an agreement nobody touched (#677). A tick
// posted by anybody who is not the host counts for nothing in the rule.
$placeBoxDrawn = array_key_exists('placeMapAsked', $_POST) === true;
$placeTicked   = ($_POST['placeMapAgreed'] ?? '') === '1';
$markDrawn     = array_key_exists('notAHomeAsked', $_POST) === true;
$markTicked    = $markDrawn === true && ($_POST['notAHome'] ?? '') === '1';
$refuseBoth    = static function () use (&$db, $backUrl): never {
    if ($db instanceof \mysqli) {
        try {
            $db->rollback();
        } catch (\Throwable $ignored) {
        }
    }
    $_SESSION['flash_msg']  = 'A place cannot be somebody\'s home and "not anybody\'s home" at once: name a host OR tick the box, not both. Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backUrl);
    exit();
};
$postedPlace   = [
    'addressLine1' => $addressLine1, 'addressLine2' => $addressLine2, 'city' => $city, 'region' => $region,
    'postcode' => $postcode, 'countryCode' => $countryCode, 'latitude' => $latitude, 'longitude' => $longitude,
    'what3words' => $w3w,
];

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
        . 'isActive, sortOrder, createdByID, `placeEnteredByID`, `hostUserID`, `placeMapAgreed`, `notAHome`) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = 'Could not save the group.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backUrl);
        exit();
    }
    $createdByID = $userId > 0 ? $userId : null;
    // 🏠 A new group has no members yet, so nobody can be named its host
    //    (a host must be an active member of THIS group): hostFromPost() is
    //    asked with no group and refuses any name.
    $hostPick = SmallGroups::hostFromPost($siteId, 0, null, true, $userId, $_POST);
    if ($hostPick['ok'] === false) {
        $_SESSION['flash_msg']  = 'A host must be a member of the group. Add the group\'s members first, then name the host.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backUrl);
        exit();
    }
    if ($markTicked === true && $hostPick['host'] !== null) {
        $refuseBoth();
    }
    $place = SmallGroups::placeAgreementAfterSave(null, $postedPlace, $hostPick['host'], $userId, $placeBoxDrawn, $placeTicked, $markDrawn, $markTicked);
    $placeEnteredBy = $place['enteredBy'];
    $placeHost      = $place['host'];
    $placeAgreed    = $place['agreed'];
    $placeNotAHome  = $place['notAHome'];
    // 30 placeholders: i s s s s i i s s s s s s s s s d d s s s i i i i i i i i i
    $stmt->bind_param(
        'issssiissssssssssddssiiiiiiiii',
        $siteId, $groupName, $slug, $groupType, $description, $serviceTypeId, $meetingDay, $meetingTime,
        $meetingFrequency, $meetingNotes, $addressLine1, $addressLine2, $city, $region, $postcode, $countryCode,
        $latitude, $longitude, $w3w, $locationVisibility, $resourcesURL, $isOpenEnrolment, $capacity,
        $isActive, $sortOrder, $createdByID, $placeEnteredBy, $placeHost, $placeAgreed, $placeNotAHome
    );
    $stmt->execute();
    $groupId = (int) $stmt->insert_id;
    $stmt->close();
} else {
    // 🔒 #809: read the row again, locked, INSIDE a transaction, and work out
    //    who entered the place from THAT — never from the copy read at the
    //    top of this request, which another save may have changed since.
    $db->begin_transaction();
    try {
        $lock = $db->prepare(
            'SELECT `addressLine1`, `addressLine2`, `city`, `region`, `postcode`, `countryCode`, `latitude`, '
            . '`longitude`, `what3words`, `placeEnteredByID`, `hostUserID`, `placeMapAgreed`, `notAHome` '
            . 'FROM `tblSmallGroups` WHERE `groupID` = ? AND `siteID` = ? FOR UPDATE'
        );
        $lock->bind_param('ii', $groupId, $siteId);
        $lock->execute();
        $lockedRow = $lock->get_result()->fetch_assoc();
        $lock->close();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
    if ($lockedRow === null) {
        $db->rollback();
        $_SESSION['flash_msg']  = 'Group not found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('small-groups'));
        exit();
    }
    $storedHost = $lockedRow['hostUserID'] !== null ? (int) $lockedRow['hostUserID'] : null;
    $hostPick   = SmallGroups::hostFromPost($siteId, $groupId, $storedHost, true, $userId, $_POST);
    if ($hostPick['ok'] === false) {
        $db->rollback();
        $_SESSION['flash_msg']  = 'The host must be an active member of this group. Nothing was saved.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backUrl);
        exit();
    }
    if ($markTicked === true && $hostPick['host'] !== null) {
        $refuseBoth();
    }
    // 🛡️ Check rounds 1 and 2 of #809 (D-124, D-128): while the place names a
    //    host, nobody else may change the host or mark it "not anybody's
    //    home", whatever they do to the address; only emptying or removing it
    //    starts again. Asked on the LOCKED row.
    $takeOver = SmallGroups::whyHostChangeRefused($lockedRow, $postedPlace, $hostPick['host'], $markTicked, $userId,
        'empty the meeting place\'s address and save');
    if ($takeOver !== '') {
        $db->rollback();
        $_SESSION['flash_msg']  = $takeOver;
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backUrl);
        exit();
    }
    $place = SmallGroups::placeAgreementAfterSave($lockedRow, $postedPlace, $hostPick['host'], $userId, $placeBoxDrawn, $placeTicked, $markDrawn, $markTicked);
    $placeEnteredBy = $place['enteredBy'];
    $placeHost      = $place['host'];
    $placeAgreed    = $place['agreed'];
    $placeNotAHome  = $place['notAHome'];
}

if ($isCreate === false && $isManageAll === true) {
    // 27 SET + 2 WHERE = 29 placeholders.
    $stmt = $db->prepare(
        'UPDATE tblSmallGroups SET '
        . 'groupName = ?, groupType = ?, description = ?, serviceTypeID = ?, meetingDay = ?, meetingTime = ?, '
        . 'meetingFrequency = ?, meetingNotes = ?, addressLine1 = ?, addressLine2 = ?, city = ?, region = ?, '
        . 'postcode = ?, countryCode = ?, latitude = ?, longitude = ?, what3words = ?, locationVisibility = ?, '
        . 'resourcesURL = ?, isOpenEnrolment = ?, capacity = ?, isActive = ?, sortOrder = ?, '
        . '`placeEnteredByID` = ?, `hostUserID` = ?, `placeMapAgreed` = ?, `notAHome` = ? '
        . 'WHERE groupID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        $db->rollback();
        $_SESSION['flash_msg']  = 'Could not save the group.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backUrl);
        exit();
    }
    $stmt->bind_param(
        'sssiisssssssssddsssiiiiiiiiii',
        $groupName, $groupType, $description, $serviceTypeId, $meetingDay, $meetingTime,
        $meetingFrequency, $meetingNotes, $addressLine1, $addressLine2, $city, $region,
        $postcode, $countryCode, $latitude, $longitude, $w3w, $locationVisibility,
        $resourcesURL, $isOpenEnrolment, $capacity, $isActive, $sortOrder,
        $placeEnteredBy, $placeHost, $placeAgreed, $placeNotAHome,
        $groupId, $siteId
    );
    try {
        $stmt->execute();
        $stmt->close();
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
} elseif ($isCreate === false) {
    // 🚫 Leader tier — serviceTypeID/isActive/sortOrder OMITTED entirely
    // (not merely re-set to their old value): 24 SET + 2 WHERE = 26.
    $stmt = $db->prepare(
        'UPDATE tblSmallGroups SET '
        . 'groupName = ?, groupType = ?, description = ?, meetingDay = ?, meetingTime = ?, '
        . 'meetingFrequency = ?, meetingNotes = ?, addressLine1 = ?, addressLine2 = ?, city = ?, region = ?, '
        . 'postcode = ?, countryCode = ?, latitude = ?, longitude = ?, what3words = ?, locationVisibility = ?, '
        . 'resourcesURL = ?, isOpenEnrolment = ?, capacity = ?, '
        . '`placeEnteredByID` = ?, `hostUserID` = ?, `placeMapAgreed` = ?, `notAHome` = ? '
        . 'WHERE groupID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        $db->rollback();
        $_SESSION['flash_msg']  = 'Could not save the group.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backUrl);
        exit();
    }
    $stmt->bind_param(
        'sssisssssssssddsssiiiiiiii',
        $groupName, $groupType, $description, $meetingDay, $meetingTime,
        $meetingFrequency, $meetingNotes, $addressLine1, $addressLine2, $city, $region,
        $postcode, $countryCode, $latitude, $longitude, $w3w, $locationVisibility,
        $resourcesURL, $isOpenEnrolment, $capacity,
        $placeEnteredBy, $placeHost, $placeAgreed, $placeNotAHome,
        $groupId, $siteId
    );
    try {
        $stmt->execute();
        $stmt->close();
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
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
header('Location: ' . Site::url('small-groups/group') . '?id=' . $groupId);
exit();
