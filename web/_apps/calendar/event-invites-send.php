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
use Portal\Core\MailQueue;
use Portal\Core\PortalAddress;
use Portal\Core\Site;

// #512 — every Site::url() call in this file adds the organisation's own
// address prefix in path mode (and is the plain address everywhere else),
// where a bare string used to always answer for organisation 1.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . Site::url('calendar'), true, 302); exit(); }

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
$redirect = Site::url('calendar/event/invites') . '?eventID=' . $eventId;

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $_SESSION['flash_msg']  = 'Invalid email address.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect, true, 302); exit();
}

// Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
$stmt = $mysqli->prepare('SELECT eventID, eventName, eventSlug, startDateTime, timezone FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL');
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
// The link comes from PortalAddress, the one place that decides the address
// in a link the portal sends out (#500): the address an administrator set,
// else this request's, with this organisation's own host name or address
// prefix where the installation uses one. It used to take the host name
// straight from the request ($_SERVER['HTTP_HOST']), which the sender's
// browser chooses, because there was then no setting to read. '' means
// there is no address to give: no e-mail is sent then (below), because an
// invitation whose button opens nothing is worse than none.
$rsvpUrl = PortalAddress::absolute('calendar/rsvp-by-link?t=' . $token, $siteId);
$when    = date('l j M Y, H:i', strtotime((string) $event['startDateTime']));

$subject = 'You\'re invited: ' . (string) $event['eventName'];
$bodyHtml = '<p>Hi' . ($display !== '' ? ' ' . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') : '') . ',</p>'
    . '<p>You\'re invited to <strong>' . htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8') . '</strong> on '
    . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '.</p>'
    . '<p style="margin: 24px 0;"><a href="' . htmlspecialchars($rsvpUrl, ENT_QUOTES, 'UTF-8')
    . '" style="background:#5e6ad2; color:white; padding:12px 24px; text-decoration:none; border-radius:6px;">RSVP — single click</a></p>'
    . '<p style="font-size:12px; color:#666;">Or paste this link into your browser: ' . htmlspecialchars($rsvpUrl, ENT_QUOTES, 'UTF-8') . '</p>';

// #512 — Mailer::send() CAN throw: sendViaGraph() raises
// RuntimeException('From address missing') when MS365 Graph is the active
// provider but no sender address has been configured. Before this fix,
// that meant a portal with the mailer half set up gave a bare 500 AFTER
// the invite row above had already been written — the token existed and
// worked, but the page that was meant to say so never rendered, and
// nothing told the sender what had happened. Caught the same way the
// "mailer reported a failure" flash below already covers a false return
// value; this covers a thrown exception the same way, so both failure
// shapes land on the same ordinary flash message rather than one of them
// crashing the request.
// ⏳ THE MAIL QUEUE (#592). Mailer::send() answers true for a message it has
//    only KEPT to send later, and this page used to record that as
//    "[delivered]" and say "Invite emailed." Now it asks what became of it.
//    Use-by: the moment the event STARTS, on the event's own clock. An
//    invitation that arrives after that is no use to anybody.
//    The clock's zone is `tblEvents.timezone`, the column the event form
//    writes and the event page shows; when it is not a zone PHP knows (the
//    form's box is free text) the ORGANISATION's own zone is used, so the
//    invitation is still kept. WHAT WAS WRONG: this read `eventTimezone`,
//    which only the feed importer writes, so every event made in the portal
//    was taken to be on London's clock.
$sent    = false;
$waiting = false;
if ($rsvpUrl !== '' && class_exists(Mailer::class) === true && method_exists(Mailer::class, 'send') === true) {
    try {
        $useBy   = MailQueue::useBy((string) $event['startDateTime'], (string) $event['timezone'], (string) (Site::current()['timezone'] ?? ''));
        $kept    = (bool) Mailer::send($email, $subject, $bodyHtml, [], ['expiresAt' => $useBy]);
        $waiting = $kept === true && Mailer::lastIsWaiting() === true;
        $sent    = $kept === true && $waiting === false;
    } catch (\Throwable $e) {
        $sent = false;
        Logger::errorPlatform('Email', 'Error', 'INVITE_SEND', 'Invitation email could not be sent', $e->getMessage());
    }
}

Logger::activity('EventInviteSent', 'Event #' . $eventId . ' → ' . $email . ($sent === true ? ' [accepted by the mail service]' : ($waiting === true ? ' [waiting in the mail queue]' : ($rsvpUrl === '' ? ' [not sent: the portal\'s web address is not known]' : ' [mailer failed]'))));

if ($rsvpUrl === '') {
    $_SESSION['flash_msg'] = 'The invitation was saved, but no e-mail was sent: the portal does not know its own web address, '
        . 'so the e-mail could not carry a working link. A global administrator can set the address at Admin, Settings, Organisation.';
} elseif ($waiting === true) {
    $_SESSION['flash_msg'] = 'The invitation was saved. Its e-mail could not be sent at once and is waiting in the mail queue; '
        . 'it is not sent once the event has started. To be sure it arrives in time, share the link by hand as well.';
} else {
    $_SESSION['flash_msg'] = $sent === true ? 'Invite emailed.' : 'Token created, but mailer reported a failure — share the link manually.';
}
$_SESSION['flash_type'] = $sent === true ? 'success' : 'warning';
header('Location: ' . $redirect, true, 302);
exit();
