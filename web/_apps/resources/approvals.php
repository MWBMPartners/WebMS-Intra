<?php
// Path: public_html/resources/approvals.php
/**
 * Resource Booking — admin approval queue for pending bookings.
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
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$db     = App::db();
$siteId = Site::id();

$rows = [];
$stmt = $db->prepare(
    'SELECT b.bookingID, b.startAt, b.endAt, b.purpose, b.notes, b.createdAt, '
    . '       r.name AS resourceName, '
    . '       u.fullName AS bookedByName, u.emailAddress AS bookedByEmail '
    . 'FROM tblResourceBooking b '
    . 'JOIN tblResource r ON r.resourceID = b.resourceID '
    . 'JOIN tblUsers u ON u.userID = b.bookedByID '
    . "WHERE r.siteID = ? AND b.status = 'pending' "
    . 'ORDER BY b.startAt'
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

$pageTitle   = 'Booking Approvals';
$pageSection = 'resources';
$breadcrumbs = ['Dashboard' => '/', 'Resources' => '/resources', 'Approvals' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-clipboard-check me-2"></i>Booking Approvals</h1>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-success">No pending bookings — all caught up.</div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <?php foreach ($rows as $r): ?>
                <div class="border-bottom py-3">
                    <div class="row">
                        <div class="col-md-8">
                            <strong><?php echo htmlspecialchars((string) $r['resourceName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            &middot; <?php echo htmlspecialchars(date('D j M Y, H:i', strtotime((string) $r['startAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            → <?php echo htmlspecialchars(date('H:i', strtotime((string) $r['endAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            <br>
                            <span class="small text-muted">
                                Requested by <?php echo htmlspecialchars((string) $r['bookedByName'], ENT_QUOTES, 'UTF-8'); ?>
                                (<?php echo htmlspecialchars((string) $r['bookedByEmail'], ENT_QUOTES, 'UTF-8'); ?>)
                                &middot; <?php echo htmlspecialchars(date('j M', strtotime((string) $r['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php if (($r['purpose'] ?? '') !== ''): ?>
                                <p class="mb-1 mt-1"><strong>Purpose:</strong> <?php echo htmlspecialchars((string) $r['purpose'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <?php if (($r['notes'] ?? '') !== ''): ?>
                                <p class="small text-muted mb-0">Notes: <?php echo htmlspecialchars((string) $r['notes'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <form method="post" action="/resources/action" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="bookingID" value="<?php echo (int) $r['bookingID']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-success btn-sm">Approve</button>
                            </form>
                            <form method="post" action="/resources/action" class="d-inline ms-1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="bookingID" value="<?php echo (int) $r['bookingID']; ?>">
                                <input type="hidden" name="action" value="decline">
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                        data-confirm="Decline this booking?" data-confirm-destructive="true">Decline</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
