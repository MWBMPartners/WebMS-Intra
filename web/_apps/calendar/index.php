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
use Portal\Core\Auth;
use Portal\Core\Site;

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
$showPast       = ($_GET['past'] ?? '') === '1';   // list-view only

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

$conditions = ["e.isDeleted = 0", "e.status = 'published'", "e.isPublic = 1", "e.siteID = ?"];
$params     = [$siteId];
$types      = 'i';

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

if ($view === 'list') {
    if ($showPast === false) {
        $conditions[] = 'e.startDateTime >= NOW()';
    }
    $where = 'WHERE ' . implode(' AND ', $conditions);

    // 📋 Count
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
    $sql = 'SELECT e.eventID, e.eventName, e.eventSlug, e.description, '
         . 'e.startDateTime, e.endDateTime, e.timezone, e.isAllDay, '
         . 'e.locationName, e.locationAddress, e.status, e.isFeatured, '
         . 'e.heroImage, '
         . 'c.categoryName, c.color AS categoryColor, c.displayStyle AS categoryDisplayStyle, '
         . 't.typeName, s.seriesName '
         . 'FROM tblEvents e '
         . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
         . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
         . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
         . $where . ' '
         . 'ORDER BY e.startDateTime ' . $orderDir . ' '
         . 'LIMIT ? OFFSET ?';

    $fetchTypes  = $types . 'ii';
    $fetchParams = array_merge($params, [$perPage, $offset]);

    $stmt = $mysqli->prepare($sql);
    if ($stmt !== false) {
        $stmt->bind_param($fetchTypes, ...$fetchParams);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $events[] = $r;
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

    $sql = 'SELECT e.eventID, e.eventName, e.eventSlug, e.description, '
         . 'e.startDateTime, e.endDateTime, e.timezone, e.isAllDay, '
         . 'e.locationName, e.locationAddress, e.status, e.isFeatured, '
         . 'e.heroImage, '
         . 'c.categoryName, c.color AS categoryColor, c.displayStyle AS categoryDisplayStyle, '
         . 't.typeName, s.seriesName '
         . 'FROM tblEvents e '
         . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
         . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
         . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
         . $where . ' '
         . 'ORDER BY e.startDateTime ASC';

    $stmt = $mysqli->prepare($sql);
    if ($stmt !== false) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $events[] = $r;
        }
        $stmt->close();
    }
    $totalRows  = count($events);
    $totalPages = 1;
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
