<?php
// Path: public_html/resources/resource.php
/**
 * Resource Booking — single resource with upcoming bookings calendar.
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
$id     = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /resources');
    exit();
}

$r = null;
$stmt = $db->prepare(
    'SELECT * FROM tblResource WHERE resourceID = ? AND siteID = ? AND isActive = 1 LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($r === null) {
    http_response_code(404);
    exit('Resource not found');
}

$lookahead = (int) (App::settings()['resources']['lookahead_days'] ?? 90);
$endWindow = date('Y-m-d', strtotime('+' . $lookahead . ' days'));

$bookings = [];
$stmt = $db->prepare(
    'SELECT b.bookingID, b.startAt, b.endAt, b.purpose, b.status, '
    . '       u.fullName AS bookedByName '
    . 'FROM tblResourceBooking b JOIN tblUsers u ON u.userID = b.bookedByID '
    . 'WHERE b.resourceID = ? AND b.status IN (\'pending\',\'approved\') '
    . '  AND b.startAt >= NOW() AND b.startAt <= ? '
    . 'ORDER BY b.startAt'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $id, $endWindow);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
}

$pageTitle   = (string) $r['name'];
$pageSection = 'resources';
$breadcrumbs = ['Dashboard' => '/', 'Resources' => '/resources', (string) $r['name'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-1"><?php echo htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
<p class="text-muted">
    <?php echo htmlspecialchars(ucfirst((string) $r['category']), ENT_QUOTES, 'UTF-8'); ?>
    <?php if ($r['location'] !== null): ?> &middot; <?php echo htmlspecialchars((string) $r['location'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    <?php if ($r['capacity'] !== null): ?> &middot; capacity <?php echo (int) $r['capacity']; ?><?php endif; ?>
</p>

<?php if (($r['description'] ?? '') !== ''): ?>
    <p><?php echo htmlspecialchars((string) $r['description'], ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<a href="/resources/book?id=<?php echo $id; ?>" class="btn btn-primary mb-3">
    <i class="fa-solid fa-calendar-plus me-1"></i>Book this resource
</a>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Upcoming bookings (next <?php echo $lookahead; ?> days)</h2>
        <?php if (count($bookings) === 0): ?>
            <p class="text-muted mb-0">No upcoming bookings.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($bookings as $b): ?>
                    <div class="row py-2 border-bottom small">
                        <div class="col-md-3">
                            <strong><?php echo htmlspecialchars(date('D j M, H:i', strtotime((string) $b['startAt'])), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <br>→ <?php echo htmlspecialchars(date('H:i', strtotime((string) $b['endAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="col-md-5"><?php echo htmlspecialchars((string) ($b['purpose'] ?? '(no purpose given)'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 text-muted"><?php echo htmlspecialchars((string) $b['bookedByName'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2">
                            <?php if ($b['status'] === 'pending'): ?>
                                <span class="badge bg-warning text-dark">Pending approval</span>
                            <?php else: ?>
                                <span class="badge bg-success">Approved</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
