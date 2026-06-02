<?php
// Path: public_html/calendar/zoom-create.php
/**
 * Create a Zoom meeting tied to an existing calendar event.
 *
 * Caller posts eventID; we resolve the relevant Zoom account (org or
 * per-user depending on zoom.mode), call Zoom::createMeeting() with the
 * event's start time + duration, and store the meeting in tblZoomMeeting.
 *
 * @package   Portal\Calendar
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/274
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;
use Portal\Core\Zoom;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db      = App::db();
$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$eventId = (int) ($_POST['eventID'] ?? 0);
$settings = App::settings()['zoom'] ?? [];
$mode    = (string) ($settings['mode'] ?? 'org');

$flash = static function (string $msg, string $type, int $eventId): void {
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = $type;
    header('Location: /calendar/event?id=' . $eventId);
    exit();
};

if ((string) ($settings['enabled'] ?? '0') !== '1') {
    $flash('Zoom integration not enabled.', 'danger', $eventId);
}

$stmt = $db->prepare('SELECT eventID, eventName, startDateTime, endDateTime, timezone FROM tblEvents WHERE eventID = ? AND siteID = ? LIMIT 1');
$event = null;
if ($stmt !== false) {
    $stmt->bind_param('ii', $eventId, $siteId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($event === null) {
    $flash('Event not found.', 'danger', $eventId);
}

// Skip if a meeting already exists for this event.
$stmt = $db->prepare('SELECT meetingID FROM tblZoomMeeting WHERE eventID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existing !== null) {
        $flash('Event already has a Zoom meeting.', 'info', $eventId);
    }
}

$accountUserId = $mode === 'user' ? $userId : null;
$account = Zoom::loadValidAccount($siteId, $accountUserId);
if ($account === null) {
    $flash($mode === 'user'
        ? 'Your Zoom account is not connected — visit /account/integrations/zoom.'
        : 'No org Zoom account is connected — admin must connect first.', 'danger', $eventId);
}

$start    = (string) $event['startDateTime'];
$end      = (string) ($event['endDateTime'] ?? '');
$timezone = (string) ($event['timezone'] ?? 'UTC');
$duration = 60;
if ($end !== '') {
    $startTs = (int) strtotime($start);
    $endTs   = (int) strtotime($end);
    if ($endTs > $startTs) {
        $duration = (int) max(15, ($endTs - $startTs) / 60);
    }
}

$payload = [
    'topic'      => (string) $event['eventName'],
    'type'       => 2,
    'start_time' => date('Y-m-d\TH:i:s', (int) strtotime($start)),
    'duration'   => $duration,
    'timezone'   => $timezone,
    'settings'   => [
        'host_video'        => true,
        'participant_video' => true,
        'join_before_host'  => false,
        'mute_upon_entry'   => true,
        'waiting_room'      => true,
    ],
];

$meeting = Zoom::createMeeting($account['accessToken'], $payload);
if ($meeting === null || isset($meeting['id']) === false) {
    $flash('Zoom meeting creation failed.', 'danger', $eventId);
}

$ins = $db->prepare(
    'INSERT INTO tblZoomMeeting (siteID, eventID, accountID, zoomMeetingId, joinUrl, startUrl, passcode, topic, isRecurring, createdByID) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
);
if ($ins !== false) {
    $zoomId   = (string) $meeting['id'];
    $joinUrl  = (string) ($meeting['join_url'] ?? '');
    $startUrl = (string) ($meeting['start_url'] ?? '');
    $passcode = (string) ($meeting['password']  ?? '');
    $topic    = (string) ($meeting['topic']     ?? $event['eventName']);
    $accId    = (int) $account['accountID'];
    $ins->bind_param(
        'iiisssssi',
        $siteId, $eventId, $accId, $zoomId, $joinUrl, $startUrl, $passcode, $topic, $userId
    );
    $ins->execute();
    $ins->close();
}

$flash('Zoom meeting created.', 'success', $eventId);
