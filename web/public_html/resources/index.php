<?php
// Path: public_html/resources/index.php
/**
 * Resource Booking — browse available resources.
 *
 * @package   Portal\Resources
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/263
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();

$rows = [];
$stmt = $db->prepare(
    'SELECT resourceID, name, description, category, capacity, location, '
    . '       requiresApproval, hourlyRatePence '
    . 'FROM tblResource WHERE siteID = ? AND isActive = 1 '
    . 'ORDER BY category, name'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$grouped = [];
foreach ($rows as $r) {
    $grouped[(string) $r['category']][] = $r;
}

$pageTitle   = 'Resource Booking';
$pageSection = 'resources';
$breadcrumbs = ['Dashboard' => '/', 'Resources' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$icons = ['room' => 'fa-door-open', 'equipment' => 'fa-screwdriver-wrench', 'vehicle' => 'fa-car', 'other' => 'fa-cube'];
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-building me-2"></i>Resource Booking</h1>
        <p class="text-secondary mb-0">Reserve rooms, equipment, and vehicles. Some resources require admin approval.</p>
    </div>
    <div>
        <a href="/resources/my-bookings" class="btn btn-outline-primary btn-sm me-1">My bookings</a>
        <?php if (App::isAdmin() === true): ?>
            <a href="/resources/approvals" class="btn btn-outline-warning btn-sm me-1">Approvals</a>
            <a href="/resources/manage" class="btn btn-primary btn-sm">Manage</a>
        <?php endif; ?>
    </div>
</div>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">
        No resources configured yet.
        <?php if (App::isAdmin() === true): ?>
            <a href="/resources/manage">Add some →</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php foreach ($grouped as $category => $items): ?>
        <h2 class="h5 mt-3 mb-2">
            <i class="fa-solid <?php echo htmlspecialchars($icons[$category] ?? 'fa-cube', ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
            <?php echo htmlspecialchars(ucfirst($category), ENT_QUOTES, 'UTF-8'); ?>
        </h2>
        <div class="row g-3 mb-3">
            <?php foreach ($items as $r): ?>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h3 class="h6 mb-1">
                                <a href="/resources/resource?id=<?php echo (int) $r['resourceID']; ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </h3>
                            <?php if ($r['location'] !== null): ?>
                                <p class="small text-muted mb-1"><i class="fa-solid fa-location-dot me-1"></i><?php echo htmlspecialchars((string) $r['location'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <?php if ($r['capacity'] !== null): ?>
                                <p class="small text-muted mb-1"><i class="fa-solid fa-users me-1"></i>Capacity: <?php echo (int) $r['capacity']; ?></p>
                            <?php endif; ?>
                            <?php if ((int) $r['requiresApproval'] === 1): ?>
                                <span class="badge bg-warning text-dark">Approval required</span>
                            <?php endif; ?>
                            <?php if ($r['hourlyRatePence'] !== null): ?>
                                <span class="badge bg-info text-dark">£<?php echo number_format((int) $r['hourlyRatePence'] / 100, 2); ?>/hr</span>
                            <?php endif; ?>
                            <?php if (($r['description'] ?? '') !== ''): ?>
                                <p class="small mt-2 mb-2"><?php echo htmlspecialchars((string) $r['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <a href="/resources/book?id=<?php echo (int) $r['resourceID']; ?>" class="btn btn-primary btn-sm">Book</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
