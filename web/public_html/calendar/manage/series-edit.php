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
use Portal\Core\I18n;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Bulk Edit Series Events';
$pageSection = 'calendar';
$breadcrumbs = [
    'Dashboard' => '/',
    'Calendar'  => '/calendar',
    'Manage'    => '/calendar/manage',
    'Series'    => '/calendar/manage/series',
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
    header('Location: /calendar/manage/series');
    exit();
}

// 📋 Fetch series details
$series = null;
$stmt = $mysqli->prepare('SELECT seriesID, seriesName FROM tblEventSeries WHERE seriesID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $seriesID, $siteId);
    $stmt->execute();
    $series = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($series === null) {
    $_SESSION['flash_msg']  = 'Series not found.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /calendar/manage/series');
    exit();
}

// -----------------------------------------------------------------------------
// 💾 Handle POST — apply bulk changes
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /calendar/manage/series');
        exit();
    }

    $selectedIds = $_POST['eventIDs'] ?? [];
    if (is_array($selectedIds) === false || count($selectedIds) === 0) {
        $_SESSION['flash_msg']  = 'No events selected.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: /calendar/manage/series-edit?seriesID=' . $seriesID);
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
        header('Location: /calendar/manage/series-edit?seriesID=' . $seriesID);
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
        header('Location: /calendar/manage/series-edit?seriesID=' . $seriesID);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($validIds), '?'));
    $paramTypes  .= str_repeat('i', count($validIds));
    $paramValues  = array_merge($paramValues, $validIds);

    // 📋 Add siteID and seriesID filters
    $paramTypes   .= 'ii';
    $paramValues[] = $seriesID;
    $paramValues[] = $siteId;

    $sql = 'UPDATE tblEvents SET ' . implode(', ', $setClauses)
         . ' WHERE eventID IN (' . $placeholders . ') AND seriesID = ? AND siteID = ?';

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
    header('Location: /calendar/manage/series-edit?seriesID=' . $seriesID);
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
    . 'FROM tblEvents e '
    . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
    . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
    . 'WHERE e.seriesID = ? AND e.siteID = ? AND e.isDeleted = 0 '
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
    <a href="/calendar/manage/series" class="btn btn-outline-secondary">
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
        No events in this series. <a href="/calendar/manage" class="alert-link">Create events</a> and assign them to this series.
    </div>
<?php else: ?>

<form method="post" action="/calendar/manage/series-edit">
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

<script>
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

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
