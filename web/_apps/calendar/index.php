<?php
// Path: public_html/calendar/index.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — View Router 📅
 * -----------------------------------------------------------------------------
 * Routes between the seven calendar view modes (issue #136):
 *
 *   ?view=day        – single day, hour timeline
 *   ?view=week       – 7-day grid (Mon-Sun)
 *   ?view=weekdays   – 5-day grid (Mon-Fri)
 *   ?view=weekend    – 2-day grid (Sat-Sun)
 *   ?view=month      – calendar grid (5-6 rows)
 *   ?view=year       – 12-month wall planner
 *   ?view=list       – chronological card list (legacy default)
 *
 * Defaults:
 *   - URL  ?view=…           ← takes top priority
 *   - localStorage           ← remembered last view per device
 *   - setting calendar.defaultView (admin-set, falls back to "month")
 *
 * Date cursor:
 *   ?date=YYYY-MM-DD         (interpreted by view: day uses date,
 *                            week uses containing week, month uses
 *                            year-month, year uses year part)
 *
 * Filters (applied to every view):
 *   ?category=N              category ID
 *   ?type=N                  type ID
 *   ?past=1                  list-view-only: also show past events
 *
 * Each view partial under views/ receives the resolved $events array
 * plus the shared filter / range variables and emits the inner panel
 * markup only (the shared header and template chrome are emitted by
 * this router).
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.11.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/136
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\Site;
use Portal\Core\Venues;

// 📌 Page metadata
$pageTitle   = 'Calendar';
$pageSection = 'calendar';
$breadcrumbs = ['Dashboard' => '/', 'Calendar' => ''];

// 🛡️ Ensure session for nav state
Auth::ensureSession();

// -----------------------------------------------------------------------------
// 🔀 Resolve active view
// -----------------------------------------------------------------------------
$validViews = ['day', 'week', 'weekdays', 'weekend', 'month', 'year', 'list'];

$defaultView = (string) (App::settings('calendar.defaultView') ?? 'month');
if (in_array($defaultView, $validViews, true) === false) {
    $defaultView = 'month';
}

$view = (string) ($_GET['view'] ?? $defaultView);
if (in_array($view, $validViews, true) === false) {
    $view = $defaultView;
}

// -----------------------------------------------------------------------------
// 📅 Resolve the date cursor — interpreted per view below
// -----------------------------------------------------------------------------
$rawDate = trim((string) ($_GET['date'] ?? ''));
$cursor  = null;
if ($rawDate !== '') {
    // Accept YYYY-MM-DD, YYYY-MM, or YYYY — fall back to today if malformed
    try {
        if (preg_match('/^\d{4}$/', $rawDate) === 1) {
            $cursor = new DateTimeImmutable($rawDate . '-01-01');
        } elseif (preg_match('/^\d{4}-\d{2}$/', $rawDate) === 1) {
            $cursor = new DateTimeImmutable($rawDate . '-01');
        } else {
            $cursor = new DateTimeImmutable($rawDate);
        }
    } catch (\Throwable) {
        $cursor = null;
    }
}
if ($cursor === null) {
    $cursor = new DateTimeImmutable('today');
}

// -----------------------------------------------------------------------------
// 🔍 Common filter parameters
// -----------------------------------------------------------------------------
$filterCategory = trim((string) ($_GET['category'] ?? ''));
$filterType     = trim((string) ($_GET['type'] ?? ''));
$filterLocation = trim((string) ($_GET['location'] ?? ''));
$filterSearch   = trim((string) ($_GET['q'] ?? ''));
$filterFrom     = trim((string) ($_GET['from'] ?? ''));
$filterTo       = trim((string) ($_GET['to'] ?? ''));
$showPast       = ($_GET['past'] ?? '') === '1';   // list-view only

// 🛡️ Length-clamp + format-validate new filters.
$filterLocation = mb_substr($filterLocation, 0, 80);
$filterSearch   = mb_substr($filterSearch, 0, 80);
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom) !== 1) { $filterFrom = ''; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)   !== 1) { $filterTo   = ''; }

$siteId = Site::id();

// -----------------------------------------------------------------------------
// 📊 Compute the date range visible in the chosen view.
//
// Returned as [DateTimeImmutable $rangeStart (00:00:00),
//              DateTimeImmutable $rangeEnd  (last second of the range)].
//
// Used both for the SQL filter (grid views) and the title shown in the
// shared header. List view ignores the range and uses pagination instead.
// -----------------------------------------------------------------------------
$rangeStart = $cursor;
$rangeEnd   = $cursor;

switch ($view) {
    case 'day':
        $rangeStart = $cursor->setTime(0, 0, 0);
        $rangeEnd   = $cursor->setTime(23, 59, 59);
        break;

    case 'week':
        // Week runs Monday → Sunday for civil/ISO convenience.
        $dow        = (int) $cursor->format('N');           // 1..7, Mon..Sun
        $rangeStart = $cursor->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
        $rangeEnd   = $rangeStart->modify('+6 days')->setTime(23, 59, 59);
        break;

    case 'weekdays':
        $dow        = (int) $cursor->format('N');
        $rangeStart = $cursor->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
        $rangeEnd   = $rangeStart->modify('+4 days')->setTime(23, 59, 59); // Mon..Fri
        break;

    case 'weekend':
        // Anchor on the Saturday of the containing week. If the cursor is
        // Mon-Fri we look forward to the upcoming Sat; if it's Sat/Sun we
        // use the same weekend.
        $dow = (int) $cursor->format('N');
        if ($dow <= 5) {
            $rangeStart = $cursor->modify('+' . (6 - $dow) . ' days')->setTime(0, 0, 0);
        } else {
            // Sat (6) → today; Sun (7) → yesterday (start of weekend)
            $rangeStart = $cursor->modify('-' . ($dow - 6) . ' days')->setTime(0, 0, 0);
        }
        $rangeEnd = $rangeStart->modify('+1 day')->setTime(23, 59, 59);
        break;

    case 'month':
        $rangeStart = $cursor->modify('first day of this month')->setTime(0, 0, 0);
        $rangeEnd   = $cursor->modify('last day of this month')->setTime(23, 59, 59);
        break;

    case 'year':
        $rangeStart = $cursor->setDate((int) $cursor->format('Y'), 1, 1)->setTime(0, 0, 0);
        $rangeEnd   = $cursor->setDate((int) $cursor->format('Y'), 12, 31)->setTime(23, 59, 59);
        break;

    case 'list':
    default:
        $rangeStart = null;
        $rangeEnd   = null;
        break;
}

// -----------------------------------------------------------------------------
// 🗄️ Fetch events. List view keeps its pagination; grid views pull the
// full visible-range set in one query (no pagination — grids show the
// whole window).
// -----------------------------------------------------------------------------
$events      = [];
$totalRows   = 0;
$totalPages  = 1;
$page        = max(1, (int) ($_GET['page'] ?? 1));
$perPage     = 20;

// 👁️ Who may see which event, and in how much detail (#514 part P2).
//
//    WHAT WAS HERE BEFORE: the literal condition `e.isPublic = 1`, so every
//    view listed only the organisation's PUBLIC events, to everybody —
//    even its own members never saw its members-only events here. The
//    owner decided on 17 September 2026 (answer 4 to the #514 plan) that
//    members SHOULD see their own organisation's members-only events in
//    these views. Events copied in from outside calendars (#514) also need
//    their four levels (public, members, selected groups, hidden) honoured.
//
//    NOW: the one shared rule, `EventVisibility::where()` in "session"
//    mode, decides row by row inside the database. It admits a public
//    event to anybody, a members-only event to an active member of THAT
//    event's own organisation (or its administrator), and an imported
//    event by its own level. Nothing is decided in PHP afterwards.
//
//    Where the fragment goes, and why there: it is appended as the LAST
//    element of this first array, straight after `e.siteID = ?`, and its
//    values are appended to $types/$params at the same moment, BEFORE the
//    category, type, location, search and date filters below add theirs.
//    bind_param() matches values to `?` marks by position only, and
//    $params starts with $siteId for `e.siteID = ?`. Putting the fragment
//    where `isPublic` used to sit (ahead of `siteID`) would have needed its
//    values bound before $siteId, which this file does not do. Keeping the
//    page's own literal conditions first also keeps them where
//    tools/audit-checks/check_sql_columns.py can read them; it cannot read
//    inside a piece of SQL built in a variable, so the fragment's own
//    column names are checked by tools/event-visibility-selftest.php
//    instead, which prepares every mode against the real tables.
//
//    `where()` returns its SQL starting with " AND " (so most callers can
//    add it straight after their own WHERE). This page joins its
//    conditions with implode(' AND ', ...), so that leading " AND " is cut
//    off first; the check below refuses anything else rather than guess.
$viewerId   = EventVisibility::sessionViewerId();
$today      = date('Y-m-d');
$visibility = EventVisibility::where('e', EventVisibility::MODE_SESSION, $viewerId, $today);
if (str_starts_with($visibility['sql'], ' AND ') === false) {
    throw new \LogicException('EventVisibility::where() no longer starts with " AND "; calendar/index.php must be updated.');
}

$conditions = ["e.isDeleted = 0", "e.status = 'published'", "e.siteID = ?", substr($visibility['sql'], 5)];
$params     = array_merge([$siteId], $visibility['params']);
$types      = 'i' . $visibility['types'];

// 🙈 Hidden imported events never appear in these grids and lists — not
//    even to administrators, who can see them (the rule above lets them
//    in). An administrator opens such an event by its direct link (and,
//    once part P8 of #514 builds it, from the outside calendar's own
//    page). Why: a grid shown on a shared screen
//    or over somebody's shoulder must never carry an event its calendar's
//    settings hid. The portal's OWN events are untouched by this line
//    (their importLevel is never read). The #514 plan, part P2 row a.
$conditions[] = "(e.externalFeedID IS NULL OR e.importLevel <> 'hidden')";

// 🔎 "May this viewer see the full details?" — the same answer the rule
//    gives for the canSeeFull column (1 = full details, 0 = title, date
//    and time only). Selected beside every row below, and ALSO used inside
//    the location and search filters, because a filter that matched on a
//    hidden detail would tell the visitor that detail through the list of
//    results (leak-hunt finding 5 in the #514 plan: searching for a word
//    that appears only in a limited event's description would otherwise
//    reveal it by returning the event).
//
//    MySQL does not let a WHERE clause use a name given in the SELECT
//    list, so the filters need the bare CASE expression. fullDetailSelect()
//    returns it as "CASE ... END AS canSeeFull" (its documented shape), so
//    the name is cut off the end here, and anything else is refused rather
//    than guessed at. Its values are bound wherever the expression's `?`
//    marks sit, which differs between the SELECT list and each filter;
//    each use below appends them at its own position.
$fullDetail = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_SESSION, $viewerId, $today, 'canSeeFull');
if (str_ends_with($fullDetail['sql'], ' AS canSeeFull') === false) {
    throw new \LogicException('EventVisibility::fullDetailSelect() no longer ends with " AS canSeeFull"; calendar/index.php must be updated.');
}
$fullDetailExpr = substr($fullDetail['sql'], 0, -strlen(' AS canSeeFull'));

if ($filterCategory !== '') {
    $conditions[] = 'e.categoryID = ?';
    $params[]     = (int) $filterCategory;
    $types       .= 'i';
}
if ($filterType !== '') {
    $conditions[] = 'e.typeID = ?';
    $params[]     = (int) $filterType;
    $types       .= 'i';
}
// 🔍 Faceted filters (#330)
//    #514 part P2: the location, and the description half of the search,
//    are matched only for rows whose full details this viewer may see (the
//    canSeeFull expression above). Before, a filter matched on the stored
//    text whatever the viewer could see, so a limited event would appear in
//    the results for a word that is only in its hidden description or
//    location — telling the visitor that word is there. The event's NAME
//    is always shown, so the name half of the search stays open to all.
//    The values are bound in the order the `?` marks appear: the
//    expression's own values first, then the LIKE text (location); the name
//    text, the expression's values, then the description text (search).
if ($filterLocation !== '') {
    $conditions[] = '(' . $fullDetailExpr . ' = 1 AND e.locationName LIKE ?)';
    $params       = array_merge($params, $fullDetail['params'], ['%' . $filterLocation . '%']);
    $types       .= $fullDetail['types'] . 's';
}
if ($filterSearch !== '') {
    $conditions[] = '(e.eventName LIKE ? OR (' . $fullDetailExpr . ' = 1 AND e.description LIKE ?))';
    $needle       = '%' . $filterSearch . '%';
    $params       = array_merge($params, [$needle], $fullDetail['params'], [$needle]);
    $types       .= 's' . $fullDetail['types'] . 's';
}
if ($filterFrom !== '') {
    $conditions[] = 'e.startDateTime >= ?';
    $params[]     = $filterFrom . ' 00:00:00';
    $types       .= 's';
}
if ($filterTo !== '') {
    $conditions[] = 'e.startDateTime <= ?';
    $params[]     = $filterTo . ' 23:59:59';
    $types       .= 's';
}

if ($view === 'list') {
    if ($showPast === false) {
        $conditions[] = 'e.startDateTime >= NOW()';
    }
    $where = 'WHERE ' . implode(' AND ', $conditions);

    // 📋 Count — the same WHERE as the fetch below, visibility rule included
    //    (#514 part P2), so the page count never includes rows the viewer
    //    may not see.
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblEvents e ' . $where);
    if ($stmt !== false) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $totalRows = (int) ($row['cnt'] ?? 0);
        $stmt->close();
    }
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $offset     = ($page - 1) * $perPage;

    $orderDir = $showPast === true ? 'DESC' : 'ASC';
    // 👁️ #514 part P2: canSeeFull and externalFeedID are selected beside
    //    every row, and the row goes through EventVisibility::redact()
    //    before any view sees it, so a limited event reaches the views with
    //    its description, location and image already emptied. The
    //    expression's values are bound FIRST, because the SELECT list comes
    //    before the WHERE in the statement text. It goes in through
    //    sprintf()'s `%s` rather than by joining it in with `.`, because
    //    tools/audit-checks/check_sql_columns.py does not recognise a
    //    statement at all when PHP code sits between SELECT and FROM
    //    (measured while building #514 part P2); the text sprintf() puts in
    //    is SQL built by EventVisibility itself.
    $sql = sprintf(
        'SELECT e.eventID, e.eventName, e.eventSlug, e.description, '
         . 'e.startDateTime, e.endDateTime, e.timezone, e.isAllDay, '
         . 'e.locationName, e.locationAddress, e.status, e.isFeatured, '
         . 'e.heroImage, e.externalFeedID, '
         . 'c.categoryName, c.color AS categoryColor, c.displayStyle AS categoryDisplayStyle, '
         . 't.typeName, s.seriesName, %s '
         . 'FROM tblEvents e '
         . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
         . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
         . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID ',
        $fullDetail['sql']
    )
         . $where . ' '
         . 'ORDER BY e.startDateTime ' . $orderDir . ' '
         . 'LIMIT ? OFFSET ?';

    $fetchTypes  = $fullDetail['types'] . $types . 'ii';
    $fetchParams = array_merge($fullDetail['params'], $params, [$perPage, $offset]);

    $stmt = $mysqli->prepare($sql);
    if ($stmt !== false) {
        $stmt->bind_param($fetchTypes, ...$fetchParams);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            // canSeeFull comes back as the number 1 or 0, never true/false.
            $events[] = EventVisibility::redact($r, (int) $r['canSeeFull'] === 1);
        }
        $stmt->close();
    }
} else {
    // Grid views: fetch every event overlapping the visible range.
    // An event overlaps if it starts BEFORE the range ends AND
    // (it has no end date OR it ends AFTER the range starts).
    $startStr = $rangeStart->format('Y-m-d H:i:s');
    $endStr   = $rangeEnd->format('Y-m-d H:i:s');

    $conditions[] = 'e.startDateTime <= ?';
    $params[]     = $endStr;
    $types       .= 's';

    $conditions[] = '(e.endDateTime IS NULL OR e.endDateTime >= ?)';
    $params[]     = $startStr;
    $types       .= 's';

    $where = 'WHERE ' . implode(' AND ', $conditions);

    // 👁️ #514 part P2: canSeeFull and externalFeedID are selected beside
    //    every row, and the row goes through EventVisibility::redact()
    //    before any view sees it, so a limited event reaches the views with
    //    its description, location and image already emptied. The
    //    expression's values are bound FIRST, because the SELECT list comes
    //    before the WHERE in the statement text. It goes in through
    //    sprintf()'s `%s` rather than by joining it in with `.`, because
    //    tools/audit-checks/check_sql_columns.py does not recognise a
    //    statement at all when PHP code sits between SELECT and FROM
    //    (measured while building #514 part P2); the text sprintf() puts in
    //    is SQL built by EventVisibility itself.
    $sql = sprintf(
        'SELECT e.eventID, e.eventName, e.eventSlug, e.description, '
         . 'e.startDateTime, e.endDateTime, e.timezone, e.isAllDay, '
         . 'e.locationName, e.locationAddress, e.status, e.isFeatured, '
         . 'e.heroImage, e.externalFeedID, '
         . 'c.categoryName, c.color AS categoryColor, c.displayStyle AS categoryDisplayStyle, '
         . 't.typeName, s.seriesName, %s '
         . 'FROM tblEvents e '
         . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
         . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
         . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID ',
        $fullDetail['sql']
    )
         . $where . ' '
         . 'ORDER BY e.startDateTime ASC';

    $stmt = $mysqli->prepare($sql);
    if ($stmt !== false) {
        $stmt->bind_param($fullDetail['types'] . $types, ...array_merge($fullDetail['params'], $params));
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            // canSeeFull comes back as the number 1 or 0, never true/false.
            $events[] = EventVisibility::redact($r, (int) $r['canSeeFull'] === 1);
        }
        $stmt->close();
    }
    $totalRows  = count($events);
    $totalPages = 1;
}

// -----------------------------------------------------------------------------
// 🏛️ Venue Bookings overlay (#429) — per-day "is this day booked at an
// external venue?" strip, consumed by views/_venue_strip.php across the
// grid views. Guarded behind AppRegistry::isEnabled('venues') + try/catch
// so a disabled app, a missing _core/apps/venues.php registry entry, OR
// any Venues:: exception leaves $venueOverlay = [] and the calendar
// renders byte-identical to pre-#429 output (security item 14 — the hard
// resilience requirement). List view has no fixed date range, so it never
// computes an overlay.
// -----------------------------------------------------------------------------
$venueOverlay = [];
if ($view !== 'list' && $rangeStart !== null && $rangeEnd !== null && AppRegistry::isEnabled('venues') === true) {
    try {
        $venueOverlay = Venues::availabilityForRange(
            $siteId,
            null,
            $rangeStart->format('Y-m-d'),
            $rangeEnd->format('Y-m-d')
        );
    } catch (\Throwable $e) {
        error_log('Calendar venue overlay failed: ' . $e->getMessage());
        $venueOverlay = [];
    }
}

// -----------------------------------------------------------------------------
// 🏷️ Categories + types — for filter dropdowns AND for colour-coding events
// -----------------------------------------------------------------------------
$categories = [];
$stmtCat = $mysqli->prepare(
    'SELECT categoryID, categoryName, color, displayStyle '
    . 'FROM tblEventCategories '
    . 'WHERE isActive = 1 AND siteID = ? ORDER BY sortOrder, categoryName'
);
if ($stmtCat !== false) {
    $stmtCat->bind_param('i', $siteId);
    $stmtCat->execute();
    $resultCat = $stmtCat->get_result();
    while ($r = $resultCat->fetch_assoc()) {
        $categories[] = $r;
    }
    $stmtCat->close();
}

$eventTypes = [];
$stmtType = $mysqli->prepare(
    'SELECT typeID, typeName FROM tblEventTypes '
    . 'WHERE isActive = 1 AND parentID IS NULL AND siteID = ? '
    . 'ORDER BY sortOrder, typeName'
);
if ($stmtType !== false) {
    $stmtType->bind_param('i', $siteId);
    $stmtType->execute();
    $resultType = $stmtType->get_result();
    while ($r = $resultType->fetch_assoc()) {
        $eventTypes[] = $r;
    }
    $stmtType->close();
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📅 Calendar shell -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-calendar-days me-2"></i>Calendar</h1>
    <div class="d-flex gap-2">
        <?php if (App::isAdmin() === true): ?>
            <a href="/calendar/manage" class="btn btn-outline-primary">
                <i class="fa-solid fa-list-check me-1"></i> Manage Events
            </a>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . '_shared_header.php'; ?>

<?php
// 🚦 Dispatch to the active view's partial
$partial = __DIR__ . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $view . '.php';
if (is_file($partial) === true) {
    require $partial;
} else {
    // 🛟 Should never happen — $view is whitelisted above.
    require __DIR__ . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'list.php';
}
?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
