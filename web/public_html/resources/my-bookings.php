<?php
// Path: public_html/resources/my-bookings.php
/**
 * Resource Booking — my upcoming bookings + cancel link.
 *
 * @package   Portal\Resources
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/263
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$rows = [];
$stmt = $db->prepare(
    'SELECT b.bookingID, b.startAt, b.endAt, b.purpose, b.status, '
    . '       r.name AS resourceName, r.resourceID '
    . 'FROM tblResourceBooking b JOIN tblResource r ON r.resourceID = b.resourceID '
    . 'WHERE b.bookedByID = ? AND b.endAt > NOW() AND b.status <> \'cancelled\' '
    . 'ORDER BY b.startAt'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'My Bookings';
$pageSection = 'resources';
$breadcrumbs = ['Dashboard' => '/', 'Resources' => '/resources', 'My bookings' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-calendar-check me-2"></i>My Bookings</h1>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">You have no upcoming bookings. <a href="/resources">Book a resource →</a></div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($rows as $r):
                    $statusCls = match ($r['status']) {
                        'approved' => 'success',
                        'pending'  => 'warning',
                        'declined' => 'danger',
                        default    => 'secondary',
                    };
                ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-3">
                            <a href="/resources/resource?id=<?php echo (int) $r['resourceID']; ?>" class="text-decoration-none">
                                <strong><?php echo htmlspecialchars((string) $r['resourceName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </a>
                        </div>
                        <div class="col-md-3 small">
                            <?php echo htmlspecialchars(date('D j M, H:i', strtotime((string) $r['startAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            → <?php echo htmlspecialchars(date('H:i', strtotime((string) $r['endAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="col-md-3 small text-muted"><?php echo htmlspecialchars((string) ($r['purpose'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-1"><span class="badge bg-<?php echo $statusCls; ?>"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2 text-end">
                            <form method="post" action="/resources/action" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="bookingID" value="<?php echo (int) $r['bookingID']; ?>">
                                <input type="hidden" name="action" value="cancel">
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                        data-confirm="Cancel this booking?" data-confirm-destructive="true">Cancel</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
