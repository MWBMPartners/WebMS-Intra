<?php
// Path: public_html/calendar/zoom-remove.php
/**
 * Remove the Zoom meeting from a calendar event (calls Zoom DELETE +
 * unlinks the row).
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

$stmt = $db->prepare(
    'SELECT m.meetingID, m.zoomMeetingId FROM tblZoomMeeting m '
    . 'WHERE m.eventID = ? AND m.siteID = ? LIMIT 1'
);
$meeting = null;
if ($stmt !== false) {
    $stmt->bind_param('ii', $eventId, $siteId);
    $stmt->execute();
    $meeting = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($meeting !== null) {
    $accountUserId = $mode === 'user' ? $userId : null;
    $account = Zoom::loadValidAccount($siteId, $accountUserId);
    if ($account !== null) {
        Zoom::deleteMeeting($account['accessToken'], (string) $meeting['zoomMeetingId']);
    }
    $d = $db->prepare('DELETE FROM tblZoomMeeting WHERE meetingID = ? AND siteID = ?');
    if ($d !== false) {
        $mid = (int) $meeting['meetingID'];
        $d->bind_param('ii', $mid, $siteId);
        $d->execute();
        $d->close();
    }
    $_SESSION['flash_msg']  = 'Zoom meeting removed.';
    $_SESSION['flash_type'] = 'success';
} else {
    $_SESSION['flash_msg']  = 'No Zoom meeting linked to this event.';
    $_SESSION['flash_type'] = 'info';
}

header('Location: /calendar/event?id=' . $eventId);
exit();
