<?php
// Path: public_html/api/events/list.php
/**
 * -----------------------------------------------------------------------------
 * Events API — List Events
 * -----------------------------------------------------------------------------
 * Returns a paginated JSON list of published events for the current site.
 *
 * Who sees which events (#514 part P2) — the one shared rule,
 * Portal\Core\EventVisibility, inside both the count and the fetch:
 *   - a signed-in session sees what the calendar itself would show that person
 *     ("session" mode): public events, members-only events of an organisation
 *     they are an active member of (or administer), and imported events by
 *     their own level;
 *   - an API key ("key" mode) sees the organisation's OWN events exactly as
 *     before (#127 and #511 track that separately), and an event imported
 *     from an outside calendar exactly when a signed-out visitor could see
 *     it, in the same detail — unless its calendar, or a choice or rule
 *     covering it, is set "Don't show via API" (the owner's decision of
 *     24 September 2026, which replaced "public AND ticked for the website,
 *     full details only on a Public calendar", owner answer 3 of
 *     17 September). The website box no longer matters to keys.
 * Each event says whether it was `imported` and whether its details are
 * limited (`detailsLimited`: true means only title, date and time were
 * sent — description and location fields are null). No `external*` or
 * `import*` column is ever returned. `isPublic` says what it MEANS rather
 * than what is stored (EventVisibility::isPublicSelect()): for an imported
 * event it is 1 when a signed-out visitor could see it, so always 1 in a
 * reply to a key; for the organisation's own events it is the event's own
 * setting, unchanged.
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/95
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\EventVisibility;
use Portal\Core\GeoLocation;
use Portal\Core\Site;

ApiAuth::requireRead('events:read');

$db     = App::db();
$siteId = Site::id();
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// 👁️ #514 part P2 — which mode of the shared rule. A request carrying an API
//    key is "key" mode whatever else it carries, the same test
//    events/api/detail.php uses: a key is not a person, and a browser that is
//    signed in must never lend its session's view to a key sent from it.
//    Otherwise it is "session" mode with the signed-in person as the viewer.
//    In key mode no fragment binds any value (the statements below always
//    bind at least the organisation, so bind_param() is never empty); the
//    isPublic expression binds nothing in any mode.
$ruleMode   = ApiAuth::bearerKeyRow() !== null ? EventVisibility::MODE_KEY : EventVisibility::MODE_SESSION;
$viewerId   = $ruleMode === EventVisibility::MODE_SESSION ? EventVisibility::sessionViewerId() : 0;
$today      = date('Y-m-d');
$visibility = EventVisibility::where('e', $ruleMode, $viewerId, $today);
$fullDetail = EventVisibility::fullDetailSelect('e', $ruleMode, $viewerId, $today, 'canSeeFull');
$isPublic   = EventVisibility::isPublicSelect('e', $ruleMode);

// 📊 Count total — the same rule as the fetch, so the page count never
//    includes events this caller may not see. The fragment is appended after
//    the literal conditions (tools/audit-checks/check_sql_columns.py reads
//    only those; the fragment's own column names are checked by
//    tools/event-visibility-selftest.php).
$cntStmt = $db->prepare(
    'SELECT COUNT(*) AS total FROM tblEvents e WHERE e.siteID = ? AND e.isDeleted = 0 AND e.status = \'published\''
    . $visibility['sql']
);
if ($cntStmt === false) {
    ApiResponse::error('Database error', 500);
}
$cntStmt->bind_param('i' . $visibility['types'], $siteId, ...$visibility['params']);
$cntStmt->execute();
$totalItems = (int) ($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);
$cntStmt->close();

$totalPages = max(1, (int) ceil($totalItems / $limit));

// 📋 Fetch events
$events = [];
//    canSeeFull and externalFeedID are selected beside each row (the values
//    of canSeeFull bound first: the SELECT list comes before the WHERE), then
//    used and removed below — neither is part of the reply.
//    The isPublic and canSeeFull expressions go in through sprintf()'s `%s`,
//    not by joining them in with `.`: tools/audit-checks/check_sql_columns.py does not
//    recognise a statement at all when PHP code sits between SELECT and FROM,
//    which would hide this statement's own column names from it (measured
//    while building #514 part P2). The text sprintf() puts in is SQL built by
//    EventVisibility itself, never anything a caller sent.
//    isPublic stays in the SAME position in the list (#514 part P7), so the
//    reply's fields keep their order; its (empty) types and values are
//    bound at its position, BEFORE canSeeFull's, so a later change to it
//    cannot silently misalign the rest.
$stmt = $db->prepare(sprintf(
    'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, e.endDateTime, '
    . 'e.timezone, e.isAllDay, e.locationName, e.status, %s, e.isFeatured, '
    . 'e.locationAddress, e.locationGeoLat, e.locationGeoLng, e.locationW3W, '
    . 'c.categoryName, t.typeName, e.externalFeedID, %s '
    . 'FROM tblEvents e '
    . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
    . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
    . 'WHERE e.siteID = ? AND e.isDeleted = 0 AND e.status = \'published\'',
    $isPublic['sql'],
    $fullDetail['sql']
) . $visibility['sql'] . ' ORDER BY e.startDateTime DESC LIMIT ? OFFSET ?');
if ($stmt !== false) {
    $stmt->bind_param(
        $isPublic['types'] . $fullDetail['types'] . 'i' . $visibility['types'] . 'ii',
        ...array_merge($isPublic['params'], $fullDetail['params'], [$siteId], $visibility['params'], [$limit, $offset])
    );
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // 👁️ #514 part P2: cut the details this caller may not see BEFORE the
        //    location object below is built from them, then say so. The
        //    number comes back as 1 or 0, never true/false.
        $row = EventVisibility::redact($row, (int) $row['canSeeFull'] === 1);
        $row['imported'] = $row['externalFeedID'] !== null;
        unset($row['externalFeedID'], $row['canSeeFull']);
        // 📍 #456 Chunk A — canonical `location` object (cross-repo
        // contract §2), additive alongside the legacy locationName field
        // already in the row (backward-compatible — lean list stays lean,
        // just gains one nested object).
        $row['location'] = GeoLocation::toLocationObject(
            [
                'name' => $row['locationName'], 'addressLine1' => $row['locationAddress'],
                'latitude' => $row['locationGeoLat'], 'longitude' => $row['locationGeoLng'],
                'what3words' => $row['locationW3W'],
            ],
            ['line1' => 'addressLine1']
        );
        $events[] = $row;
    }
    $stmt->close();
}

ApiResponse::success([
    'events' => $events,
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'totalItems' => $totalItems,
        'totalPages' => $totalPages,
        'hasNext'    => $page < $totalPages,
        'hasPrev'    => $page > 1,
    ],
]);
