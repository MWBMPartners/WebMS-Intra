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
 * #503 — a single `id=` download follows the event page's own draft rule: a
 * draft only for people who can manage events (anybody else gets the same
 * "not available" page as a missing event).
 *
 * #544 — the `series=` download stopped handing a signed-out visitor the
 * internal events of a series (an interim test, 21 September 2026).
 *
 * #514 part P2 — all three downloads now use the one shared visibility rule,
 * Portal\Core\EventVisibility, inside their queries: a public event for
 * anybody, a members-only event for an active member of THAT event's own
 * organisation (or its administrator), an event copied in from an outside
 * calendar by its own level. This replaced the separate sign-in tests (and
 * #544's interim test), which let ANY signed-in account download another
 * organisation's internal events (#534 items 1 and 2). An event the viewer may
 * see only as "title, date and time" goes into the file with no description,
 * location, web link, map position or last-changed time.
 *
 * @see       https://datatracker.ietf.org/doc/html/rfc5545
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/338
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\EventVisibility;
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

// 🛡️ Asked BEFORE the lookup, and on every request, whatever it turns out
//    to find — the same discipline calendar/event.php already follows.
//    What was wrong before: App::isAdmin() below only ran for a row that
//    turned out to be a draft, so a signed-in visitor asking for a draft
//    number cost one more database query than one asking for a missing
//    number — a small but real timing difference between "exists but
//    refused" and "does not exist" (#503/#532 discipline applied here too).
//    Only the single-event draft rule reads it.
//
//    (#514 part P2 removed the second question read here, "is anybody
//    signed in": the shared visibility rule in each query now decides who
//    may see what, so nothing reads it any more. The viewer's number and
//    today's date are read instead; they cost no database work.)
$canManage = App::isAdmin();
$viewerId  = EventVisibility::sessionViewerId();
$today     = date('Y-m-d');

$events = [];

if ($eventId > 0) {
    // 📅 Single event
    //
    // 👁️ #514 part P2: the shared rule (session mode) is part of the lookup,
    //    appended after the page's own literal conditions, so an event this
    //    viewer may not see comes back as no row — exactly like a missing one,
    //    for the same one statement. canSeeFull is selected beside the row;
    //    its values are bound first (the SELECT list comes before the WHERE).
    //
    //    Why sprintf() with `%s` for the canSeeFull expression, and not plain
    //    joining: tools/audit-checks/check_sql_columns.py does not recognise
    //    a statement at all when PHP code sits between SELECT and FROM, so
    //    joining the expression in with `.` hid this whole statement — the
    //    page's own column names included — from it (measured on 21 September
    //    2026 while building #514 part P2: this file went from 5 statements
    //    it could read to 1). Written as one literal with `%s`, the statement
    //    is read and its own columns are checked again. The text put in by
    //    sprintf() is SQL built by EventVisibility itself, never anything a
    //    visitor sent; every value is still bound.
    $visibility = EventVisibility::where('e', EventVisibility::MODE_SESSION, $viewerId, $today);
    $fullDetail = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_SESSION, $viewerId, $today, 'canSeeFull');
    $row  = null;
    $stmt = $db->prepare(sprintf(
        'SELECT e.*, %s FROM tblEvents e WHERE e.eventID = ? AND e.isDeleted = 0 AND e.siteID = ?',
        $fullDetail['sql']
    ) . $visibility['sql'] . ' LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param(
            $fullDetail['types'] . 'ii' . $visibility['types'],
            ...array_merge($fullDetail['params'], [$eventId, $siteId], $visibility['params'])
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    // 🛡️ Who may download ONE event (#503). The same rule as the event's own
    //    page (calendar/event.php), whose "Add to Calendar" button links here.
    //
    //    What was wrong before: this lookup had no condition on status or on
    //    isPublic and asked nobody to sign in. Anybody could download any
    //    draft or internal event, with its description and location, by trying
    //    id=1, id=2 and so on. Event numbers count upward, so that needs no
    //    guessing at all.
    //
    //    1. A draft goes only to people who can manage events: App::isAdmin(),
    //       exactly the check every page under calendar/manage/ makes. For
    //       anybody else the row is dropped, so the request falls through to
    //       the same "not available" page as a number that matches no event,
    //       and the answer does not reveal that the draft exists.
    //    2. Who may see the event at all is decided by the shared rule inside
    //       the lookup above (#514 part P2). Until then this was "an event not
    //       marked public needs sign-in", which let ANY signed-in account —
    //       a member of another organisation included — download it (#534).
    //
    //    The series and "all upcoming" downloads below already leave drafts out
    //    with their own status test, so they are not changed here.
    if ($row !== null
        && in_array((string) ($row['status'] ?? ''), ['published', 'cancelled', 'postponed'], true) === false
        && $canManage === false
    ) {
        $row = null;
    }

    // 🛡️ ONE page for a refused download and a missing one — the owner's
    //    answer of 20 September 2026, matching calendar/event.php:
    //    Router::renderEventUnavailable(), a 404 with a sign-in link for a
    //    signed-out visitor, never a sign-in redirect (a DEAD link must not
    //    ask anybody to sign in for something that no longer exists). An
    //    event the rule refuses, a draft this visitor cannot manage and a
    //    number that matches nothing all reach this line with $row null.
    //    (#514 part P2 removed the sign-in test that used to sit here; the
    //    rule in the lookup above has already decided.)
    if ($row === null) {
        Router::renderEventUnavailable();
        return;
    }

    $events[] = $row;
} elseif ($seriesId > 0) {
    // 🔄 All events in a series — with the ONE shared visibility rule
    //    (#514 part P2) applied to every row, inside the query.
    //
    //    What was wrong before #544 (21 September 2026): this lookup asked
    //    only for published rows of this organisation. It never asked whether
    //    an event was public, or who was asking. The address needs no sign-in
    //    (its route is seeded with isProtected = 0, so that a public series
    //    can be downloaded and subscribed to), so anybody could download every
    //    published event of an INTERNAL series, descriptions and locations
    //    included, by trying series=1, series=2 and so on.
    //
    //    #544 was the INTERIM fix: two whole statements, chosen by "is anybody
    //    signed in", the signed-out one adding `isPublic = 1`. It deliberately
    //    did not ask whether a signed-in visitor belonged to the event's own
    //    organisation, so any signed-in account still got any organisation's
    //    internal series (#534).
    //
    //    NOW (#514 part P2): ONE statement for everybody, with
    //    EventVisibility::where() in session mode appended after the page's
    //    own conditions. A signed-out visitor (viewer 0) still gets only the
    //    series' public events — exactly what #544 gave — and a signed-in
    //    visitor gets its members-only events only as an active member of the
    //    event's own organisation, or its administrator. Imported events follow
    //    their own level. A series with some events a visitor may see and some
    //    they may not gives them only the first kind.
    //
    //    The test is part of the SQL rather than applied to the fetched rows,
    //    so an internal series and a series number that matches nothing cost
    //    the database the same work — one statement, the same text whoever
    //    asks — and return the same nothing (#503): no refused row leaves the
    //    database.
    //
    //    ⚠️ Keep the page's own conditions whole, literal and FIRST, with the
    //    fragment added after them. tools/audit-checks/check_sql_columns.py
    //    checks the column names a WHERE clause tests only in literal SQL
    //    text, and stops reading at the first quoted value ('published' here);
    //    a piece added in a variable is invisible to it (proved on 21 September
    //    2026: a misspelt column in such a piece passed). The fragment's own
    //    column names are checked instead by tools/event-visibility-selftest.php,
    //    which prepares every mode against the real tables.
    //
    //    Drafts stay out for EVERYBODY, including people who can manage
    //    events, through the unchanged status test, so $canManage plays no
    //    part here; it matters only to the single-event download's draft rule.
    //    That is today's behaviour, kept on purpose.
    $visibility = EventVisibility::where('e', EventVisibility::MODE_SESSION, $viewerId, $today);
    $fullDetail = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_SESSION, $viewerId, $today, 'canSeeFull');
    //    (The canSeeFull expression goes in through sprintf()'s `%s` for the
    //    same reason as in the single-event branch above.)
    $stmt = $db->prepare(sprintf(
        'SELECT e.*, %s FROM tblEvents e WHERE e.seriesID = ? AND e.isDeleted = 0 AND e.siteID = ? AND e.status = \'published\'',
        $fullDetail['sql']
    ) . $visibility['sql'] . ' ORDER BY e.startDateTime');
    if ($stmt !== false) {
        $stmt->bind_param(
            $fullDetail['types'] . 'ii' . $visibility['types'],
            ...array_merge($fullDetail['params'], [$seriesId, $siteId], $visibility['params'])
        );
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $events[] = $r;
        }
        $stmt->close();
    }

    // 🛡️ ONE answer for "nothing here for you" — Router::renderEventUnavailable(),
    //    the page the single-event branch already uses (#532, #544). A series
    //    number that matches nothing, another organisation's series, a series
    //    with no events, a series with only drafts, and an internal series
    //    asked for by a signed-out visitor all reach this line the same way:
    //    one query, no rows (#514 part P2: and so does an internal series
    //    asked for by a signed-in member of ANOTHER organisation). The page is
    //    a 404 underneath, with a sign-in link for a visitor who is not signed
    //    in.
    //
    //    What was wrong before: an empty result fell through to the generic
    //    "page not found" page at the end of the selection below. That was not
    //    itself a leak (the rows above were), but once a signed-out visitor is
    //    refused an internal series, the refusal MUST look exactly like a
    //    missing series, and this page is the one answer the owner chose for
    //    that on 20 September 2026.
    if (count($events) === 0) {
        Router::renderEventUnavailable();
        return;
    }
} elseif ($exportAll === true) {
    // 📅 All upcoming events the whole world may see
    //
    // 👁️ #514 part P2: the literal `isPublic = 1` became the shared rule in
    //    "anonymous" mode (viewer 0, administrator branches off), whoever is
    //    asking. This download is a public subscription address that calendar
    //    apps fetch without signing in, so it must never depend on a session:
    //    the portal's own events only when marked public, and an imported event
    //    only at its public level. The fragment goes after the literal
    //    conditions (see the series branch above for why), and the canSeeFull
    //    expression through sprintf()'s `%s` (see the single-event branch).
    $visibility = EventVisibility::where('e', EventVisibility::MODE_ANONYMOUS, 0, $today);
    $fullDetail = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_ANONYMOUS, 0, $today, 'canSeeFull');
    $stmt = $db->prepare(sprintf(
        'SELECT e.*, %s FROM tblEvents e WHERE e.isDeleted = 0 AND e.startDateTime >= NOW() AND e.siteID = ? AND e.status = \'published\'',
        $fullDetail['sql']
    ) . $visibility['sql'] . ' ORDER BY e.startDateTime LIMIT 200');
    if ($stmt !== false) {
        $stmt->bind_param(
            $fullDetail['types'] . 'i' . $visibility['types'],
            ...array_merge($fullDetail['params'], [$siteId], $visibility['params'])
        );
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $events[] = $r;
        }
        $stmt->close();
    }
}

// 📭 Only the "all upcoming" download and a request that names nothing
//    (no id=, series= or all=1) can reach this line with nothing to send;
//    the single-event and series branches above answer their own
//    "not available" page. Nothing about a visitor's rights is decided
//    here, so the plain "page not found" answer is kept for these two.
if (count($events) === 0) {
    Router::renderError(404);
    return;
}

// 📤 Calendar name / filename basis (unchanged from the hand-built version)
$siteName = App::settings('site.name') ?? 'Portal';

// 🔗 The start of this portal's web address (for example https://portal.example.org),
//    used below to give each event in the download a link back to its page.
//    It comes from the address the visitor used (HTTP_HOST), because
//    WebMS-Intra is installed by many customers at many addresses and none
//    may be built in.
//    What was wrong before: with no HTTP_HOST this fell back to one
//    customer's live address, so a download from any other customer's portal
//    would have linked to that customer's site. Now the link is simply left
//    out of the file (Ical::emit() skips an empty address). HTTP_HOST is
//    rarely missing: very old clients that do not say which site they want,
//    some test requests, and unusual server set-ups can all leave it out.
//    (An earlier version of this comment said "only" very old clients; the
//    Codex review of 14 September 2026 pointed out that was too strong.)
//    ⚠️ It still always says https://, exactly as before. A portal served
//    only over plain http gets a link that does not open. Not changed here.
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$siteUrl     = $requestHost !== '' ? 'https://' . $requestHost : '';

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
    // 👁️ #514 part P2 — "title, date and time only" (canSeeFull 0, which the
    //    number 1 or 0 from the database says). redact() empties the
    //    description, location, address, map position, web link and images, so
    //    the entry below carries none of them; the web link back to the event's
    //    page and the last-changed time are left out further down as well (the
    //    #514 plan, section 1.4).
    $canSeeFull = (int) $ev['canSeeFull'] === 1;
    $ev         = EventVisibility::redact($ev, $canSeeFull);

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
        // 🔗 Link back to the event's own page. Site::url() adds the
        //    organisation's part of the address when the portal tells
        //    organisations apart by address ("path mode", for example
        //    /cambridge/calendar/event); otherwise it gives /calendar/event,
        //    exactly as before. What was wrong before: a bare
        //    '/calendar/event' in path mode opened the FIRST organisation's
        //    event with the same slug (slugs are only unique within one
        //    organisation), or "not found". Empty when there is no host (see
        //    $siteUrl above), which leaves the link out of the file.
        //    #514 part P2: no link at "title, date and time only" (an empty
        //    value leaves URL out of the file).
        'url'         => $siteUrl !== '' && $canSeeFull === true
            ? $siteUrl . Site::url('calendar/event') . '?slug=' . urlencode((string) $ev['eventSlug'])
            : '',
        'status'      => $status,
    ];

    // #514 part P2: no LAST-MODIFIED at "title, date and time only" — when an
    // event last changed can itself tell somebody that its hidden details did.
    if ($ev['updatedAt'] !== null && $canSeeFull === true) {
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
