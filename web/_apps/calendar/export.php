<?php
// Path: _apps/calendar/export.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — iCal Export 📤
 * -----------------------------------------------------------------------------
 * Generates .ics (iCalendar) file for a single event, a whole series, or all
 * upcoming public events. Supports:
 *   - Single event: /calendar/export?id=123
 *   - All upcoming: /calendar/export?all=1
 *   - Series:       /calendar/export?series=5
 *
 * #338 residual (Bundle 3) — this endpoint used to hand-build "floating
 * time" iCal (no TZID/VTIMEZONE) and expand recurring series into one
 * VEVENT per generated tblEvents row. It now goes through the shared
 * Portal\Core\Ical builder (same one feed.php / account-feed.php already
 * use — see #271), which:
 *   - emits DTSTART/DTEND with TZID + a VTIMEZONE block instead of a bare
 *     "Z" (UTC) floating timestamp, and
 *   - collapses a recurring series into ONE VEVENT + RRULE (mapped from
 *     tblRecurrenceRules) instead of N separate VEVENTs, when a resolvable
 *     recurrence rule exists for that series.
 * Series with no tblRecurrenceRules row (the common case today — nothing
 * in the app currently writes to that table) or a 'custom' frequency (which
 * has no clean RRULE mapping) fall back to the previous per-row VEVENT
 * behaviour automatically. A single `id=` export is never collapsed to an
 * RRULE even if that event belongs to a recurring series — the caller asked
 * for one occurrence, not a subscription to the whole series.
 *
 * @see       https://datatracker.ietf.org/doc/html/rfc5545
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/338
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Ical;
use Portal\Core\Router;
use Portal\Core\Site;

// 🔌 Database handle — App::db(), not a bare $mysqli global. This controller
//    is require()'d from inside Router::dispatch()'s method body, and PHP
//    variable scope for require/include follows the ENCLOSING FUNCTION's
//    local scope, not the caller's — a bare $mysqli reference here would be
//    undefined (dispatch()'s own local variable is named $db, and nothing
//    in the call chain declares `global $mysqli;`). App::db() is scope-safe
//    from anywhere. See _core/App.php and _core/Router.php::dispatch().
$db = App::db();

// 🔍 Determine what to export (selection logic unchanged from the
//     hand-built version — only the VEVENT construction below changed).
$eventId   = (int) ($_GET['id'] ?? 0);
$seriesId  = (int) ($_GET['series'] ?? 0);
$exportAll = ($_GET['all'] ?? '') === '1';

// 🌐 Multi-site scope
$siteId = Site::id();

$events = [];

if ($eventId > 0) {
    // 📅 Single event
    $stmt = $db->prepare(
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
    $stmt = $db->prepare(
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
    $stmt = $db->prepare(
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

// 📤 Calendar name / filename basis (unchanged from the hand-built version)
$siteName = App::settings('site.name') ?? 'Portal';
$siteUrl  = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'portal.millrdsdacambridge.uk');

$calName = $siteName . ' Calendar';
if ($eventId > 0) {
    $calName = $events[0]['eventName'];
}

// 🔁 #338 residual — map a tblRecurrenceRules row to an RFC 5545 RRULE
//     value. Returns null when the pattern can't be cleanly expressed
//     (currently only frequency='custom'), so the caller falls back to
//     per-occurrence VEVENTs for that series exactly as before.
$buildRrule = function (array $rule, bool $allDay): ?string {
    static $dayCodes = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

    // RFC 5545 §3.3.10 has no native "fortnightly"/"quarterly" FREQ value —
    // both fold into the nearest native frequency with a baked-in multiplier.
    $freqMap = [
        'weekly'      => 'WEEKLY',
        'fortnightly' => 'WEEKLY',
        'monthly'     => 'MONTHLY',
        'quarterly'   => 'MONTHLY',
        'yearly'      => 'YEARLY',
    ];
    $frequency = (string) ($rule['frequency'] ?? '');
    if (isset($freqMap[$frequency]) === false) {
        // 'custom' (or anything unrecognised) — no generic mapping.
        return null;
    }

    $parts = ['FREQ=' . $freqMap[$frequency]];

    // ⏱️ INTERVAL — fortnightly/quarterly bake their own multiplier on top
    //     of whatever intervalVal the row carries (e.g. "every 2 fortnights"
    //     is intervalVal=2 on a 'fortnightly' row → INTERVAL=4).
    $interval = max(1, (int) ($rule['intervalVal'] ?? 1));
    if ($frequency === 'fortnightly') {
        $interval *= 2;
    } elseif ($frequency === 'quarterly') {
        $interval *= 3;
    }
    if ($interval > 1) {
        $parts[] = 'INTERVAL=' . $interval;
    }

    // 📅 BYDAY — CSV of 0=Sun..6=Sat from tblRecurrenceRules.dayOfWeek,
    //     optionally with an ordinal prefix (weekOfMonth) for "nth weekday
    //     of the month" monthly/quarterly/yearly patterns (e.g. "-1SU" =
    //     last Sunday, "2MO" = second Monday). weekOfMonth is meaningless
    //     for weekly/fortnightly, so no prefix is applied there.
    $dayOfWeekCsv = trim((string) ($rule['dayOfWeek'] ?? ''));
    $weekOfMonth  = $rule['weekOfMonth'] ?? null;
    $ordinalFreqs = ['monthly', 'quarterly', 'yearly'];
    if ($dayOfWeekCsv !== '') {
        $byDay = [];
        foreach (explode(',', $dayOfWeekCsv) as $d) {
            $d = trim($d);
            if ($d === '') {
                continue;
            }
            $idx = (int) $d;
            if ($idx < 0 || $idx > 6) {
                continue;
            }
            $prefix = '';
            if (in_array($frequency, $ordinalFreqs, true) === true && $weekOfMonth !== null) {
                $prefix = (string) (int) $weekOfMonth;
            }
            $byDay[] = $prefix . $dayCodes[$idx];
        }
        if (count($byDay) > 0) {
            $parts[] = 'BYDAY=' . implode(',', $byDay);
        }
    } elseif (in_array($frequency, $ordinalFreqs, true) === true && $rule['dayOfMonth'] !== null) {
        // 📆 No weekday pattern — fixed day-of-month (e.g. "the 15th").
        $parts[] = 'BYMONTHDAY=' . (int) $rule['dayOfMonth'];
    }

    // 🗓️ BYMONTH — yearly patterns pin a calendar month.
    if ($frequency === 'yearly' && $rule['monthOfYear'] !== null) {
        $parts[] = 'BYMONTH=' . (int) $rule['monthOfYear'];
    }

    // 🛑 UNTIL / COUNT are mutually exclusive per RFC 5545 §3.3.10; prefer
    //     the explicit end date when both are present. UNTIL's value type
    //     MUST match DTSTART's: a plain DATE for all-day series, or a UTC
    //     ("Z") date-time when DTSTART carries a TZID (never the event's
    //     own local TZID — RFC 5545 is explicit that UNTIL is always UTC
    //     for date-time recurrences).
    $endDate = $rule['endDate'] ?? null;
    if ($endDate !== null && $endDate !== '') {
        if ($allDay === true) {
            // 🛡️ Anchor explicitly to UTC rather than relying on PHP's
            //     ambient default timezone (bootstrap.php switches it to
            //     the site's configured zone). Without this, parsing a
            //     bare date during BST (UTC+1) would resolve local midnight
            //     to 23:00 UTC the PREVIOUS day, shifting UNTIL back a day.
            $ts = strtotime((string) $endDate . ' UTC');
            if ($ts !== false) {
                $parts[] = 'UNTIL=' . gmdate('Ymd', $ts);
            }
        } else {
            $ts = strtotime((string) $endDate . ' 23:59:59 UTC');
            if ($ts !== false) {
                $parts[] = 'UNTIL=' . gmdate('Ymd\THis\Z', $ts);
            }
        }
    } elseif (isset($rule['maxOccurrences']) === true && $rule['maxOccurrences'] !== null) {
        $count = (int) $rule['maxOccurrences'];
        if ($count > 0) {
            $parts[] = 'COUNT=' . $count;
        }
    }

    return implode(';', $parts);
};

// 🔎 Lazily load (and cache) the recurrence rule for a series. Returns null
//     if the series has no tblRecurrenceRules row — the common case today.
$loadRecurrenceRule = function (int $seriesId) use ($db): ?array {
    $stmt = $db->prepare(
        'SELECT frequency, intervalVal, dayOfWeek, dayOfMonth, weekOfMonth, monthOfYear, endDate, maxOccurrences '
        . 'FROM tblRecurrenceRules WHERE seriesID = ? ORDER BY ruleID LIMIT 1'
    );
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('i', $seriesId);
    $stmt->execute();
    $rule = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $rule; // null when no rule row exists for this series
};

// 🧩 Build the Ical::emit() event list. A series whose recurrence rule
//     resolves to an RRULE collapses to a single master VEVENT (RFC 5545
//     §3.8.5.3); everything else — standalone events, and series we can't
//     cleanly map to an RRULE — keeps one VEVENT per row, same as before.
//     Recurrence collapsing never applies to a single `id=` export: the
//     caller asked for one occurrence, not the whole series.
$ruleCache        = [];
$seenSeriesIds    = [];
$icalEvents       = [];

foreach ($events as $ev) {
    $sid      = (int) ($ev['seriesID'] ?? 0);
    $isAllDay = ((int) $ev['isAllDay']) === 1;

    $rrule = null;
    if ($eventId <= 0 && $sid > 0) {
        if (isset($seenSeriesIds[$sid]) === true) {
            // Already emitted this series' master VEVENT from its earliest
            // row (rows are fetched ORDER BY startDateTime) — the rest of
            // this series' rows fold into that VEVENT's RRULE.
            continue;
        }
        if (array_key_exists($sid, $ruleCache) === false) {
            $ruleCache[$sid] = $loadRecurrenceRule($sid);
        }
        $rule = $ruleCache[$sid];
        if ($rule !== null) {
            $rrule = $buildRrule($rule, $isAllDay);
        }
        if ($rrule !== null) {
            $seenSeriesIds[$sid] = true;
        }
    }

    // 📍 Location — combine name + address exactly as the hand-built
    //     version did (address newlines flattened to comma-separated).
    $location = trim((string) ($ev['locationName'] ?? ''));
    $address  = (string) ($ev['locationAddress'] ?? '');
    if ($address !== '') {
        $address  = str_replace("\n", ', ', $address);
        $location = $location !== '' ? $location . ', ' . $address : $address;
    }

    // 🚦 STATUS mapping — identical to the hand-built version.
    $status = match ((string) $ev['status']) {
        'cancelled' => 'CANCELLED',
        'postponed' => 'TENTATIVE',
        default     => 'CONFIRMED',
    };

    /** @var array{
     *   uid:string, summary:string, description:string, location:?string,
     *   startsAt:string, endsAt:string, allDay:bool, timezone:string,
     *   url:string, status:string, lastModified?:string, rrule?:string,
     *   geo?:array{lat:mixed,lng:mixed}
     * } $entry
     */
    $entry = [
        'uid'         => 'event-' . $ev['eventID'] . '@' . ($_SERVER['HTTP_HOST'] ?? 'portal'),
        'summary'     => (string) $ev['eventName'],
        'description' => (string) ($ev['description'] ?? ''),
        'location'    => $location !== '' ? $location : null,
        'startsAt'    => (string) $ev['startDateTime'],
        'endsAt'      => (string) ($ev['endDateTime'] ?? ''),
        'allDay'      => $isAllDay,
        'timezone'    => (string) ($ev['timezone'] ?? 'Europe/London'),
        'url'         => $siteUrl . '/calendar/event?slug=' . urlencode((string) $ev['eventSlug']),
        'status'      => $status,
    ];

    if ($ev['updatedAt'] !== null) {
        $entry['lastModified'] = (string) $ev['updatedAt'];
    }

    if ($ev['locationGeoLat'] !== null && $ev['locationGeoLng'] !== null) {
        $entry['geo'] = ['lat' => $ev['locationGeoLat'], 'lng' => $ev['locationGeoLng']];
    }

    if ($rrule !== null) {
        $entry['rrule'] = $rrule;
    }

    $icalEvents[] = $entry;
}

// 📤 Set headers (unchanged from the hand-built version)
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $calName) . '.ics"');

// 📝 Build iCal content via the shared builder (adds VTIMEZONE + TZID
//     automatically — see _core/Ical.php).
echo Ical::emit($calName, $icalEvents);
exit();
