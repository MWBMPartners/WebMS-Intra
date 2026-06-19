<?php
// _apps/kids/profiles-save.php (#298)
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /kids/profiles', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$fullName = mb_substr(trim((string) ($_POST['fullName'] ?? '')), 0, 120);
$dob      = trim((string) ($_POST['dateOfBirth'] ?? ''));
$alle     = mb_substr(trim((string) ($_POST['allergies'] ?? '')),       0, 500);
$medical  = mb_substr(trim((string) ($_POST['medicalNotes'] ?? '')),    0, 1000);
$pickup   = mb_substr(trim((string) ($_POST['pickupAuthorisedNames'] ?? '')), 0, 500);
$photo    = (int) ($_POST['photoConsent'] ?? 0) === 1 ? 1 : 0;

if ($fullName === '') {
    $_SESSION['flash_msg']  = 'Name required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /kids/profiles', true, 302); exit();
}
$dobArg = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) === 1) ? $dob : null;

$alleArg    = $alle    !== '' ? $alle    : null;
$medicalArg = $medical !== '' ? $medical : null;
$pickupArg  = $pickup  !== '' ? $pickup  : null;

$stmt = $mysqli->prepare(
    'INSERT INTO tblKidProfiles (siteID, parentUserID, fullName, dateOfBirth, allergies, medicalNotes, photoConsent, pickupAuthorisedNames) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('iissssis', $siteId, $userId, $fullName, $dobArg, $alleArg, $medicalArg, $photo, $pickupArg);
$stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

Logger::activity('KidProfileAdded', 'Child #' . $newId . ' "' . $fullName . '" by user #' . $userId);
$_SESSION['flash_msg']  = $fullName . ' added.';
$_SESSION['flash_type'] = 'success';
header('Location: /kids/profiles', true, 302); exit();
