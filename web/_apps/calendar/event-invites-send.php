<?php
// Path: _apps/calendar/event-invites-send.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Generate + email an RSVP invite token (#335)
 * -----------------------------------------------------------------------------
 * POST. Generates a 64-char hex token via random_bytes(32), upserts into
 * tblEventRSVPInvites (revives an expired or already-sent token for the
 * same email by refreshing token + expiry), emails the invite via the
 * existing Mailer.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/335
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /calendar', true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$eventId = (int) ($_POST['eventID'] ?? 0);
$email   = trim((string) ($_POST['email'] ?? ''));
$display = mb_substr(trim((string) ($_POST['displayName'] ?? '')), 0, 120);
$senderId = (int) ($_SESSION['user_id'] ?? 0);
$siteId   = Site::id();

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}
$redirect = '/calendar/event/invites?eventID=' . $eventId;

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $_SESSION['flash_msg']  = 'Invalid email address.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect, true, 302); exit();
}

$stmt = $mysqli->prepare('SELECT eventID, eventName, eventSlug, startDateTime FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($event === null) { http_response_code(404); exit('Event not found'); }

$token     = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+60 days'));
$displayArg = $display !== '' ? $display : null;

$stmt = $mysqli->prepare(
    'INSERT INTO tblEventRSVPInvites (eventID, email, displayName, token, createdByID, expiresAt) '
    . 'VALUES (?, ?, ?, ?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE token = VALUES(token), displayName = VALUES(displayName), '
    . '                        createdByID = VALUES(createdByID), expiresAt = VALUES(expiresAt), '
    . '                        usedAt = NULL, response = NULL'
);
$stmt->bind_param('isssis', $eventId, $email, $displayArg, $token, $senderId, $expiresAt);
$stmt->execute();
$stmt->close();

// 📧 Build the email.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$rsvpUrl = $scheme . '://' . $host . '/calendar/rsvp-by-link?t=' . $token;
$when    = date('l j M Y, H:i', strtotime((string) $event['startDateTime']));

$subject = 'You\'re invited: ' . (string) $event['eventName'];
$bodyHtml = '<p>Hi' . ($display !== '' ? ' ' . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') : '') . ',</p>'
    . '<p>You\'re invited to <strong>' . htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8') . '</strong> on '
    . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '.</p>'
    . '<p style="margin: 24px 0;"><a href="' . htmlspecialchars($rsvpUrl, ENT_QUOTES, 'UTF-8')
    . '" style="background:#5e6ad2; color:white; padding:12px 24px; text-decoration:none; border-radius:6px;">RSVP — single click</a></p>'
    . '<p style="font-size:12px; color:#666;">Or paste this link into your browser: ' . htmlspecialchars($rsvpUrl, ENT_QUOTES, 'UTF-8') . '</p>';

$sent = false;
if (class_exists(Mailer::class) === true && method_exists(Mailer::class, 'send') === true) {
    $sent = (bool) Mailer::send($email, $subject, $bodyHtml);
}

Logger::activity('EventInviteSent', 'Event #' . $eventId . ' → ' . $email . ($sent === true ? ' [delivered]' : ' [mailer failed]'));

$_SESSION['flash_msg']  = $sent === true ? 'Invite emailed.' : 'Token created, but mailer reported a failure — share the link manually.';
$_SESSION['flash_type'] = $sent === true ? 'success' : 'warning';
header('Location: ' . $redirect, true, 302);
exit();
