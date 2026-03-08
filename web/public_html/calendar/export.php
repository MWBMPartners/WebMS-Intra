<?php
// Path: public_html/calendar/export.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — iCal Export 📤
 * -----------------------------------------------------------------------------
 * Generates .ics (iCalendar) file for a single event or all upcoming events.
 * Supports:
 *   - Single event: /calendar/export?id=123
 *   - All upcoming: /calendar/export?all=1
 *   - Series:       /calendar/export?series=5
 *
 * @see       https://datatracker.ietf.org/doc/html/rfc5545
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Router;
use Portal\Core\Site;

// 🔍 Determine what to export
$eventId  = (int) ($_GET['id'] ?? 0);
$seriesId = (int) ($_GET['series'] ?? 0);
$exportAll = ($_GET['all'] ?? '') === '1';

// 🌐 Multi-site scope
$siteId = Site::id();

$events = [];

if ($eventId > 0) {
    // 📅 Single event
    $stmt = $mysqli->prepare(
        'SELECT * FROM tblEvents WHERE eventID = ? AND isDeleted = 0 AND siteID = ? LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $eventId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row !== null) {
            $events[] = $row;
        }
        $stmt->close();
    }
} elseif ($seriesId > 0) {
    // 🔄 All events in a series
    $stmt = $mysqli->prepare(
        'SELECT * FROM tblEvents WHERE seriesID = ? AND isDeleted = 0 AND status = \'published\' AND siteID = ? ORDER BY startDateTime'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $seriesId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $events[] = $r;
        }
        $stmt->close();
    }
} elseif ($exportAll === true) {
    // 📅 All upcoming public events
    $stmt = $mysqli->prepare(
        'SELECT * FROM tblEvents WHERE isDeleted = 0 AND status = \'published\' '
        . 'AND isPublic = 1 AND startDateTime >= NOW() AND siteID = ? ORDER BY startDateTime LIMIT 200'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $events[] = $r;
        }
        $stmt->close();
    }
}

if (count($events) === 0) {
    Router::renderError(404);
    return;
}

// 📤 Generate iCal output
$siteName = App::settings('site.name') ?? 'Portal';
$siteUrl  = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'portal.millrdsdacambridge.uk');

$calName = $siteName . ' Calendar';
if ($eventId > 0) {
    $calName = $events[0]['eventName'];
}

// 🔤 Helper to escape iCal text values
$icalEscape = function (string $text): string {
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace("\n", '\\n', $text);
    $text = str_replace(',', '\\,', $text);
    $text = str_replace(';', '\\;', $text);
    return $text;
};

// 🔤 Helper to format datetime for iCal (UTC)
$icalDate = function (string $datetime): string {
    $dt = new DateTime($datetime);
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Ymd\THis\Z');
};

// 📤 Set headers
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $calName) . '.ics"');

// 📝 Build iCal content
$lines = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'VERSION:2.0';
$lines[] = 'PRODID:-//' . $icalEscape($siteName) . '//Calendar//EN';
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'METHOD:PUBLISH';
$lines[] = 'X-WR-CALNAME:' . $icalEscape($calName);

foreach ($events as $ev) {
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:event-' . $ev['eventID'] . '@' . ($_SERVER['HTTP_HOST'] ?? 'portal');
    $lines[] = 'DTSTAMP:' . $icalDate($ev['createdAt']);

    if ($ev['isAllDay'] === '1' || (int) $ev['isAllDay'] === 1) {
        // 📅 All-day event uses DATE format (no time)
        $startDt = new DateTime($ev['startDateTime']);
        $lines[] = 'DTSTART;VALUE=DATE:' . $startDt->format('Ymd');
        if ($ev['endDateTime'] !== null) {
            $endDt = new DateTime($ev['endDateTime']);
            $endDt->modify('+1 day'); // iCal all-day end is exclusive
            $lines[] = 'DTEND;VALUE=DATE:' . $endDt->format('Ymd');
        }
    } else {
        $lines[] = 'DTSTART:' . $icalDate($ev['startDateTime']);
        if ($ev['endDateTime'] !== null) {
            $lines[] = 'DTEND:' . $icalDate($ev['endDateTime']);
        }
    }

    $lines[] = 'SUMMARY:' . $icalEscape($ev['eventName']);

    if ($ev['description'] !== null && $ev['description'] !== '') {
        $lines[] = 'DESCRIPTION:' . $icalEscape($ev['description']);
    }

    if ($ev['locationName'] !== null && $ev['locationName'] !== '') {
        $location = $ev['locationName'];
        if ($ev['locationAddress'] !== null && $ev['locationAddress'] !== '') {
            $location .= ', ' . str_replace("\n", ', ', $ev['locationAddress']);
        }
        $lines[] = 'LOCATION:' . $icalEscape($location);
    }

    if ($ev['locationGeoLat'] !== null && $ev['locationGeoLng'] !== null) {
        $lines[] = 'GEO:' . $ev['locationGeoLat'] . ';' . $ev['locationGeoLng'];
    }

    $lines[] = 'URL:' . $siteUrl . '/calendar/event?slug=' . urlencode($ev['eventSlug']);
    $lines[] = 'STATUS:' . match ($ev['status']) {
        'cancelled' => 'CANCELLED',
        'postponed' => 'TENTATIVE',
        default     => 'CONFIRMED',
    };

    if ($ev['updatedAt'] !== null) {
        $lines[] = 'LAST-MODIFIED:' . $icalDate($ev['updatedAt']);
    }

    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

echo implode("\r\n", $lines) . "\r\n";
exit();
