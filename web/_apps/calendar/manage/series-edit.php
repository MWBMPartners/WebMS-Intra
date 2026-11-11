<?php
// Path: public_html/calendar/manage/series-edit.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Series Bulk Edit ✏️
 * -----------------------------------------------------------------------------
 * Displays all events belonging to a series and allows bulk updates to common
 * fields (status, category, type, visibility, featured). Admins can select
 * individual events or use "select all" to apply changes in one operation.
 *
 * ♻️ HIDDEN DATES (#691; the owner's decisions D-143, D-144, D-145). Below the list, the page shows every HIDDEN date of
 * the series — a date a pattern change removed, or one deleted on its own —
 * with a button to bring each back AS A DRAFT. `Portal\Core\HiddenSeriesDates`
 * decides everything about it (which dates are listed, whether one may come
 * back, and the words for each answer); this page only draws the list and
 * posts the button. It lives here, not on the pattern page, because this is
 * where the pattern page sends people afterwards and where a draft is
 * published. No new address was needed, so no route and no migration.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/75
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\HiddenSeriesDates;
use Portal\Core\I18n;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Bulk Edit Series Events';
$pageSection = 'calendar';
$breadcrumbs = [
    'Dashboard' => Site::url(''),
    'Calendar'  => Site::url('calendar'),
    'Manage'    => Site::url('calendar/manage'),
    'Series'    => Site::url('calendar/manage/series'),
    'Bulk Edit' => '',
];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🌐 Multi-site scope
$siteId = Site::id();
$userId = $_SESSION['user_id'] ?? null;

// 📋 Identify the series
$seriesID = (int) ($_GET['seriesID'] ?? $_POST['seriesID'] ?? 0);
if ($seriesID <= 0) {
    $_SESSION['flash_msg']  = 'Invalid series ID.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar/manage/series'));
    exit();
}

// 📋 Fetch series details
//
// #514 part P8 (chunk P8-3, challenge M5): a series that holds an event
// copied in from an outside calendar is the importer's, managed on that
// calendar's own page (who may see it, through a choice) — never edited
// here. Part 6 handed this page over in writing, and before this change it
// opened any series of the organisation by number, although its list and
// its update already skip imported events. The test is IN THIS ONE
// STATEMENT, the shape `series.php` already uses for its list: a series is
// recognised as imported by the events in it, never by its "imp-" address
// (an administrator can type a name that makes the same address). No event
// row is filtered on `isDeleted`, so a series whose imported dates were
// removed stays refused too. A series holding imported events, a number
// that does not exist and another organisation's number therefore run the
// SAME statement and get the SAME answer below ("Series not found."), so
// none of the three can be told apart.
$series = null;
$stmt = $mysqli->prepare(
    'SELECT s.seriesID, s.seriesName FROM tblEventSeries s '
    . 'WHERE s.seriesID = ? AND s.siteID = ? '
    . '  AND NOT EXISTS (SELECT 1 FROM tblEvents xi WHERE xi.seriesID = s.seriesID AND xi.externalFeedID IS NOT NULL) '
    . 'LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $seriesID, $siteId);
    $stmt->execute();
    $series = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($series === null) {
    $_SESSION['flash_msg']  = 'Series not found.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . Site::url('calendar/manage/series'));
    exit();
}

// -----------------------------------------------------------------------------
// 💾 Handle POST — apply bulk changes
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('calendar/manage/series'));
        exit();
    }

    // ♻️ BRINGING A HIDDEN DATE BACK (#691). Its own form, told apart by
    //    `action`, and answered before the bulk update is even looked at, so
    //    one press can never do both. Everything that matters — the
    //    organisation, the series, the lock, the day and the address — is
    //    decided by `HiddenSeriesDates::restore()`; this branch only passes it
    //    the numbers and shows its answer. The gate in front of it is the one
    //    in front of the whole page (`App::isAdmin()`, above) and the form
    //    token just checked, so it is gated exactly as the rest of the series
    //    pages are, with no second rule of its own.
    //
    // 🤝 THE SECOND PRESS (the owner, 11 November 2026 — D-144). A date whose
    //    day the series already uses is refused on the first press; the page
    //    then offers "Bring it back anyway", whose form carries
    //    `confirmSameDay=1`. Only that exact value counts, and it is handed to
    //    `restore()` as a plain yes/no: whether the day is REALLY taken is
    //    decided there, under its lock, so a confirmation can never stand in
    //    for the check.
    if ((string) ($_POST['action'] ?? '') === 'restore') {
        $restoreEventID = (int) ($_POST['restoreEventID'] ?? 0);
        $outcome        = HiddenSeriesDates::restore(
            $mysqli,
            $siteId,
            $seriesID,
            $restoreEventID,
            $userId === null ? null : (int) $userId,
            (string) ($_POST['confirmSameDay'] ?? '') === '1'
        );
        // 📌 Remember which date was refused for its day, so the page drawn
        //    after the redirect can offer the deliberate second press beside
        //    THAT date only. Read once and dropped (see the card below). It is
        //    a convenience for the page, not a permission: the confirmed press
        //    is judged afresh by `restore()` whatever this holds.
        if ($outcome === HiddenSeriesDates::DAY_TAKEN) {
            $_SESSION['seriesRestoreOffer'] = ['seriesID' => $seriesID, 'eventID' => $restoreEventID];
        }
        $said                   = HiddenSeriesDates::words($outcome);
        $_SESSION['flash_msg']  = $said['words'];
        $_SESSION['flash_type'] = $said['type'];
        header('Location: ' . Site::url('calendar/manage/series-edit') . '?seriesID=' . $seriesID);
        exit();
    }

    $selectedIds = $_POST['eventIDs'] ?? [];
    if (is_array($selectedIds) === false || count($selectedIds) === 0) {
        $_SESSION['flash_msg']  = 'No events selected.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . Site::url('calendar/manage/series-edit') . '?seriesID=' . $seriesID);
        exit();
    }

    // 📋 Collect fields to update (only non-empty values)
    $setClauses  = [];
    $paramTypes  = '';
    $paramValues = [];

    $bulkStatus = trim($_POST['bulkStatus'] ?? '');
    if ($bulkStatus !== '' && in_array($bulkStatus, ['draft', 'published', 'cancelled', 'postponed'], true) === true) {
        $setClauses[]  = 'status = ?';
        $paramTypes   .= 's';
        $paramValues[] = $bulkStatus;
    }

    $bulkCategoryID = trim($_POST['bulkCategoryID'] ?? '');
    if ($bulkCategoryID !== '') {
        $catVal = ((int) $bulkCategoryID) ?: null;
        $setClauses[]  = 'categoryID = ?';
        $paramTypes   .= 'i';
        $paramValues[] = $catVal;
    }

    $bulkTypeID = trim($_POST['bulkTypeID'] ?? '');
    if ($bulkTypeID !== '') {
        $typeVal = ((int) $bulkTypeID) ?: null;
        $setClauses[]  = 'typeID = ?';
        $paramTypes   .= 'i';
        $paramValues[] = $typeVal;
    }

    $bulkIsPublic = trim($_POST['bulkIsPublic'] ?? '');
    if ($bulkIsPublic !== '') {
        $setClauses[]  = 'isPublic = ?';
        $paramTypes   .= 'i';
        $paramValues[] = (int) $bulkIsPublic;
    }

    $bulkIsFeatured = trim($_POST['bulkIsFeatured'] ?? '');
    if ($bulkIsFeatured !== '') {
        $setClauses[]  = 'isFeatured = ?';
        $paramTypes   .= 'i';
        $paramValues[] = (int) $bulkIsFeatured;
    }

    if (count($setClauses) === 0) {
        $_SESSION['flash_msg']  = 'No fields to update. Select at least one field to change.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . Site::url('calendar/manage/series-edit') . '?seriesID=' . $seriesID);
        exit();
    }

    // 🔄 Add updatedByID
    $setClauses[]  = 'updatedByID = ?';
    $paramTypes   .= 'i';
    $paramValues[] = $userId;

    // 📋 Build IN clause for selected event IDs
    $validIds = array_map('intval', $selectedIds);
    $validIds = array_filter($validIds, function (int $id): bool {
        return $id > 0;
    });

    if (count($validIds) === 0) {
        $_SESSION['flash_msg']  = 'No valid events selected.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . Site::url('calendar/manage/series-edit') . '?seriesID=' . $seriesID);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($validIds), '?'));
    $paramTypes  .= str_repeat('i', count($validIds));
    $paramValues  = array_merge($paramValues, $validIds);

    // 📋 Add siteID and seriesID filters
    $paramTypes   .= 'ii';
    $paramValues[] = $seriesID;
    $paramValues[] = $siteId;

    // Imported events are read-only (#514 D5); the fetch below already keeps them off the checkbox
    // list, but the condition is repeated on the write itself so a forged eventID in the posted list
    // updates 0 rows for that id rather than silently overwriting a row the source calendar owns.
    //
    // ♻️ `isDeleted = 0` FOR THE SAME REASON (#691). The checkbox list shows live dates only, but the
    //    write did not say so, so a forged number could change a HIDDEN date's status — publishing a
    //    date that is not even in the diary, which would then come back "published" by a route that
    //    never went through `HiddenSeriesDates`. Hidden dates are changed only by being brought back.
    $sql = 'UPDATE tblEvents SET ' . implode(', ', $setClauses)
         . ' WHERE eventID IN (' . $placeholders . ') AND seriesID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL';

    $stmt = $mysqli->prepare($sql);
    if ($stmt !== false) {
        $stmt->bind_param($paramTypes, ...$paramValues);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
    } else {
        $affected = 0;
    }

    Logger::activity(
        'SeriesBulkEdit',
        'Bulk updated ' . $affected . ' event(s) in series #' . $seriesID,
        $userId
    );

    $_SESSION['flash_msg']  = $affected . ' event(s) updated successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('calendar/manage/series-edit') . '?seriesID=' . $seriesID);
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Fetch events in this series
// -----------------------------------------------------------------------------
$events = [];
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.eventName, e.startDateTime, e.endDateTime, e.status, '
    . 'e.isPublic, e.isFeatured, e.categoryID, e.typeID, '
    . 'c.categoryName, t.typeName '
    // Imported events are read-only (#514 D5); this keeps them off the bulk-edit checkbox list, since
    // nothing here can save a change to one.
    . 'FROM tblEvents e '
    . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
    . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
    . 'WHERE e.seriesID = ? AND e.siteID = ? AND e.isDeleted = 0 AND e.externalFeedID IS NULL '
    . 'ORDER BY e.startDateTime ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $seriesID, $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
    $stmt->close();
}

// ♻️ The series' hidden dates, for the card at the foot of the page (#691).
$hiddenDates = HiddenSeriesDates::listFor($mysqli, $siteId, $seriesID);

// 🤝 The date, if any, whose first press was just refused for its day (D-144):
//    only that one is offered "Bring it back anyway". Taken out of the session
//    as it is read, so a reload or another series never shows it again.
$restoreOffer = 0;
$offerHeld    = $_SESSION['seriesRestoreOffer'] ?? null;
unset($_SESSION['seriesRestoreOffer']);
if (is_array($offerHeld) === true && (int) ($offerHeld['seriesID'] ?? 0) === $seriesID) {
    $restoreOffer = (int) ($offerHeld['eventID'] ?? 0);
}

// 📋 Fetch categories and types for dropdowns
$categories = [];
$stmtCat = $mysqli->prepare('SELECT categoryID, categoryName FROM tblEventCategories WHERE siteID = ? AND isActive = 1 ORDER BY sortOrder, categoryName');
if ($stmtCat !== false) {
    $stmtCat->bind_param('i', $siteId);
    $stmtCat->execute();
    $resCat = $stmtCat->get_result();
    while ($c = $resCat->fetch_assoc()) {
        $categories[] = $c;
    }
    $stmtCat->close();
}

$types = [];
$stmtType = $mysqli->prepare('SELECT typeID, typeName FROM tblEventTypes WHERE siteID = ? AND isActive = 1 ORDER BY sortOrder, typeName');
if ($stmtType !== false) {
    $stmtType->bind_param('i', $siteId);
    $stmtType->execute();
    $resType = $stmtType->get_result();
    while ($t = $resType->fetch_assoc()) {
        $types[] = $t;
    }
    $stmtType->close();
}

// 📋 Flash message
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 📋 Status badge map
$statusBadges = [
    'draft'     => 'secondary',
    'published' => 'success',
    'cancelled' => 'danger',
    'postponed' => 'warning',
];

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📌 Page header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-0">
            <i class="fa-solid fa-pen-ruler me-2"></i>Bulk Edit:
            <?php echo htmlspecialchars($series['seriesName'], ENT_QUOTES, 'UTF-8'); ?>
        </h1>
        <small class="text-muted"><?php echo count($events); ?> event(s) in this series</small>
    </div>
    <a href="<?php echo htmlspecialchars(Site::url('calendar/manage/series'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Series
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (count($events) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        No events in this series. <a href="<?php echo htmlspecialchars(Site::url('calendar/manage'), ENT_QUOTES, 'UTF-8'); ?>" class="alert-link">Create events</a> and assign them to this series.
    </div>
<?php else: ?>

<form method="post" action="<?php echo htmlspecialchars(Site::url('calendar/manage/series-edit'), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="seriesID" value="<?php echo (int) $seriesID; ?>">

    <!-- 🔧 Bulk update fields -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fa-solid fa-sliders me-1"></i> Bulk Update Fields</h5>
            <small class="text-muted">Leave a field blank to keep its current value unchanged.</small>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4 col-lg-2">
                    <label class="form-label">Status</label>
                    <select name="bulkStatus" class="form-select form-select-sm">
                        <option value="">— No change —</option>
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="postponed">Postponed</option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label">Category</label>
                    <select name="bulkCategoryID" class="form-select form-select-sm">
                        <option value="">— No change —</option>
                        <option value="0">— None —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo (int) $c['categoryID']; ?>">
                                <?php echo htmlspecialchars($c['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label">Type</label>
                    <select name="bulkTypeID" class="form-select form-select-sm">
                        <option value="">— No change —</option>
                        <option value="0">— None —</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?php echo (int) $t['typeID']; ?>">
                                <?php echo htmlspecialchars($t['typeName'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label">Public</label>
                    <select name="bulkIsPublic" class="form-select form-select-sm">
                        <option value="">— No change —</option>
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label">Featured</label>
                    <select name="bulkIsFeatured" class="form-select form-select-sm">
                        <option value="">— No change —</option>
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- 📋 Event list with checkboxes -->
    <div class="portal-data-list">
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-1 text-center">
                <input type="checkbox" id="selectAllEvents" class="form-check-input" title="Select all">
            </div>
            <div class="col-md-4">Event</div>
            <div class="col-md-2">Date</div>
            <div class="col-md-2">Status</div>
            <div class="col-md-2">Category / Type</div>
            <div class="col-md-1 text-center">Pub</div>
        </div>

        <?php foreach ($events as $ev): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-1 text-center">
                    <input type="checkbox" name="eventIDs[]" value="<?php echo (int) $ev['eventID']; ?>"
                           class="form-check-input portal-event-checkbox">
                </div>
                <div class="col-12 col-md-4">
                    <span class="d-md-none fw-semibold">Event: </span>
                    <strong><?php echo htmlspecialchars($ev['eventName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Date: </span>
                    <?php echo htmlspecialchars(I18n::formatDate($ev['startDateTime'], 'medium'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Status: </span>
                    <span class="badge bg-<?php echo $statusBadges[$ev['status']] ?? 'secondary'; ?>">
                        <?php echo htmlspecialchars(ucfirst($ev['status']), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Cat/Type: </span>
                    <?php
                    $catType = [];
                    if ($ev['categoryName'] !== null && $ev['categoryName'] !== '') {
                        $catType[] = htmlspecialchars($ev['categoryName'], ENT_QUOTES, 'UTF-8');
                    }
                    if ($ev['typeName'] !== null && $ev['typeName'] !== '') {
                        $catType[] = htmlspecialchars($ev['typeName'], ENT_QUOTES, 'UTF-8');
                    }
                    echo count($catType) > 0 ? implode(' / ', $catType) : '<span class="text-muted">—</span>';
                    ?>
                </div>
                <div class="col-12 col-md-1 text-center">
                    <span class="d-md-none fw-semibold">Public: </span>
                    <?php if ((int) $ev['isPublic'] === 1): ?>
                        <i class="fa-solid fa-check text-success"></i>
                    <?php else: ?>
                        <i class="fa-solid fa-xmark text-muted"></i>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 📌 Submit -->
    <div class="d-flex justify-content-between align-items-center mt-3">
        <small class="text-muted"><span id="selectedCount">0</span> event(s) selected</small>
        <button type="submit" class="btn btn-primary" id="bulkUpdateBtn" disabled>
            <i class="fa-solid fa-pen-to-square me-1"></i> Apply Bulk Update
        </button>
    </div>
</form>

<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
// 📋 Select All toggle
var selectAll = document.getElementById('selectAllEvents');
var checkboxes = document.querySelectorAll('.portal-event-checkbox');
var countEl    = document.getElementById('selectedCount');
var submitBtn  = document.getElementById('bulkUpdateBtn');

function updateCount() {
    var checked = document.querySelectorAll('.portal-event-checkbox:checked').length;
    countEl.textContent = checked;
    submitBtn.disabled = (checked === 0);
}

selectAll.addEventListener('change', function () {
    checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
    updateCount();
});

checkboxes.forEach(function (cb) {
    cb.addEventListener('change', function () {
        selectAll.checked = (document.querySelectorAll('.portal-event-checkbox:checked').length === checkboxes.length);
        updateCount();
    });
});
</script>

<?php endif; ?>

<?php if ($hiddenDates !== []): ?>
<!-- ♻️ Hidden dates (#691). OUTSIDE the bulk form above on purpose: a <form>
     inside a <form> is dropped by the browser's parser, and its button would
     then submit the OUTER form — the bulk update — instead of this one. Each
     date has its own small form. -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-eye-slash me-1"></i> Hidden dates
            <span class="badge bg-secondary"><?php echo count($hiddenDates); ?></span></h5>
        <small class="text-muted">
            Dates of this series that were removed by a pattern change <strong>or deleted by hand</strong>, one at
            a time. They were hidden rather than deleted, so nothing attached to them was lost. A hidden date
            carries no mark saying which way it was hidden, so both kinds are listed here.
        </small>
    </div>
    <div class="card-body">
        <p class="small mb-3">
            <strong>A date you bring back arrives as a draft</strong>, so members do not see it until you have
            looked at it and published it (tick it in the list above and set <em>Status</em> to Published).
            <strong>The one exception is a date that was cancelled before it was hidden:</strong> it comes back
            still cancelled, with its reason, so members see it as cancelled rather than as a service that is
            happening. If the series already has a date on that day, the first press is
            refused and tells you so; you can then choose <em>Bring it back anyway</em>, which leaves the series
            with two dates on that day. A later pattern change may hide it again if the new pattern does not
            include its day.
        </p>
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-4">Event</div>
                <div class="col-md-3">Date</div>
                <div class="col-md-2">Was</div>
                <div class="col-md-3"></div>
            </div>
            <?php foreach ($hiddenDates as $hd): ?>
                <div class="portal-data-row" data-hidden-event="<?php echo (int) $hd['eventID']; ?>">
                    <div class="col-12 col-md-4">
                        <span class="d-md-none fw-semibold">Event: </span>
                        <?php echo htmlspecialchars($hd['eventName'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-3">
                        <span class="d-md-none fw-semibold">Date: </span>
                        <?php
                        // 🕰️ A wall-clock reading shown as one: never through
                        //    strtotime(), which would move a time in the hour the
                        //    clocks skip (#777).
                        echo htmlspecialchars(I18n::formatClockDateTime($hd['startDateTime'], 'D j M Y, H:i'), ENT_QUOTES, 'UTF-8');
                        ?>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Was: </span>
                        <span class="badge bg-<?php echo htmlspecialchars($statusBadges[$hd['status']] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(ucfirst($hd['status']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="col-12 col-md-3">
                        <?php if ($hd['dayTaken'] === true): ?>
                            <span class="small text-muted d-block">This series already has a date on that day.</span>
                        <?php endif; ?>
                        <form method="post" action="<?php echo htmlspecialchars(Site::url('calendar/manage/series-edit'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="seriesID" value="<?php echo (int) $seriesID; ?>">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="restoreEventID" value="<?php echo (int) $hd['eventID']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                <?php if ($hd['status'] === 'cancelled'): ?>
                                    <?php // 🚫 D-146: a cancelled date comes back cancelled, and the button says so. ?>
                                    <i class="fa-solid fa-rotate-left me-1"></i> Bring back, still cancelled
                                <?php else: ?>
                                    <i class="fa-solid fa-rotate-left me-1"></i> Bring back as a draft
                                <?php endif; ?>
                            </button>
                        </form>
                        <?php if ($restoreOffer === (int) $hd['eventID'] && $hd['dayTaken'] === true): ?>
                            <?php
                            // 🤝 THE DELIBERATE SECOND PRESS (D-144): its own
                            //    form and its own button, offered only beside
                            //    the date whose first press was just refused.
                            //    `confirmSameDay` is what `restore()` reads.
                            ?>
                            <form method="post" action="<?php echo htmlspecialchars(Site::url('calendar/manage/series-edit'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline" data-restore-anyway="<?php echo (int) $hd['eventID']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="seriesID" value="<?php echo (int) $seriesID; ?>">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="restoreEventID" value="<?php echo (int) $hd['eventID']; ?>">
                                <input type="hidden" name="confirmSameDay" value="1">
                                <button type="submit" class="btn btn-sm btn-warning mt-1">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Bring it back anyway
                                </button>
                            </form>
                            <span class="small text-muted d-block">The series will then have two dates on that day.</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
