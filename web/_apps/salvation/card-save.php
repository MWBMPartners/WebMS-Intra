<?php
// _apps/salvation/card-save.php (#316)
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /', true, 302); exit(); }

Auth::ensureSession();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }
if (class_exists(Captcha::class) === true && Captcha::isConfigured() === true) {
    if (Captcha::verify($_POST) === false) { http_response_code(400); exit('Captcha failed.'); }
}

$siteId = Site::id();
$eventId = (int) ($_POST['eventID'] ?? 0);
$eventIdArg = $eventId > 0 ? $eventId : null;

$fullName = mb_substr(trim((string) ($_POST['fullName'] ?? '')), 0, 120);
$email    = trim((string) ($_POST['email'] ?? ''));
$phone    = mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 40);
$address  = mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 500);
$decision = (string) ($_POST['decision'] ?? 'first-time');
$prayer   = mb_substr(trim((string) ($_POST['prayerRequest'] ?? '')), 0, 1000);

if ($fullName === '') { http_response_code(400); exit('Name required.'); }
$emailArg = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) ? $email : null;
if (in_array($decision, ['first-time','rededication','baptism','membership','bible-study','prayer','other'], true) === false) {
    $decision = 'first-time';
}

$stmt = $mysqli->prepare(
    'INSERT INTO tblSalvationCards (siteID, eventID, fullName, email, phone, address, decision, prayerRequest) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$phoneArg   = $phone !== '' ? $phone : null;
$addressArg = $address !== '' ? $address : null;
$prayerArg  = $prayer !== '' ? $prayer : null;
$stmt->bind_param('iissssss', $siteId, $eventIdArg, $fullName, $emailArg, $phoneArg, $addressArg, $decision, $prayerArg);
$stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

Logger::activity('SalvationCardReceived', 'Card #' . $newId . ' (' . $fullName . ', ' . $decision . ')');
if (class_exists('\\Portal\\Core\\WebhookDispatcher') === true) {
    \Portal\Core\WebhookDispatcher::emit('salvation.card.received', ['cardID' => $newId, 'decision' => $decision]);
}

$pageTitle = 'Thank you';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
echo '<div class="container py-5 text-center" style="max-width:560px;">';
echo '<i class="fa-solid fa-circle-check fa-3x text-success mb-3"></i>';
echo '<h1 class="h3">Thank you</h1>';
echo '<p class="text-muted">Someone from the team will be in touch.</p>';
echo '</div>';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
