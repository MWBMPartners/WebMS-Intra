<?php
// _apps/kids/checkin-do.php (#298)
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /kids/checkin', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Safeguarding gate (#298 gap fix) — same role check as the checkin.php
//     terminal it's posted from; without it any logged-in member could
//     check in ANY child at the site by guessing/enumerating childID.
if (App::isAdmin() === false && App::hasRole('kids_team') === false) {
    Router::renderError(403);
    return;
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$childId  = (int) ($_POST['childID'] ?? 0);
$eventId  = (int) ($_POST['eventID'] ?? 0);
$staffId  = (int) ($_SESSION['user_id'] ?? 0);
$siteId   = Site::id();

// 🛡️ Confirm child belongs to this site + isn't already open.
$stmt = $mysqli->prepare(
    'SELECT k.childID, k.fullName, '
    . '       (SELECT COUNT(*) FROM tblKidCheckins kc WHERE kc.childID = k.childID AND kc.checkedOutAt IS NULL) AS isCheckedIn '
    . 'FROM tblKidProfiles k WHERE k.childID = ? AND k.siteID = ? AND k.isActive = 1'
);
$stmt->bind_param('ii', $childId, $siteId);
$stmt->execute();
$child = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($child === null) { http_response_code(404); exit('Child not found'); }
if ((int) $child['isCheckedIn'] === 1) {
    header('Location: /kids/checkin', true, 302); exit();
}

// 🎟️ Issue a 6-digit numeric badge code (random, unique-enough across the
//     open set — we don't enforce DB uniqueness because collisions resolve
//     by the staff visually disambiguating + lookup-by-badge-and-name).
$badge = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
$eventIdArg = $eventId > 0 ? $eventId : null;

// 🔗 Keep the event number only when it is one of THIS organisation's own
//    events, not deleted and not imported. Anything else is stored as "no
//    event". WHAT WAS WRONG (12 October 2026): the posted number was stored
//    unchecked. Another organisation's event number was accepted, and a
//    number that matched no event made the database refuse the whole row
//    (the column is linked to tblEvents), so the staff member lost what they had
//    just entered: the child was not checked in.
//    NEVER refuse the check-in over a bad event number. The event is a label on
//    the record, not the reason for it. attendance/record/save.php treats a
//    posted event number the same way.
if ($eventIdArg !== null) {
    $eventHere  = false;
    $eventCheck = $mysqli->prepare(
        'SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL'
    );
    if ($eventCheck !== false) {
        $eventCheck->bind_param('ii', $eventIdArg, $siteId);
        $eventCheck->execute();
        $eventHere = $eventCheck->get_result()->fetch_assoc() !== null;
        $eventCheck->close();
    }
    // Could not ask counts as "not ours": the record is still saved.
    if ($eventHere === false) {
        $eventIdArg = null;
    }
}

$stmt = $mysqli->prepare('INSERT INTO tblKidCheckins (childID, eventID, badgeCode, checkedInByID) VALUES (?, ?, ?, ?)');
$stmt->bind_param('iisi', $childId, $eventIdArg, $badge, $staffId);
$stmt->execute();
$stmt->close();

Logger::activity('KidCheckedIn', 'Child #' . $childId . ' badge=' . $badge);

$_SESSION['kids_badge_issued'] = ['name' => (string) $child['fullName'], 'code' => $badge];
header('Location: /kids/checkin', true, 302); exit();
