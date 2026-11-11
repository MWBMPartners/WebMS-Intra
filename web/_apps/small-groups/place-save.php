<?php
// Path: _apps/small-groups/place-save.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — save, change or remove a host's home 🏠💾 (#809, D-123)
 * -----------------------------------------------------------------------------
 * POST-only, form token first, flash + redirect on every exit. The group is
 * fetched by `groupID` AND `siteID` first; a place is then looked up by
 * `placeID` AND that group AND that organisation, so a place of another group
 * is indistinguishable from a missing one.
 *
 * WHO MAY (the owner, D-125): `SmallGroups::mayEditHome()`, the same answer the
 * page draws from. A leader may add or change ANY home; any other active
 * member only their OWN, as its host — and the host is then forced to them,
 * whatever is posted.
 *
 * "NOT ANYBODY'S HOME" (D-128). Somebody who may choose a host may instead
 * mark the place as nobody's home; a save asking for both is refused. A member
 * adding or changing their OWN home is its host, so the mark is never read from
 * their post at all.
 *
 * WHOSE HOME, AND THEIR AGREEMENT (D-124). Each home names its host, a member
 * of the group; only THAT member can agree to a map of it. The host is
 * resolved by `SmallGroups::hostFromPost()` and the agreement by
 * `SmallGroups::placeAgreementAfterSave()` — the same two the group's usual
 * place uses — asked on the place's row read again with `FOR UPDATE` inside a
 * transaction, and written in the SAME statement as the address. So an
 * agreement can never be carried to another host or another address, nor
 * attached to a home somebody else changed a moment earlier (#647; the
 * transaction starts first, because a lock outside a transaction is
 * decoration — #697).
 *
 * REMOVE empties the address, who entered it and the agreement, and switches
 * the row off. The row itself stays, so a past meeting held there still points
 * at something and says "a place no longer recorded" rather than reading as
 * the group's usual place (which is what an emptied link would mean).
 *
 * @package   Portal\SmallGroups
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/809
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GeoLocation;
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

$groupId  = (int) ($_POST['groupID'] ?? 0);
$groupUrl = Site::url('small-groups/group') . '?id=' . $groupId;

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $groupUrl);
    exit();
}

$siteId = Site::id();
$userId = (int) (App::user()['userID'] ?? 0);
$group  = SmallGroups::getGroup($siteId, $groupId);
if ($group === null) {
    $_SESSION['flash_msg']  = 'Group not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('small-groups'));
    exit();
}
$canManage = SmallGroups::canManage($siteId, $groupId);
$isMember  = SmallGroups::isActiveMemberOf($siteId, $groupId, $userId);

$action  = (string) ($_POST['action'] ?? '');
$placeId = (int) ($_POST['placeID'] ?? 0);
if (in_array($action, ['create', 'update', 'remove'], true) === false) {
    Router::renderError(400);
    return;
}
// 🚪 D-125, asked on the place as it is stored now (the update path asks again
//    on the LOCKED row below, because the host may have changed meanwhile).
$current = $action === 'create' ? null : SmallGroups::getPlace($siteId, $groupId, $placeId);
if ($action !== 'create' && ($current === null || (int) $current['isActive'] !== 1)) {
    $_SESSION['flash_msg']  = 'That home is not on this group\'s list.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . $groupUrl);
    exit();
}
if (SmallGroups::mayEditHome($canManage, $isMember, $current, $userId) === false) {
    Router::renderError(403);
    return;
}
$formUrl = Site::url('small-groups/place') . '?id=' . $groupId . ($placeId > 0 ? '&placeID=' . $placeId : '');

$db = App::db();

// -----------------------------------------------------------------------------
// 🗑️ Remove: empty the home, keep the row for the meetings that point at it.
// -----------------------------------------------------------------------------
if ($action === 'remove') {
    $stmt = $db->prepare(
        'UPDATE `tblSmallGroupPlaces` SET `placeLabel` = NULL, `addressLine1` = NULL, `addressLine2` = NULL, '
        . '`city` = NULL, `region` = NULL, `postcode` = NULL, `countryCode` = NULL, `latitude` = NULL, '
        . '`longitude` = NULL, `what3words` = NULL, `placeEnteredByID` = NULL, `hostUserID` = NULL, '
        . '`placeMapAgreed` = 0, `notAHome` = 0, `isActive` = 0 '
        . 'WHERE `placeID` = ? AND `groupID` = ? AND `siteID` = ? AND `isActive` = 1'
        . ($canManage === true ? '' : ' AND `hostUserID` = ?')
    );
    if ($canManage === true) {
        $stmt->bind_param('iii', $placeId, $groupId, $siteId);
    } else {
        $stmt->bind_param('iiii', $placeId, $groupId, $siteId, $userId);
    }
    $stmt->execute();
    $removed = $stmt->affected_rows === 1;
    $stmt->close();
    if ($removed === true) {
        Logger::activity('SmallGroupPlaceRemoved', 'Removed a host\'s home from group: ' . $group['groupName'], $userId > 0 ? $userId : null);
    }
    $_SESSION['flash_msg']  = $removed === true ? 'The home was removed from the group\'s list.' : 'That home is not on this group\'s list.';
    $_SESSION['flash_type'] = $removed === true ? 'success' : 'warning';
    header('Location: ' . $groupUrl);
    exit();
}

// -----------------------------------------------------------------------------
// 🧹 The place as posted (the #456 partial's canonical names, as save.php)
// -----------------------------------------------------------------------------
$text = static function (string $field, int $max): ?string {
    $value = trim((string) ($_POST[$field] ?? ''));

    return $value !== '' ? mb_substr($value, 0, $max) : null;
};
$label        = $text('placeLabel', 100);
$addressLine1 = $text('addressLine1', 255);
$addressLine2 = $text('addressLine2', 255);
$city         = $text('city', 100);
$region       = $text('region', 100);
$postcode     = $text('postcode', 20);
$countryCode  = strtoupper(trim((string) ($_POST['countryCode'] ?? '')));
$countryCode  = preg_match('/^[A-Z]{2}$/', $countryCode) === 1 ? $countryCode : null;

$w3wPosted = trim((string) ($_POST['what3words'] ?? ''));
if ($w3wPosted !== '' && GeoLocation::validateW3W($w3wPosted) === null) {
    $_SESSION['flash_msg']  = t('location.w3w_invalid');
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $formUrl);
    exit();
}
$w3w       = GeoLocation::validateW3W($w3wPosted);
$coords    = GeoLocation::validateCoords($_POST['latitude'] ?? null, $_POST['longitude'] ?? null);
$latitude  = $coords['lat'] ?? null;
$longitude = $coords['lng'] ?? null;

$postedPlace = [
    'addressLine1' => $addressLine1, 'addressLine2' => $addressLine2, 'city' => $city, 'region' => $region,
    'postcode' => $postcode, 'countryCode' => $countryCode, 'latitude' => $latitude, 'longitude' => $longitude,
    'what3words' => $w3w,
];
if (SmallGroups::placeIsEmpty(SmallGroups::placeOf($postedPlace)) === true) {
    // A home with no address is not a home: refuse rather than store an empty
    // entry that a meeting could then point at.
    $_SESSION['flash_msg']  = 'Enter at least part of the address, a map position or a what3words address.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $formUrl);
    exit();
}

// 🏠 The agreement box (#677: the hidden marker says it was drawn).
$boxDrawn = array_key_exists('placeMapAsked', $_POST) === true;
$ticked   = ($_POST['placeMapAgreed'] ?? '') === '1';
// 🏛️ The mark is offered only to somebody who may choose the host (D-128, D-125).
$markDrawn  = $canManage === true && array_key_exists('notAHomeAsked', $_POST) === true;
$markTicked = $markDrawn === true && ($_POST['notAHome'] ?? '') === '1';
$refuseBoth = static function () use ($db, $formUrl): never {
    try {
        $db->rollback();
    } catch (\Throwable $ignored) {
    }
    $_SESSION['flash_msg']  = 'A place cannot be somebody\'s home and "not anybody\'s home" at once: name a host OR tick the box, not both. Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $formUrl);
    exit();
};

$refuseHost = static function () use ($formUrl): never {
    $_SESSION['flash_msg']  = 'The host must be an active member of this group. Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $formUrl);
    exit();
};

if ($action === 'create') {
    $hostPick = SmallGroups::hostFromPost($siteId, $groupId, null, $canManage, $userId, $_POST);
    if ($hostPick['ok'] === false) {
        $refuseHost();
    }
    if ($markTicked === true && $hostPick['host'] !== null) {
        $refuseBoth();
    }
    $agreement = SmallGroups::placeAgreementAfterSave(null, $postedPlace, $hostPick['host'], $userId, $boxDrawn, $ticked, $markDrawn, $markTicked);
    $enteredBy = $agreement['enteredBy'];
    $host      = $agreement['host'];
    $agreed    = $agreement['agreed'];
    $notAHome  = $agreement['notAHome'];
    $stmt = $db->prepare(
        'INSERT INTO `tblSmallGroupPlaces` (`siteID`, `groupID`, `placeLabel`, `addressLine1`, `addressLine2`, `city`, '
        . '`region`, `postcode`, `countryCode`, `latitude`, `longitude`, `what3words`, `hostUserID`, `placeEnteredByID`, '
        . '`placeMapAgreed`, `notAHome`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    // 16 placeholders: i i s s s s s s s d d s i i i i
    $stmt->bind_param(
        'iisssssssddsiiii',
        $siteId, $groupId, $label, $addressLine1, $addressLine2, $city, $region, $postcode, $countryCode,
        $latitude, $longitude, $w3w, $host, $enteredBy, $agreed, $notAHome
    );
    $stmt->execute();
    $stmt->close();
    Logger::activity('SmallGroupPlaceSaved', 'Added a host\'s home to group: ' . $group['groupName'], $userId > 0 ? $userId : null);
    $_SESSION['flash_msg']  = 'The home was added to the group\'s list.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . $groupUrl);
    exit();
}

// -----------------------------------------------------------------------------
// ✏️ Update: read the place again, LOCKED, inside the transaction, and decide
//    who entered it from that row — never from a copy read earlier.
// -----------------------------------------------------------------------------
$db->begin_transaction();
try {
    $lock = $db->prepare(
        'SELECT `addressLine1`, `addressLine2`, `city`, `region`, `postcode`, `countryCode`, `latitude`, `longitude`, '
        . '`what3words`, `placeEnteredByID`, `hostUserID`, `placeMapAgreed`, `notAHome` FROM `tblSmallGroupPlaces` '
        . 'WHERE `placeID` = ? AND `groupID` = ? AND `siteID` = ? AND `isActive` = 1 FOR UPDATE'
    );
    $lock->bind_param('iii', $placeId, $groupId, $siteId);
    $lock->execute();
    $locked = $lock->get_result()->fetch_assoc();
    $lock->close();
    if ($locked === null) {
        $db->rollback();
        $_SESSION['flash_msg']  = 'That home is not on this group\'s list.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . $groupUrl);
        exit();
    }
    // D-125 again, on the locked row: the host may have changed since the
    // first look, and a member may change only a home that is still theirs.
    if (SmallGroups::mayEditHome($canManage, $isMember, $locked, $userId) === false) {
        $db->rollback();
        Router::renderError(403);
        return;
    }
    $storedHost = $locked['hostUserID'] !== null ? (int) $locked['hostUserID'] : null;
    $hostPick   = SmallGroups::hostFromPost($siteId, $groupId, $storedHost, $canManage, $userId, $_POST);
    if ($hostPick['ok'] === false) {
        $db->rollback();
        $refuseHost();
    }
    if ($markTicked === true && $hostPick['host'] !== null) {
        $refuseBoth();
    }
    $agreement = SmallGroups::placeAgreementAfterSave($locked, $postedPlace, $hostPick['host'], $userId, $boxDrawn, $ticked, $markDrawn, $markTicked);
    $enteredBy = $agreement['enteredBy'];
    $host      = $agreement['host'];
    $agreed    = $agreement['agreed'];
    $notAHome  = $agreement['notAHome'];
    $stmt = $db->prepare(
        'UPDATE `tblSmallGroupPlaces` SET `placeLabel` = ?, `addressLine1` = ?, `addressLine2` = ?, `city` = ?, '
        . '`region` = ?, `postcode` = ?, `countryCode` = ?, `latitude` = ?, `longitude` = ?, `what3words` = ?, '
        . '`hostUserID` = ?, `placeEnteredByID` = ?, `placeMapAgreed` = ?, `notAHome` = ? '
        . 'WHERE `placeID` = ? AND `groupID` = ? AND `siteID` = ?'
    );
    // 17 placeholders: s s s s s s s d d s i i i i i i i
    $stmt->bind_param(
        'sssssssddsiiiiiii',
        $label, $addressLine1, $addressLine2, $city, $region, $postcode, $countryCode, $latitude, $longitude, $w3w,
        $host, $enteredBy, $agreed, $notAHome, $placeId, $groupId, $siteId
    );
    $stmt->execute();
    $stmt->close();
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    throw $e;
}

Logger::activity('SmallGroupPlaceSaved', 'Changed a host\'s home of group: ' . $group['groupName'], $userId > 0 ? $userId : null);
$_SESSION['flash_msg']  = 'The home was saved.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . $groupUrl);
exit();
