<?php
// Path: _apps/venues/statuses.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Booking Statuses 🚦
 * -----------------------------------------------------------------------------
 * The configurable booking-status vocabulary, per SITE (the leadership /
 * landlord approval process is organisational, not per-building —
 * 01-data-model §2). `countsAsConfirmed` is THE flag (decision #4): it is
 * what turns a booking green on the calendar and silences the "is it
 * booked?" warning. `Venues::seedStatuses()` runs on every GET here (Q8's
 * second entry point, alongside `index.php`) — idempotent, cheap
 * short-circuit on the hot "already seeded" path.
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — admins or the venue_manager role only.
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$db     = App::db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 CSRF FIRST — before any side-effect.
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /venues/statuses');
        exit();
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $statusId = (int) ($_POST['statusID'] ?? 0);
        // 🎨 "Use category default" clears the colour — an empty posted
        // value already resolves to NULL inside Venues::saveStatus(), which
        // is exactly the "derive from statusCategory" behaviour.
        $useDefault = isset($_POST['useDefaultColor']) === true;
        $result = Venues::saveStatus($siteId, $statusId, [
            'statusName'        => (string) ($_POST['statusName'] ?? ''),
            'statusCategory'    => (string) ($_POST['statusCategory'] ?? 'proposed'),
            'countsAsConfirmed' => isset($_POST['countsAsConfirmed']) ? '1' : '0',
            'isAvailable'       => isset($_POST['isAvailable']) ? '1' : '0',
            'color'             => $useDefault === true ? '' : (string) ($_POST['color'] ?? ''),
            'sortOrder'         => (string) ($_POST['sortOrder'] ?? '0'),
        ], $userId);
        $_SESSION['flash_msg']  = $result['id'] > 0 ? 'Status saved.' : ('Could not save: ' . (string) ($result['error'] ?? 'unknown error'));
        $_SESSION['flash_type'] = $result['id'] > 0 ? 'success' : 'danger';
    } elseif ($action === 'toggle') {
        $statusId = (int) ($_POST['statusID'] ?? 0);
        Venues::toggleStatusActive($statusId, $siteId, $userId);
        $_SESSION['flash_msg']  = 'Status updated.';
        $_SESSION['flash_type'] = 'success';
    } elseif ($action === 'restore') {
        $touched = Venues::restoreDefaultStatuses($siteId, $userId);
        $_SESSION['flash_msg']  = $touched . ' default status(es) restored.';
        $_SESSION['flash_type'] = 'success';
    }

    header('Location: /venues/statuses');
    exit();
}

// 🌱 Idempotent per-site seed — cheap short-circuit once seeded (Q8).
Venues::seedStatuses($siteId);

$statuses = Venues::listStatuses($siteId, false);
foreach ($statuses as &$status) {
    $statusId = (int) $status['statusID'];
    $cStmt = $db->prepare('SELECT COUNT(*) AS c FROM tblVenueBookings WHERE statusID = ? AND siteID = ? AND isDeleted = 0');
    $count = 0;
    if ($cStmt !== false) {
        $cStmt->bind_param('ii', $statusId, $siteId);
        $cStmt->execute();
        $count = (int) ($cStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $cStmt->close();
    }
    $status['bookingCount'] = $count;
}
unset($status);

$editId     = (int) ($_GET['edit'] ?? 0);
$editStatus = null;
foreach ($statuses as $s) {
    if ((int) $s['statusID'] === $editId) {
        $editStatus = $s;
        break;
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$categoryBadge = [
    'proposed' => 'warning', 'agreed' => 'success', 'rejected' => 'danger',
    'standing' => 'success', 'unavailable' => 'danger',
];

$pageTitle   = 'Booking Statuses';
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venues' => '/venues', 'Statuses' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-flag me-2"></i>Booking Statuses</h1>
    <a href="/venues/manage" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to venues</a>
</div>
<div class="alert alert-secondary small">
    <i class="fa-solid fa-circle-info me-1"></i><strong>"Counts as confirmed"</strong> is what turns a booking green on the calendar and silences the "is it booked?" warning — only tick it for statuses that mean the hire is actually secured.
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5"><?php echo $editStatus !== null ? 'Edit status' : 'Add status'; ?></h2>
        <form method="post" action="/venues/statuses" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="statusID" value="<?php echo $editStatus !== null ? (int) $editStatus['statusID'] : 0; ?>">
            <div class="col-md-3">
                <label class="form-label small" for="statusName">Name *</label>
                <input type="text" class="form-control form-control-sm" id="statusName" name="statusName" required maxlength="150"
                       value="<?php echo $editStatus !== null ? htmlspecialchars((string) $editStatus['statusName'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="statusCategory">Category</label>
                <select class="form-select form-select-sm" id="statusCategory" name="statusCategory">
                    <?php foreach (Venues::STATUS_CATEGORIES as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo ($editStatus !== null ? (string) $editStatus['statusCategory'] : 'proposed') === $cat ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($cat), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 form-check mt-4">
                <input type="checkbox" class="form-check-input" id="countsAsConfirmed" name="countsAsConfirmed" value="1"
                       <?php echo ($editStatus !== null && (int) $editStatus['countsAsConfirmed'] === 1) ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="countsAsConfirmed">Counts as confirmed</label>
            </div>
            <div class="col-md-2 form-check mt-4">
                <input type="checkbox" class="form-check-input" id="isAvailable" name="isAvailable" value="1"
                       <?php echo ($editStatus === null || (int) $editStatus['isAvailable'] === 1) ? 'checked' : ''; ?>>
                <label class="form-check-label small" for="isAvailable">Available / usable</label>
            </div>
            <div class="col-md-1">
                <label class="form-label small" for="color">Colour</label>
                <input type="color" class="form-control form-control-sm form-control-color" id="color" name="color"
                       value="<?php echo htmlspecialchars(($editStatus !== null && $editStatus['color'] !== null) ? (string) $editStatus['color'] : '#6c757d', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-2 form-check mt-4">
                <input type="checkbox" class="form-check-input" id="useDefaultColor" name="useDefaultColor" value="1">
                <label class="form-check-label small" for="useDefaultColor">Use category default</label>
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="sortOrder">Sort order</label>
                <input type="number" class="form-control form-control-sm" id="sortOrder" name="sortOrder"
                       value="<?php echo $editStatus !== null ? (int) $editStatus['sortOrder'] : 0; ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fa-solid fa-<?php echo $editStatus !== null ? 'check' : 'plus'; ?> me-1"></i><?php echo $editStatus !== null ? 'Update' : 'Add'; ?>
                </button>
                <?php if ($editStatus !== null): ?>
                    <a href="/venues/statuses" class="btn btn-outline-secondary btn-sm w-100 mt-1">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (count($statuses) === 0): ?>
    <div class="alert alert-info">No statuses yet.</div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-3">Status</div>
            <div class="col-2">Category</div>
            <div class="col-2">Confirmed / Available</div>
            <div class="col-1">Bookings</div>
            <div class="col-4 text-end">Actions</div>
        </div>
        <?php foreach ($statuses as $status): ?>
            <div class="portal-data-row align-items-center">
                <div class="col-3">
                    <span class="badge" style="background-color: <?php echo htmlspecialchars(Venues::statusColor($status), ENT_QUOTES, 'UTF-8'); ?>;">&nbsp;&nbsp;</span>
                    <strong class="ms-1"><?php echo htmlspecialchars((string) $status['statusName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ((int) $status['isActive'] === 0): ?><span class="badge bg-secondary ms-1">inactive</span><?php endif; ?>
                </div>
                <div class="col-2"><span class="badge bg-<?php echo $categoryBadge[(string) $status['statusCategory']] ?? 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst((string) $status['statusCategory']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="col-2 small">
                    <i class="fa-solid fa-<?php echo (int) $status['countsAsConfirmed'] === 1 ? 'check text-success' : 'xmark text-muted'; ?>" title="Counts as confirmed"></i>
                    /
                    <i class="fa-solid fa-<?php echo (int) $status['isAvailable'] === 1 ? 'check text-success' : 'xmark text-muted'; ?>" title="Available"></i>
                </div>
                <div class="col-1"><span class="badge bg-light text-dark border"><?php echo (int) $status['bookingCount']; ?></span></div>
                <div class="col-4 text-end">
                    <a href="/venues/statuses?edit=<?php echo (int) $status['statusID']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                    <form method="post" action="/venues/statuses" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="statusID" value="<?php echo (int) $status['statusID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?php echo (int) $status['isActive'] === 1 ? 'warning' : 'success'; ?>"
                                title="<?php echo (int) $status['isActive'] === 1 ? 'Deactivate' : 'Activate'; ?>">
                            <i class="fa-solid fa-toggle-<?php echo (int) $status['isActive'] === 1 ? 'on' : 'off'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" action="/venues/statuses" class="mt-3" data-confirm="Restore any missing/retired default statuses (Standard Agreement, Pending Leadership Agreement, Proposed to Landlord, Agreed by Landlord, Rejected by Landlord, Rejected &ndash; building already in use)?">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="restore">
    <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-rotate-left me-1"></i>Restore default statuses</button>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
