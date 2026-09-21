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

use Portal\Core\EventVisibility;
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
$today      = date('Y-m-d');
$visibility = EventVisibility::where('e', EventVisibility::MODE_WEBSITE, 0, $today);
$fullDetail = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_WEBSITE, 0, $today, 'canSeeFull');
// 🧩 The canSeeFull expression goes in through sprintf()'s `%s`, not by
//    joining it in with `.`: tools/audit-checks/check_sql_columns.py does not
//    recognise a statement at all when PHP code sits between SELECT and FROM
//    (measured while building #514 part P2). The text sprintf() puts in is
//    SQL built by EventVisibility itself, never anything a visitor sent.
$stmt = $mysqli->prepare(sprintf(
    'SELECT e.eventID, e.eventName AS title, e.locationName AS location, e.startDateTime, e.endDateTime, %s '
    . 'FROM tblEvents e '
    . 'WHERE e.siteID = ? '
    . '  AND e.isDeleted = 0 '
    . '  AND e.startDateTime >= NOW() '
    . '  AND e.status = "published"',
    $fullDetail['sql']
) . $visibility['sql'] . ' ORDER BY e.startDateTime ASC LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param(
        $fullDetail['types'] . 'i' . $visibility['types'],
        ...array_merge($fullDetail['params'], [$siteId], $visibility['params'])
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
        'message'      => 'No upcoming services scheduled',
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

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
        'startsAt'  => date('c', strtotime((string) $nextEvent['startDateTime'])),
        'endsAt'    => $nextEvent['endDateTime'] !== null
            ? date('c', strtotime((string) $nextEvent['endDateTime']))
            : null,
    ],
], JSON_UNESCAPED_SLASHES);
