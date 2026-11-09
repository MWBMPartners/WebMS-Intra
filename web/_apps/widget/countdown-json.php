<?php
// Path: _apps/widget/countdown-json.php
/**
 * -----------------------------------------------------------------------------
 * Embeddable Countdown Widget — JSON feed 📡 (#319)
 * -----------------------------------------------------------------------------
 * Public read-only endpoint that returns the next upcoming event for the
 * active site as JSON. Consumed by the static countdown.js embed widget
 * that churches paste onto their own website.
 *
 * Public — no auth required. CORS open (this is by design — external sites
 * are the entire point of the widget).
 *
 * @package   Portal\Widget
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/319
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Events;
use Portal\Core\EventVisibility;
use Portal\Core\PublicWebsite;
use Portal\Core\Site;

// 🌐 CORS — open to all origins (widget embeds across the internet).
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: public, max-age=60');
header('Content-Type: application/json; charset=utf-8');

$siteId = Site::id();

// 📋 Next upcoming event on this site. Public visibility only (no
//    leadership-only events leak via the public widget feed).
$nextEvent = null;
// 🛡️ Column names match the tblEvents schema: eventName (NOT title),
//    locationName (NOT location), status ENUM with 'cancelled' value (NOT
//    isCancelled bool).
//
// 👁️ #514 part P2: "public only" used to be the literal `isPublic = 1`. It is
//    now the one shared rule, EventVisibility::where(), in "website" mode —
//    the mode for feeds meant for the organisation's own public website. It
//    looks as NOBODY: the portal's own events only when marked public, and an
//    event copied in from an outside calendar only when it is public AND
//    ticked for the website (owner decision D4). canSeeFull (1 = full
//    details, 0 = title, date and time only) is selected beside the row;
//    at 0 the location goes out as null (leak-hunt finding 23 in the #514
//    plan). The fragment is appended after the literal conditions, which
//    tools/audit-checks/check_sql_columns.py can read (it cannot read inside
//    the fragment; tools/event-visibility-selftest.php checks the fragment's
//    own column names). canSeeFull's values are bound first, because the
//    SELECT list comes before the WHERE.
//    ⚠️ This reply is cached for 60 seconds by browsers and by anything in
//    between (Cache-Control above), so narrowing an event can take up to a
//    minute to reach an embed; a copy already taken cannot be pulled back.
// 🌐 #493 P1: "website" mode now also needs the event's own "Show on the
//    public website" switch and today inside its dates (D-057, D-078), and a
//    copied-in event follows the rule its organisation is held to (D-080,
//    D-081) — worked out here and BOUND by the rule, never written into it.
//    `$today` is the organisation's (start-up sets PHP's zone to it), which is
//    the calendar those dates are kept on.
$today      = date('Y-m-d');
$visibility = EventVisibility::where('e', EventVisibility::MODE_WEBSITE, 0, $today, PublicWebsite::copiedRuleFor($mysqli, $siteId));
$fullDetail = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_WEBSITE, 0, $today, 'canSeeFull');
// 🧩 The canSeeFull expression goes in through sprintf()'s `%s`, not by
//    joining it in with `.`: tools/audit-checks/check_sql_columns.py does not
//    recognise a statement at all when PHP code sits between SELECT and FROM
//    (measured while building #514 part P2). The text sprintf() puts in is
//    SQL built by EventVisibility itself, never anything a visitor sent.
// 🕰️ "STILL TO COME" IS ASKED ON EACH EVENT'S OWN CLOCK (#804). This was
//    `e.startDateTime >= NOW()`, and `NOW()` is the DATABASE SERVER's clock
//    in its own session zone: with an Auckland organisation on a database
//    running in UTC, an event that had begun up to about thirteen hours ago
//    was still published to every website embedding this feed as "next",
//    with its start time already in the past. Events::nowOnItsOwnClock()
//    gives each row's own clock exactly, inside SQL, so `LIMIT 1` still
//    picks from the right rows; the loose bound beside it is only there so
//    the `startDateTime` index can still be used. The visibility rule is
//    unchanged: EventVisibility::where() in "website" mode, built above, is
//    still appended to this statement.
$nowOnClock = Events::nowOnItsOwnClock($mysqli, 'e', $siteId);
$looseFloor = Events::looseUtcBound('-1 day');
$stmt = $mysqli->prepare(sprintf(
    'SELECT e.eventID, e.eventName AS title, e.locationName AS location, e.startDateTime, e.endDateTime, '
    // The event's own zone (#777 part two): `startsAt` below is an instant,
    // and an instant depends on the clock the reading was written on.
    . '`e`.`timezone`, %s '
    . 'FROM tblEvents e '
    . 'WHERE e.siteID = ? '
    . '  AND e.isDeleted = 0 '
    . '  AND `e`.`startDateTime` >= ? '
    . '  AND `e`.`startDateTime` >= %s '
    . '  AND e.status = "published"',
    $fullDetail['sql'],
    $nowOnClock['sql']
) . $visibility['sql'] . ' ORDER BY e.startDateTime ASC LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param(
        $fullDetail['types'] . 'is' . $nowOnClock['types'] . $visibility['types'],
        ...array_merge($fullDetail['params'], [$siteId, $looseFloor], $nowOnClock['params'], $visibility['params'])
    );
    $stmt->execute();
    $nextEvent = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

// 🏷️ Brand-aware site name + product name (multi-brand layer, #296).
$siteName    = Site::branding('name') ?? Site::productName();
$productName = Site::productName();

if ($nextEvent === null) {
    echo json_encode([
        'siteName'     => $siteName,
        'productName'  => $productName,
        'nextEvent'    => null,
        'message'      => 'No upcoming services on the public website',
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

// 🕰️ A stored reading as an ISO 8601 instant on the event's own clock (#777).
$nextZone    = Site::zoneFor((string) ($nextEvent['timezone'] ?? ''));
$nextInstant = static function (mixed $reading) use ($nextZone): ?string {
    $instant = \Portal\Core\I18n::wallClockInstant((string) $reading, $nextZone);

    return $instant !== null ? $instant->format('c') : null;
};

// 📡 Emit a minimal, stable shape — the JS widget reads only these keys.
echo json_encode([
    'siteName'    => $siteName,
    'productName' => $productName,
    'nextEvent'   => [
        'id'        => (int) $nextEvent['eventID'],
        'title'     => (string) $nextEvent['title'],
        // #514 part P2: null at "title, date and time only" (canSeeFull 0,
        // the number 0 from the database); otherwise exactly as before.
        'location'  => (int) $nextEvent['canSeeFull'] === 1 ? (string) ($nextEvent['location'] ?? '') : null,
        // 🕰️ #777 part two. These were `date('c', strtotime(...))`, which
        //    reads the stored reading on PHP's default zone (the
        //    organisation's) -- so an event kept on another clock was
        //    published hours out to every website embedding this feed. The
        //    instant is now taken on the event's OWN clock; a reading that is
        //    not a real one gives null rather than a confident wrong instant.
        'startsAt'  => $nextInstant($nextEvent['startDateTime']),
        'endsAt'    => $nextEvent['endDateTime'] !== null
            ? $nextInstant($nextEvent['endDateTime'])
            : null,
    ],
], JSON_UNESCAPED_SLASHES);
