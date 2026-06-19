<?php
// Path: _apps/calendar/event-register-save.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Public registration POST handler (#347)
 * -----------------------------------------------------------------------------
 * Validates, length-clamps, INSERTs a pending tblEventRegistrations row,
 * fires the calendar.event.registration.received webhook, shows a
 * thank-you page.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/347
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /calendar', true, 302); exit(); }

Auth::ensureSession();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); exit('Bad request.');
}

$slug = trim((string) ($_POST['slug'] ?? ''));
if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9\-]{0,79}$/i', $slug) !== 1) {
    http_response_code(400); exit('Invalid event.');
}

$siteId = Site::id();
$stmt = $mysqli->prepare(
    'SELECT eventID, eventName, registrationEnabled, registrationOpensAt, registrationClosesAt '
    . 'FROM tblEvents WHERE eventSlug = ? AND siteID = ? AND isDeleted = 0 AND status = "published" LIMIT 1'
);
$stmt->bind_param('si', $slug, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if ($event === null || (int) $event['registrationEnabled'] !== 1) {
    http_response_code(404); exit('Registration not available.');
}
$now = time();
if (!empty($event['registrationOpensAt']) && strtotime((string) $event['registrationOpensAt']) > $now) {
    http_response_code(403); exit('Registration not yet open.');
}
if (!empty($event['registrationClosesAt']) && strtotime((string) $event['registrationClosesAt']) < $now) {
    http_response_code(403); exit('Registration has closed.');
}

// 🛡️ Clamp + sanitise every field.
$fullName     = mb_substr(trim((string) ($_POST['fullName'] ?? '')), 0, 120);
$dob          = trim((string) ($_POST['dateOfBirth'] ?? ''));
$grade        = mb_substr(trim((string) ($_POST['grade'] ?? '')), 0, 10);
$gender       = (string) ($_POST['gender'] ?? '');
$shirtSize    = (string) ($_POST['shirtSize'] ?? '');
$allergies    = mb_substr(trim((string) ($_POST['allergies'] ?? '')), 0, 500);
$medical      = mb_substr(trim((string) ($_POST['medicalNotes'] ?? '')), 0, 1000);
$parentName   = mb_substr(trim((string) ($_POST['parentName'] ?? '')), 0, 120);
$parentPhone  = mb_substr(trim((string) ($_POST['parentPhone'] ?? '')), 0, 40);
$parentEmail  = trim((string) ($_POST['parentEmail'] ?? ''));
$photoConsent = (int) ($_POST['photoConsent'] ?? 0) === 1 ? 1 : 0;
$emergName    = mb_substr(trim((string) ($_POST['emergencyContactName'] ?? '')), 0, 120);
$emergPhone   = mb_substr(trim((string) ($_POST['emergencyContactPhone'] ?? '')), 0, 40);

if ($fullName === '') {
    $_SESSION['flash_msg']  = 'Full name is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /calendar/event/register?slug=' . urlencode($slug), true, 302); exit();
}

// 🛡️ Validate constrained values; null on miss.
$genderArg    = in_array($gender, ['male', 'female', 'other'], true) === true ? $gender : null;
$shirtArg     = in_array($shirtSize, ['YS','YM','YL','XS','S','M','L','XL','XXL'], true) === true ? $shirtSize : null;
$dobArg       = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) === 1) ? $dob : null;
$parentEmailArg = ($parentEmail !== '' && filter_var($parentEmail, FILTER_VALIDATE_EMAIL) !== false) ? $parentEmail : null;
$eventIdInt   = (int) $event['eventID'];

$stmt = $mysqli->prepare(
    'INSERT INTO tblEventRegistrations '
    . '(eventID, fullName, dateOfBirth, grade, gender, shirtSize, allergies, medicalNotes, '
    . ' parentName, parentPhone, parentEmail, photoConsent, emergencyContactName, '
    . ' emergencyContactPhone, status, source) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", "public-form")'
);
$stmt->bind_param(
    'issssssssssiss',
    $eventIdInt, $fullName, $dobArg, $grade, $genderArg, $shirtArg,
    $allergies, $medical, $parentName, $parentPhone, $parentEmailArg,
    $photoConsent, $emergName, $emergPhone
);
$stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

Logger::activity('EventRegistrationReceived', 'Event #' . $eventIdInt . ' registration #' . $newId . ' (' . $fullName . ')');

if (class_exists('\\Portal\\Core\\WebhookDispatcher') === true) {
    $payload = ['eventID' => $eventIdInt, 'registrationID' => $newId, 'fullName' => $fullName, 'grade' => $grade];
    \Portal\Core\WebhookDispatcher::emit('calendar.event.registration.received', $payload);
}

// 🎨 Thank-you page.
$pageTitle = 'Registration received';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
echo '<div class="container py-5 text-center" style="max-width:560px;">';
echo '<i class="fa-solid fa-circle-check fa-3x text-success mb-3"></i>';
echo '<h1 class="h3 mb-2">Thank you!</h1>';
echo '<p class="text-muted">We\'ve received the registration for <strong>' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
echo '<p class="text-muted small">Status: <strong>Pending review</strong>. The event coordinator will be in touch.</p>';
echo '<a href="/calendar/event?slug=' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" class="btn btn-outline-primary mt-3">Back to event</a>';
echo '</div>';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
exit();
