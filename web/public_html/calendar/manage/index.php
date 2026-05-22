<?php
// Path: public_html/calendar/manage/index.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Management 📅
 * -----------------------------------------------------------------------------
 * Admin page for listing, creating, and editing events. Shows all events
 * including drafts and deleted (with filter). Uses modals or inline forms
 * for create/edit operations.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Manage Events';
$pageSection = 'calendar';
$breadcrumbs = ['Dashboard' => '/', 'Calendar' => '/calendar', 'Manage' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🌐 Multi-site scope
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 🔍 Check if we're editing a specific event
// -----------------------------------------------------------------------------
$editId    = (int) ($_GET['edit'] ?? 0);
$editEvent = null;
$editPeople = [];

if ($editId > 0) {
    $stmt = $mysqli->prepare('SELECT * FROM tblEvents WHERE eventID = ? AND siteID = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $editId, $siteId);
        $stmt->execute();
        $editEvent = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($editEvent !== null) {
        // 📋 Fetch event people for edit form
        $stmt = $mysqli->prepare(
            'SELECT ep.*, u.fullName FROM tblEventPeople ep '
            . 'LEFT JOIN tblUsers u ON u.userID = ep.userID '
            . 'WHERE ep.eventID = ? ORDER BY ep.sortOrder'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $editId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($r = $result->fetch_assoc()) {
                $editPeople[] = $r;
            }
            $stmt->close();
        }

        $pageTitle = 'Edit: ' . $editEvent['eventName'];
        $breadcrumbs = ['Dashboard' => '/', 'Calendar' => '/calendar', 'Manage' => '/calendar/manage', 'Edit' => ''];
    }
}

// -----------------------------------------------------------------------------
// 📋 Fetch reference data for forms
// -----------------------------------------------------------------------------
$categories = [];
$stmtCat = $mysqli->prepare('SELECT categoryID, categoryName FROM tblEventCategories WHERE isActive = 1 AND siteID = ? ORDER BY sortOrder');
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
$stmtType = $mysqli->prepare('SELECT typeID, parentID, typeName FROM tblEventTypes WHERE isActive = 1 AND siteID = ? ORDER BY sortOrder');
if ($stmtType !== false) {
    $stmtType->bind_param('i', $siteId);
    $stmtType->execute();
    $resultType = $stmtType->get_result();
    while ($r = $resultType->fetch_assoc()) {
        $eventTypes[] = $r;
    }
    $stmtType->close();
}

$seriesList = [];
$stmtSeries = $mysqli->prepare('SELECT seriesID, seriesName FROM tblEventSeries WHERE isActive = 1 AND siteID = ? ORDER BY seriesName');
if ($stmtSeries !== false) {
    $stmtSeries->bind_param('i', $siteId);
    $stmtSeries->execute();
    $resultSeries = $stmtSeries->get_result();
    while ($r = $resultSeries->fetch_assoc()) {
        $seriesList[] = $r;
    }
    $stmtSeries->close();
}

// 📋 Flash message
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// -----------------------------------------------------------------------------
// 📋 Fetch event list (if not editing)
// -----------------------------------------------------------------------------
$events = [];
if ($editEvent === null) {
    $filterStatus = trim($_GET['status'] ?? '');
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 25;
    $offset  = ($page - 1) * $perPage;

    $conditions = ['e.isDeleted = 0', 'e.siteID = ?'];
    $params = [$siteId];
    $types  = 'i';

    if ($filterStatus !== '') {
        $conditions[] = 'e.status = ?';
        $params[]     = $filterStatus;
        $types       .= 's';
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $countSql  = 'SELECT COUNT(*) AS cnt FROM tblEvents e ' . $where;
    $totalRows = 0;
    if (count($params) > 0) {
        $stmt = $mysqli->prepare($countSql);
        if ($stmt !== false) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $totalRows = (int) ($row['cnt'] ?? 0);
            $stmt->close();
        }
    } else {
        $result = $mysqli->query($countSql);
        if ($result !== false) {
            $row = $result->fetch_assoc();
            $totalRows = (int) ($row['cnt'] ?? 0);
        }
    }
    $totalPages = max(1, (int) ceil($totalRows / $perPage));

    $sql = 'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, e.status, '
         . 'e.isPublic, e.isFeatured, c.categoryName, t.typeName, s.seriesName '
         . 'FROM tblEvents e '
         . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
         . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
         . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
         . $where . ' ORDER BY e.startDateTime DESC LIMIT ? OFFSET ?';

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
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($editEvent !== null): ?>
<!-- ✏️ Edit Event Form -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-pen me-2"></i>Edit Event</h1>
    <a href="/calendar/manage" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back to List</a>
</div>

<form method="post" action="/calendar/manage/save" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="eventID" value="<?php echo (int) $editEvent['eventID']; ?>">

    <?php require __DIR__ . DIRECTORY_SEPARATOR . '_event_form.php'; ?>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save Changes</button>
        <a href="/calendar/manage" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php else: ?>
<!-- 📋 Event List + Create -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-list-check me-2"></i>Manage Events</h1>
    <div class="d-flex gap-2 flex-wrap">
        <a href="/calendar/manage/types" class="btn btn-outline-secondary">
            <i class="fa-solid fa-tags me-1"></i> Categories &amp; Types
        </a>
        <a href="/calendar/manage/month-themes" class="btn btn-outline-secondary">
            <i class="fa-solid fa-quote-left me-1"></i> Month Themes
        </a>
        <button class="btn btn-success" data-bs-toggle="collapse" data-bs-target="#createEventForm">
            <i class="fa-solid fa-plus me-1"></i> Create Event
        </button>
    </div>
</div>

<!-- ➕ Create event form (collapsible) -->
<div class="collapse mb-4" id="createEventForm">
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Create New Event</h5></div>
        <div class="card-body">
            <form method="post" action="/calendar/manage/save" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create">

                <?php $editEvent = null; require __DIR__ . DIRECTORY_SEPARATOR . '_event_form.php'; ?>

                <div class="mt-3">
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>Create Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 🔍 Filters -->
<form method="get" action="/calendar/manage" class="row g-2 mb-4">
    <div class="col-12 col-md-4">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <option value="draft" <?php echo ($filterStatus ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
            <option value="published" <?php echo ($filterStatus ?? '') === 'published' ? 'selected' : ''; ?>>Published</option>
            <option value="cancelled" <?php echo ($filterStatus ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            <option value="postponed" <?php echo ($filterStatus ?? '') === 'postponed' ? 'selected' : ''; ?>>Postponed</option>
        </select>
    </div>
    <div class="col-12 col-md-4">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
            <i class="fa-solid fa-filter me-1"></i> Filter
        </button>
    </div>
</form>

<!-- 📋 Event list -->
<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-4">Event</div>
        <div class="col-md-2">Date</div>
        <div class="col-md-2">Status</div>
        <div class="col-md-2">Category</div>
        <div class="col-md-2 text-end">Actions</div>
    </div>

    <?php if (count($events) === 0): ?>
        <div class="portal-data-row">
            <div class="col-12 text-center text-muted py-3">No events found.</div>
        </div>
    <?php endif; ?>

    <?php foreach ($events as $ev): ?>
        <?php
        $statusClass = match ($ev['status']) {
            'published' => 'success',
            'draft'     => 'secondary',
            'cancelled' => 'danger',
            'postponed' => 'warning',
            default     => 'secondary',
        };
        ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">Event: </span>
                <strong><?php echo htmlspecialchars($ev['eventName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($ev['isFeatured'] === '1'): ?>
                    <i class="fa-solid fa-star text-warning ms-1" title="Featured"></i>
                <?php endif; ?>
                <?php if ($ev['isPublic'] === '0' || (int) $ev['isPublic'] === 0): ?>
                    <i class="fa-solid fa-lock text-muted ms-1" title="Private"></i>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Date: </span>
                <small><?php echo htmlspecialchars(\Portal\Core\I18n::formatDateTime($ev['startDateTime']), ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Status: </span>
                <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($ev['status']), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Category: </span>
                <small><?php echo htmlspecialchars($ev['categoryName'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <a href="/calendar/event?slug=<?php echo htmlspecialchars($ev['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>"
                   class="btn btn-sm btn-outline-secondary me-1" title="View">
                    <i class="fa-solid fa-eye"></i>
                </a>
                <a href="/calendar/manage?edit=<?php echo (int) $ev['eventID']; ?>"
                   class="btn btn-sm btn-outline-primary me-1" title="Edit">
                    <i class="fa-solid fa-pen"></i>
                </a>
                <form method="post" action="/calendar/manage/delete" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="eventID" value="<?php echo (int) $ev['eventID']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"
                            onclick="return confirm('Delete this event?');">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- 📖 Pagination -->
<?php if (isset($totalPages) === true && $totalPages > 1): ?>
    <nav aria-label="Event list pagination" class="mt-4">
        <ul class="pagination justify-content-center">
            <?php
            $baseUrl = '/calendar/manage?' . http_build_query(array_filter(['status' => $filterStatus ?? '']));
            $sep = '&';
            ?>
            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . ($page - 1), ENT_QUOTES, 'UTF-8'); ?>">&laquo;</a>
            </li>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . $i, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . ($page + 1), ENT_QUOTES, 'UTF-8'); ?>">&raquo;</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
