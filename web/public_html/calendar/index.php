<?php
// Path: public_html/calendar/index.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Public Event Listing 📅
 * -----------------------------------------------------------------------------
 * Public-facing calendar page showing upcoming and past events. Supports:
 *   - List view (default) with filters by category, type, and date range
 *   - Toggle to show past events
 *   - Pagination
 *   - Links to individual event pages and admin management (if admin)
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
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Calendar';
$pageSection = 'calendar';
$breadcrumbs = ['Dashboard' => '/', 'Calendar' => ''];

// 🛡️ Ensure session for nav state
Auth::ensureSession();

// -----------------------------------------------------------------------------
// 🔍 Filter parameters
// -----------------------------------------------------------------------------
$filterCategory = trim($_GET['category'] ?? '');
$filterType     = trim($_GET['type'] ?? '');
$showPast       = ($_GET['past'] ?? '') === '1';
$page           = max(1, (int) ($_GET['page'] ?? 1));
$perPage        = 20;
$offset         = ($page - 1) * $perPage;

// 🌐 Multi-site scope
$siteId = Site::id();

// 📊 Build WHERE clause
$conditions = ["e.isDeleted = 0", "e.status = 'published'", "e.isPublic = 1", "e.siteID = ?"];
$params     = [$siteId];
$types      = 'i';

if ($showPast === false) {
    $conditions[] = 'e.startDateTime >= NOW()';
}

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

$where = 'WHERE ' . implode(' AND ', $conditions);

// 📋 Count total rows
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

// 📋 Fetch events
$orderDir = $showPast === true ? 'DESC' : 'ASC';
$sql = 'SELECT e.eventID, e.eventName, e.eventSlug, e.description, '
     . 'e.startDateTime, e.endDateTime, e.timezone, e.isAllDay, '
     . 'e.locationName, e.locationAddress, e.status, e.isFeatured, '
     . 'e.heroImage, '
     . 'c.categoryName, t.typeName, s.seriesName '
     . 'FROM tblEvents e '
     . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
     . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
     . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
     . $where . ' '
     . 'ORDER BY e.startDateTime ' . $orderDir . ' '
     . 'LIMIT ? OFFSET ?';

$fetchTypes  = $types . 'ii';
$fetchParams = array_merge($params, [$perPage, $offset]);

$events = [];
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

// 📊 Fetch categories and types for filter dropdowns
$categories = [];
$stmtCat = $mysqli->prepare('SELECT categoryID, categoryName FROM tblEventCategories WHERE isActive = 1 AND siteID = ? ORDER BY sortOrder, categoryName');
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
$stmtType = $mysqli->prepare('SELECT typeID, typeName FROM tblEventTypes WHERE isActive = 1 AND parentID IS NULL AND siteID = ? ORDER BY sortOrder, typeName');
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

<!-- 📅 Calendar Listing -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-calendar-days me-2"></i>Calendar</h1>
    <div class="d-flex gap-2">
        <?php if (App::isAdmin() === true): ?>
            <a href="/calendar/manage" class="btn btn-outline-primary">
                <i class="fa-solid fa-list-check me-1"></i> Manage Events
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- 🔍 Filters -->
<form method="get" action="/calendar" class="row g-2 mb-4">
    <div class="col-12 col-md-3">
        <select name="category" class="form-select form-select-sm">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo (int) $cat['categoryID']; ?>"
                    <?php echo ($filterCategory === (string) $cat['categoryID']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <select name="type" class="form-select form-select-sm">
            <option value="">All Types</option>
            <?php foreach ($eventTypes as $et): ?>
                <option value="<?php echo (int) $et['typeID']; ?>"
                    <?php echo ($filterType === (string) $et['typeID']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($et['typeName'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" name="past" value="1" id="showPast"
                   <?php echo $showPast === true ? 'checked' : ''; ?>>
            <label class="form-check-label" for="showPast">Show past events</label>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
            <i class="fa-solid fa-filter me-1"></i> Filter
        </button>
    </div>
</form>

<!-- 📋 Events list -->
<?php if (count($events) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        <?php echo $showPast === true ? 'No events found matching your filters.' : 'No upcoming events. Check back soon!'; ?>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($events as $event): ?>
            <?php
            $startDt = new DateTime($event['startDateTime']);
            $isToday = $startDt->format('Y-m-d') === date('Y-m-d');
            $isPast  = $startDt < new DateTime();
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card h-100 <?php echo $event['isFeatured'] === '1' ? 'border-warning' : ''; ?> <?php echo $isPast === true ? 'opacity-75' : ''; ?>">
                    <?php if ($event['heroImage'] !== null && $event['heroImage'] !== ''): ?>
                        <img src="/assets/uploads/calendar/<?php echo htmlspecialchars($event['heroImage'], ENT_QUOTES, 'UTF-8'); ?>"
                             class="card-img-top" alt="" style="height:180px;object-fit:cover;">
                    <?php endif; ?>
                    <div class="card-body">
                        <?php if ($event['isFeatured'] === '1'): ?>
                            <span class="badge bg-warning text-dark mb-2"><i class="fa-solid fa-star me-1"></i>Featured</span>
                        <?php endif; ?>

                        <h5 class="card-title">
                            <a href="/calendar/event?slug=<?php echo htmlspecialchars($event['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($event['eventName'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </h5>

                        <!-- 📅 Date/Time -->
                        <p class="card-text mb-1">
                            <i class="fa-regular fa-calendar me-1 text-primary"></i>
                            <strong><?php echo htmlspecialchars(\Portal\Core\I18n::formatDate($startDt->format('Y-m-d H:i:s'), 'long'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if ($event['isAllDay'] !== '1' && (int) $event['isAllDay'] !== 1): ?>
                                <span class="text-muted ms-1"><?php echo htmlspecialchars($startDt->format('g:i A'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark ms-1">All Day</span>
                            <?php endif; ?>
                            <?php if ($isToday === true): ?>
                                <span class="badge bg-success ms-1">Today</span>
                            <?php endif; ?>
                        </p>

                        <!-- 📍 Location -->
                        <?php if ($event['locationName'] !== null && $event['locationName'] !== ''): ?>
                            <p class="card-text mb-1 small text-muted">
                                <i class="fa-solid fa-location-dot me-1"></i>
                                <?php echo htmlspecialchars($event['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        <?php endif; ?>

                        <!-- 🏷️ Category / Type badges -->
                        <div class="mt-2">
                            <?php if ($event['categoryName'] !== null): ?>
                                <span class="badge bg-primary me-1"><?php echo htmlspecialchars($event['categoryName'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($event['typeName'] !== null): ?>
                                <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($event['typeName'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($event['seriesName'] !== null): ?>
                                <span class="badge bg-info me-1"><?php echo htmlspecialchars($event['seriesName'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 📖 Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav aria-label="Calendar pagination" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php
                $baseUrl = '/calendar?' . http_build_query(array_filter([
                    'category' => $filterCategory,
                    'type'     => $filterType,
                    'past'     => $showPast ? '1' : '',
                ]));
                $sep = '&';
                ?>
                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . ($page - 1), ENT_QUOTES, 'UTF-8'); ?>">&laquo;</a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i <= 3 || $i >= $totalPages - 2 || abs($i - $page) <= 1): ?>
                        <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo htmlspecialchars($baseUrl . $sep . 'page=' . $i, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php elseif ($i === 4 || $i === $totalPages - 3): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
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
