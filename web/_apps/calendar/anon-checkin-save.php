<?php
// _apps/calendar/anon-checkin-save.php (#314)
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /', true, 302); exit(); }

Auth::ensureSession();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); exit('Bad request');
}

$eventId   = (int) ($_POST['eventID'] ?? 0);
$headcount = max(1, min(20, (int) ($_POST['headcount'] ?? 1)));
$siteId    = Site::id();
$source    = (string) ($_GET['source'] ?? 'self');
if (in_array($source, ['self', 'kiosk', 'qr'], true) === false) { $source = 'self'; }

$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND status = "published"');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Event not found'); }

$ua     = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$ip     = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ipHash = $ip !== '' ? hash('sha256', $ip . '|' . $eventId) : null;

$stmt = $mysqli->prepare('INSERT INTO tblAnonymousCheckins (eventID, headcount, source, userAgent, ipHash) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('iisss', $eventId, $headcount, $source, $ua, $ipHash);
$stmt->execute();
$stmt->close();

$pageTitle = 'Checked in';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
echo '<div class="container py-5 text-center" style="max-width:480px;">';
echo '<i class="fa-solid fa-circle-check fa-4x text-success mb-3"></i>';
echo '<h1 class="h3">Checked in</h1>';
echo '<p class="text-muted">Thanks, ' . (int) $headcount . ' added to the headcount.</p>';
echo '</div>';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
