<?php
// Path: public_html/calendar/feed.php
/**
 * iCalendar feed endpoint.
 *
 * URLs:
 *   /calendar.ics?token=PERSONAL_TOKEN          — user's full visible calendar
 *   /calendar.ics?token=PERSONAL_TOKEN&days=90  — bounded window
 *
 * Returns text/calendar with a 15-minute cache hint so well-behaved
 * clients don't hammer the server.
 *
 * @package   Portal\Calendar
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/271
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Ical;
use Portal\Core\Site;

$token = (string) ($_GET['token'] ?? '');
$userId = Ical::userIdForToken($token);
if ($userId <= 0) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Invalid token.');
}

$db = App::db();

// 🪞 Resolve the user's site for scoping. Multi-site users are not
//    currently supported in the feed — picks their primary.
$siteId = 1;
$stmt = $db->prepare('SELECT siteID FROM tblUsers WHERE userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row !== null && (int) $row['siteID'] > 0) {
        $siteId = (int) $row['siteID'];
    }
}

$daysFwd = max(7, min(365, (int) ($_GET['days'] ?? 365)));
$fromDate = date('Y-m-d', strtotime('-30 days'));
$toDate   = date('Y-m-d', strtotime('+' . $daysFwd . ' days'));

$events = [];

// 📅 Standard calendar events.
$stmt = $db->prepare(
    'SELECT eventID, eventName, description, startDateTime, endDateTime, isAllDay, '
    . '       timezone, locationName, locationAddress, eventSlug, updatedAt '
    . 'FROM tblEvents '
    . 'WHERE siteID = ? AND startDateTime >= ? AND startDateTime <= ? '
    . 'ORDER BY startDateTime'
);
if ($stmt !== false) {
    $stmt->bind_param('iss', $siteId, $fromDate, $toDate);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $location = trim((string) ($r['locationName'] ?? '') . ' ' . (string) ($r['locationAddress'] ?? ''));
        $events[] = [
            'uid'          => 'event-' . $r['eventID'] . '@portal.webms-intra',
            'summary'      => (string) $r['eventName'],
            'description'  => (string) ($r['description'] ?? ''),
            'location'     => $location !== '' ? $location : null,
            'startsAt'     => (string) $r['startDateTime'],
            'endsAt'       => (string) ($r['endDateTime'] ?? ''),
            'allDay'       => (int) $r['isAllDay'] === 1,
            'lastModified' => (string) ($r['updatedAt'] ?? ''),
        ];
    }
    $stmt->close();
}

// 🗓️ User's rota duties (if rota app is enabled).
try {
    $stmt = $db->prepare(
        'SELECT s.slotID, s.slotDate, s.startTime, s.endTime, r.name AS roleName '
        . 'FROM tblRotaSlot s JOIN tblRotaRoleType r ON r.roleTypeID = s.roleTypeID '
        . 'WHERE s.siteID = ? AND s.assignedToID = ? AND s.slotDate >= ? AND s.slotDate <= ? '
        . 'ORDER BY s.slotDate'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iiss', $siteId, $userId, $fromDate, $toDate);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $allDay = $r['startTime'] === null;
            $start = (string) $r['slotDate'] . ($allDay ? '' : ' ' . substr((string) $r['startTime'], 0, 5));
            $end   = $allDay
                ? (string) $r['slotDate']
                : (string) $r['slotDate'] . ' ' . substr((string) ($r['endTime'] ?? $r['startTime']), 0, 5);
            $events[] = [
                'uid'      => 'rota-' . $r['slotID'] . '@portal.webms-intra',
                'summary'  => 'Duty: ' . (string) $r['roleName'],
                'startsAt' => $start,
                'endsAt'   => $end,
                'allDay'   => $allDay,
            ];
        }
        $stmt->close();
    }
} catch (\Throwable $ignored) {
    // 🛡️ Rota tables may not exist if the app isn't installed — silent.
}

$siteName = (string) (App::settings()['site']['name'] ?? 'Portal');
$ics = Ical::emit($siteName . ' — My Calendar', $events);

header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: private, max-age=900');
header('Content-Disposition: inline; filename="portal-calendar.ics"');
echo $ics;
