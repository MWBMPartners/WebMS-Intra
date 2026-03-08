<?php
// Path: public_html/calendar/event.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Single Event Detail Page 📅
 * -----------------------------------------------------------------------------
 * Displays full details for a single event, including description, location,
 * people, links, materials, and series info. Accessed via /calendar/event?slug=X
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

// 🛡️ Ensure session for nav state
Auth::ensureSession();

// 🔍 Get event by slug
$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    Router::renderError(404);
    return;
}

// 🌐 Multi-site scope
$siteId = Site::id();

// 📋 Fetch event
$event = null;
$stmt = $mysqli->prepare(
    'SELECT e.*, c.categoryName, t.typeName, s.seriesName, s.seriesSlug '
    . 'FROM tblEvents e '
    . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
    . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
    . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
    . 'WHERE e.eventSlug = ? AND e.isDeleted = 0 AND e.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('si', $slug, $siteId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($event === null) {
    Router::renderError(404);
    return;
}

// 🛡️ Check visibility (non-public events require login)
if (($event['isPublic'] === '0' || (int) $event['isPublic'] === 0) && Auth::check() === false) {
    Auth::requireLogin();
}

// 📌 Page metadata
$pageTitle   = $event['eventName'];
$pageSection = 'calendar';
$breadcrumbs = ['Dashboard' => '/', 'Calendar' => '/calendar', $event['eventName'] => ''];

// 📋 Fetch event people
$people = [];
$stmt = $mysqli->prepare(
    'SELECT ep.*, u.fullName FROM tblEventPeople ep '
    . 'LEFT JOIN tblUsers u ON u.userID = ep.userID '
    . 'WHERE ep.eventID = ? ORDER BY ep.sortOrder, ep.role'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $event['eventID']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $people[] = $r;
    }
    $stmt->close();
}

// 📋 Fetch event links
$links = [];
$stmt = $mysqli->prepare('SELECT * FROM tblEventLinks WHERE eventID = ? ORDER BY sortOrder');
if ($stmt !== false) {
    $stmt->bind_param('i', $event['eventID']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $links[] = $r;
    }
    $stmt->close();
}

// 📋 Fetch event materials
$materials = [];
$stmt = $mysqli->prepare('SELECT * FROM tblEventMaterials WHERE eventID = ? ORDER BY sortOrder');
if ($stmt !== false) {
    $stmt->bind_param('i', $event['eventID']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $materials[] = $r;
    }
    $stmt->close();
}

// 📋 Fetch event themes
$themes = [];
$stmt = $mysqli->prepare(
    'SELECT th.themeName, th.color FROM tblEventThemeMap tm '
    . 'JOIN tblEventThemes th ON th.themeID = tm.themeID AND th.siteID = ? '
    . 'WHERE tm.eventID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $event['eventID']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $themes[] = $r;
    }
    $stmt->close();
}

$startDt = new DateTime($event['startDateTime']);
$endDt   = $event['endDateTime'] !== null ? new DateTime($event['endDateTime']) : null;

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📅 Event Detail -->
<article class="mb-5">
    <!-- 🖼️ Hero image -->
    <?php if ($event['heroImage'] !== null && $event['heroImage'] !== ''): ?>
        <div class="mb-4">
            <img src="/assets/uploads/calendar/<?php echo htmlspecialchars($event['heroImage'], ENT_QUOTES, 'UTF-8'); ?>"
                 alt="<?php echo htmlspecialchars($event['eventName'], ENT_QUOTES, 'UTF-8'); ?>"
                 class="img-fluid rounded w-100" style="max-height:400px;object-fit:cover;">
        </div>
    <?php endif; ?>

    <!-- 📝 Header -->
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="mb-2"><?php echo htmlspecialchars($event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <!-- 🏷️ Badges -->
            <div class="d-flex flex-wrap gap-1 mb-2">
                <?php if ($event['status'] === 'cancelled'): ?>
                    <span class="badge bg-danger">Cancelled</span>
                <?php elseif ($event['status'] === 'postponed'): ?>
                    <span class="badge bg-warning text-dark">Postponed</span>
                <?php endif; ?>
                <?php if ($event['isFeatured'] === '1'): ?>
                    <span class="badge bg-warning text-dark"><i class="fa-solid fa-star me-1"></i>Featured</span>
                <?php endif; ?>
                <?php if ($event['categoryName'] !== null): ?>
                    <span class="badge bg-primary"><?php echo htmlspecialchars($event['categoryName'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if ($event['typeName'] !== null): ?>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($event['typeName'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php foreach ($themes as $theme): ?>
                    <span class="badge" style="background-color:<?php echo htmlspecialchars($theme['color'] ?? '#6c757d', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($theme['themeName'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (App::isAdmin() === true): ?>
            <a href="/calendar/manage?edit=<?php echo (int) $event['eventID']; ?>" class="btn btn-outline-primary btn-sm">
                <i class="fa-solid fa-pen me-1"></i>Edit
            </a>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <!-- 📋 Main content -->
        <div class="col-12 col-lg-8">
            <!-- 📝 Description -->
            <?php if ($event['description'] !== null && $event['description'] !== ''): ?>
                <div class="mb-4">
                    <p><?php echo nl2br(htmlspecialchars($event['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                </div>
            <?php endif; ?>

            <!-- 👤 People -->
            <?php if (count($people) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-users me-2"></i>People</h5></div>
                    <div class="card-body">
                        <div class="portal-data-list">
                            <?php foreach ($people as $person): ?>
                                <div class="portal-data-row">
                                    <div class="col-6">
                                        <strong>
                                            <?php echo htmlspecialchars(
                                                $person['fullName'] ?? $person['externalName'] ?? 'Unknown',
                                                ENT_QUOTES, 'UTF-8'
                                            ); ?>
                                        </strong>
                                        <?php if ($person['isPrimary'] === '1'): ?>
                                            <span class="badge bg-warning text-dark ms-1">Primary</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-6 text-end">
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($person['role']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 📎 Materials -->
            <?php if (count($materials) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-file-arrow-down me-2"></i>Materials</h5></div>
                    <div class="card-body">
                        <?php foreach ($materials as $mat): ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="fa-solid fa-file text-muted"></i>
                                <a href="/assets/uploads/calendar/materials/<?php echo htmlspecialchars($mat['filePath'], ENT_QUOTES, 'UTF-8'); ?>"
                                   target="_blank" class="text-decoration-none">
                                    <?php echo htmlspecialchars($mat['fileName'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php if ($mat['fileSize'] !== null): ?>
                                    <small class="text-muted">(<?php echo round((int) $mat['fileSize'] / 1024); ?> KB)</small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 📌 Sidebar -->
        <div class="col-12 col-lg-4">
            <!-- 📅 Date & Time card -->
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fa-regular fa-clock me-2"></i>When</h5></div>
                <div class="card-body">
                    <p class="mb-1">
                        <i class="fa-regular fa-calendar me-1 text-primary"></i>
                        <strong><?php echo htmlspecialchars(\Portal\Core\I18n::formatDate($startDt->format('Y-m-d H:i:s'), 'long'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </p>
                    <?php if ($event['isAllDay'] !== '1' && (int) $event['isAllDay'] !== 1): ?>
                        <p class="mb-1">
                            <i class="fa-regular fa-clock me-1 text-primary"></i>
                            <?php echo htmlspecialchars($startDt->format('g:i A'), ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($endDt !== null): ?>
                                &ndash; <?php echo htmlspecialchars($endDt->format('g:i A'), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <p class="mb-1"><span class="badge bg-light text-dark">All Day Event</span></p>
                    <?php endif; ?>
                    <p class="mb-0 small text-muted">
                        Timezone: <?php echo htmlspecialchars($event['timezone'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <!-- 📅 Add to calendar link -->
                    <a href="/calendar/export?id=<?php echo (int) $event['eventID']; ?>" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="fa-solid fa-calendar-plus me-1"></i> Add to Calendar
                    </a>
                </div>
            </div>

            <!-- 📍 Location card -->
            <?php if ($event['locationName'] !== null && $event['locationName'] !== ''): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-location-dot me-2"></i>Where</h5></div>
                    <div class="card-body">
                        <p class="mb-1"><strong><?php echo htmlspecialchars($event['locationName'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                        <?php if ($event['locationAddress'] !== null && $event['locationAddress'] !== ''): ?>
                            <p class="mb-1 small"><?php echo nl2br(htmlspecialchars($event['locationAddress'], ENT_QUOTES, 'UTF-8')); ?></p>
                        <?php endif; ?>
                        <?php if ($event['locationWebURL'] !== null && $event['locationWebURL'] !== ''): ?>
                            <p class="mb-1 small">
                                <i class="fa-solid fa-globe me-1"></i>
                                <a href="<?php echo htmlspecialchars($event['locationWebURL'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Website</a>
                            </p>
                        <?php endif; ?>
                        <?php if ($event['locationPhone'] !== null && $event['locationPhone'] !== ''): ?>
                            <p class="mb-1 small">
                                <i class="fa-solid fa-phone me-1"></i>
                                <?php echo htmlspecialchars($event['locationPhone'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 🏢 Organisation -->
            <?php if ($event['hostOrgName'] !== null && $event['hostOrgName'] !== ''): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-building me-2"></i>Hosted By</h5></div>
                    <div class="card-body">
                        <p class="mb-0"><strong><?php echo htmlspecialchars($event['hostOrgName'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                        <?php
                        $partners = json_decode($event['partnerOrgs'] ?? '[]', true);
                        if (is_array($partners) === true && count($partners) > 0):
                        ?>
                            <p class="mt-2 mb-0 small text-muted">Partners:
                                <?php echo htmlspecialchars(implode(', ', $partners), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 🔗 Links -->
            <?php if (count($links) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-link me-2"></i>Links</h5></div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($links as $link): ?>
                            <a href="<?php echo htmlspecialchars($link['linkURL'], ENT_QUOTES, 'UTF-8'); ?>"
                               class="list-group-item list-group-item-action" target="_blank" rel="noopener">
                                <i class="fa-solid fa-arrow-up-right-from-square me-1 text-muted"></i>
                                <?php echo htmlspecialchars($link['linkLabel'] ?? $link['linkURL'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="badge bg-light text-dark float-end"><?php echo htmlspecialchars($link['linkType'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 🔄 Series info -->
            <?php if ($event['seriesName'] !== null): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-layer-group me-2"></i>Series</h5></div>
                    <div class="card-body">
                        <p class="mb-0">Part of: <strong><?php echo htmlspecialchars($event['seriesName'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</article>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
