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

// 🔀 Dispatch on action (#298 gap fix, C2). Default 'add' keeps this
//     endpoint backward-compatible with any caller that doesn't send it.
$action = (string) ($_POST['action'] ?? 'add');

// -----------------------------------------------------------------------
// 🗑️ Deactivate — parent removes their own child (keeps check-in history,
//     never hard-deletes). IDOR guard: the UPDATE's WHERE clause requires
//     BOTH childID AND parentUserID = $userId, so a parent can only ever
//     deactivate a child that is actually theirs — a forged childID for
//     someone else's child simply matches zero rows.
// -----------------------------------------------------------------------
if ($action === 'deactivate') {
    $childId = (int) ($_POST['childID'] ?? 0);
    if ($childId <= 0) {
        header('Location: /kids/profiles', true, 302); exit();
    }
    $stmt = $mysqli->prepare(
        'UPDATE tblKidProfiles SET isActive = 0 '
        . 'WHERE childID = ? AND siteID = ? AND parentUserID = ? AND isActive = 1'
    );
    $stmt->bind_param('iii', $childId, $siteId, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        Logger::activity('KidProfileDeactivated', 'Child #' . $childId . ' by parent #' . $userId);
        $_SESSION['flash_msg']  = 'Child profile deactivated.';
        $_SESSION['flash_type'] = 'success';
    } else {
        // 🛡️ Not found / not owned by this parent / already inactive —
        //     same generic message either way, so we never confirm or deny
        //     the existence of another parent's child record.
        $_SESSION['flash_msg']  = 'Could not deactivate that profile.';
        $_SESSION['flash_type'] = 'danger';
    }
    header('Location: /kids/profiles', true, 302); exit();
}

// -----------------------------------------------------------------------
// 📥 Shared field parsing for add + edit.
// -----------------------------------------------------------------------
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

// -----------------------------------------------------------------------
// ✏️ Edit — parent updates their own child (new allergy discovered, pickup
//     list changed, etc). IDOR guard: same childID + parentUserID = $userId
//     pattern as deactivate above — the UPDATE only ever touches a row this
//     parent actually owns, regardless of what childID was posted.
// -----------------------------------------------------------------------
if ($action === 'edit') {
    $childId = (int) ($_POST['childID'] ?? 0);
    if ($childId <= 0) {
        header('Location: /kids/profiles', true, 302); exit();
    }
    $stmt = $mysqli->prepare(
        'UPDATE tblKidProfiles '
        . 'SET fullName = ?, dateOfBirth = ?, allergies = ?, medicalNotes = ?, photoConsent = ?, pickupAuthorisedNames = ? '
        . 'WHERE childID = ? AND siteID = ? AND parentUserID = ? AND isActive = 1'
    );
    $stmt->bind_param('ssssisiii', $fullName, $dobArg, $alleArg, $medicalArg, $photo, $pickupArg, $childId, $siteId, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        Logger::activity('KidProfileEdited', 'Child #' . $childId . ' "' . $fullName . '" by parent #' . $userId);
        $_SESSION['flash_msg']  = $fullName . ' updated.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_msg']  = 'Could not update that profile.';
        $_SESSION['flash_type'] = 'danger';
    }
    header('Location: /kids/profiles', true, 302); exit();
}

// -----------------------------------------------------------------------
// ➕ Add — original "register a new child" flow.
// -----------------------------------------------------------------------
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
