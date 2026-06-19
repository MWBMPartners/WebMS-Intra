<?php
// _apps/kids/checkin-do.php (#298)
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /kids/checkin', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
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

$stmt = $mysqli->prepare('INSERT INTO tblKidCheckins (childID, eventID, badgeCode, checkedInByID) VALUES (?, ?, ?, ?)');
$stmt->bind_param('iisi', $childId, $eventIdArg, $badge, $staffId);
$stmt->execute();
$stmt->close();

Logger::activity('KidCheckedIn', 'Child #' . $childId . ' badge=' . $badge);

$_SESSION['kids_badge_issued'] = ['name' => (string) $child['fullName'], 'code' => $badge];
header('Location: /kids/checkin', true, 302); exit();
