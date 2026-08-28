<?php
// Path: _apps/venues/usage-types.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Usage Types & Effective-Dated Windows ⏰
 * -----------------------------------------------------------------------------
 * The configurable "HOURS" vocabulary for ONE venue (`?venue=N`, required,
 * 404 unless it belongs to this site — 01-data-model §2). Each type carries
 * a `usageKind` (hire/closed/unavailable, locked once a booking references
 * the type — history is never silently reclassified) and a history of
 * effective-dated default time windows resolved by
 * `Venues::resolveWindow()`: the row with the greatest `effectiveFrom` at or
 * before a given date wins. `Venues::` has no `listWindows()` helper, so the
 * window-history read below is a small site-scoped prepared SELECT directly
 * against `tblVenueUsageTypeWindows` — read-only, consistent with every
 * other agent's "future bookings count" style ad-hoc read.
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

$venueId = (int) ($_GET['venue'] ?? $_POST['venue'] ?? 0);
$venue   = $venueId > 0 ? Venues::getVenue($venueId, $siteId) : null;
if ($venue === null) {
    Router::renderError(404);
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 CSRF FIRST — before any side-effect.
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /venues/usage-types?venue=' . $venueId);
        exit();
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save-type') {
        $usageTypeId = (int) ($_POST['usageTypeID'] ?? 0);
        $result = Venues::saveUsageType($siteId, $venueId, $usageTypeId, [
            'typeName'  => (string) ($_POST['typeName'] ?? ''),
            'usageKind' => (string) ($_POST['usageKind'] ?? 'hire'),
            'sortOrder' => (string) ($_POST['sortOrder'] ?? '0'),
        ], $userId);
        $_SESSION['flash_msg']  = $result['id'] > 0 ? 'Usage type saved.' : ('Could not save: ' . (string) ($result['error'] ?? 'unknown error'));
        $_SESSION['flash_type'] = $result['id'] > 0 ? 'success' : 'danger';
    } elseif ($action === 'toggle-type') {
        $usageTypeId = (int) ($_POST['usageTypeID'] ?? 0);
        Venues::toggleUsageTypeActive($usageTypeId, $siteId, $userId);
        $_SESSION['flash_msg']  = 'Usage type updated.';
        $_SESSION['flash_type'] = 'success';
    } elseif ($action === 'add-window') {
        $usageTypeId = (int) ($_POST['usageTypeID'] ?? 0);
        $result = Venues::saveWindow($siteId, $usageTypeId, [
            'effectiveFrom'    => (string) ($_POST['effectiveFrom'] ?? ''),
            'defaultStartTime' => (string) ($_POST['defaultStartTime'] ?? ''),
            'defaultEndTime'   => (string) ($_POST['defaultEndTime'] ?? ''),
            'note'             => (string) ($_POST['note'] ?? ''),
        ], $userId);
        $_SESSION['flash_msg']  = $result['id'] > 0 ? 'Window added.' : ('Could not add window: ' . (string) ($result['error'] ?? 'unknown error'));
        $_SESSION['flash_type'] = $result['id'] > 0 ? 'success' : 'danger';
    } elseif ($action === 'delete-window') {
        $windowId = (int) ($_POST['windowID'] ?? 0);
        $ok = Venues::deleteWindow($windowId, $siteId, $userId);
        $_SESSION['flash_msg']  = $ok === true ? 'Window removed.' : 'Could not remove window.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
    } elseif ($action === 'reapply') {
        $usageTypeId = (int) ($_POST['usageTypeID'] ?? 0);
        $fromDate    = (string) ($_POST['fromDate'] ?? '');
        $touched     = Venues::reapplyWindowDefaults($siteId, $usageTypeId, $fromDate, $userId);
        $_SESSION['flash_msg']  = $touched . ' future booking(s) updated with the current default window.';
        $_SESSION['flash_type'] = 'success';
    }

    header('Location: /venues/usage-types?venue=' . $venueId);
    exit();
}

// 📋 List + per-type decoration: window history, booking count, in-use lock.
$types = Venues::listUsageTypes($venueId, $siteId, false);
foreach ($types as &$type) {
    $usageTypeId = (int) $type['usageTypeID'];

    $windows = [];
    $wStmt = $db->prepare(
        'SELECT windowID, effectiveFrom, defaultStartTime, defaultEndTime, note '
        . 'FROM tblVenueUsageTypeWindows WHERE usageTypeID = ? AND siteID = ? ORDER BY effectiveFrom DESC'
    );
    if ($wStmt !== false) {
        $wStmt->bind_param('ii', $usageTypeId, $siteId);
        $wStmt->execute();
        $wRes = $wStmt->get_result();
        while ($row = $wRes->fetch_assoc()) {
            $windows[] = $row;
        }
        $wStmt->close();
    }
    $type['windows'] = $windows;

    $cStmt = $db->prepare('SELECT COUNT(*) AS c FROM tblVenueBookings WHERE usageTypeID = ? AND siteID = ? AND isDeleted = 0');
    $bookingCount = 0;
    if ($cStmt !== false) {
        $cStmt->bind_param('ii', $usageTypeId, $siteId);
        $cStmt->execute();
        $bookingCount = (int) ($cStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $cStmt->close();
    }
    $type['bookingCount'] = $bookingCount;
    $type['inUse']        = $bookingCount > 0;
}
unset($type);

$editId  = (int) ($_GET['edit'] ?? 0);
$editType = null;
foreach ($types as $t) {
    if ((int) $t['usageTypeID'] === $editId) {
        $editType = $t;
        break;
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$kindBadge = ['hire' => 'primary', 'closed' => 'secondary', 'unavailable' => 'danger'];

$pageTitle   = 'Usage Types — ' . (string) $venue['venueName'];
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venues' => '/venues', (string) $venue['venueName'] => '/venues/venue?id=' . $venueId, 'Usage types' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-clock me-2"></i>Usage Types — <?php echo htmlspecialchars((string) $venue['venueName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <a href="/venues/manage" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to venues</a>
</div>
<div class="alert alert-secondary small">
    <i class="fa-solid fa-circle-info me-1"></i>A new window applies to bookings dated on/after its start date; existing bookings keep their saved times unless you <strong>Re-apply</strong>.
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5"><?php echo $editType !== null ? 'Edit usage type' : 'Add usage type'; ?></h2>
        <form method="post" action="/venues/usage-types?venue=<?php echo $venueId; ?>" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save-type">
            <input type="hidden" name="venue" value="<?php echo $venueId; ?>">
            <input type="hidden" name="usageTypeID" value="<?php echo $editType !== null ? (int) $editType['usageTypeID'] : 0; ?>">
            <div class="col-md-4">
                <label class="form-label small" for="typeName">Name *</label>
                <input type="text" class="form-control form-control-sm" id="typeName" name="typeName" required maxlength="100"
                       value="<?php echo $editType !== null ? htmlspecialchars((string) $editType['typeName'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="usageKind">Kind</label>
                <?php $kindLocked = $editType !== null && $editType['inUse'] === true; ?>
                <select class="form-select form-select-sm" id="usageKind" name="usageKind" <?php echo $kindLocked === true ? 'disabled title="Locked — this type has bookings; create a new type instead of reclassifying it."' : ''; ?>>
                    <?php foreach (Venues::USAGE_KINDS as $kind): ?>
                        <option value="<?php echo htmlspecialchars($kind, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo ($editType !== null ? (string) $editType['usageKind'] : 'hire') === $kind ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($kind), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($kindLocked === true): ?>
                    <input type="hidden" name="usageKind" value="<?php echo htmlspecialchars((string) $editType['usageKind'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-text">Locked — bookings already reference this type.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="sortOrder">Sort order</label>
                <input type="number" class="form-control form-control-sm" id="sortOrder" name="sortOrder"
                       value="<?php echo $editType !== null ? (int) $editType['sortOrder'] : 0; ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fa-solid fa-<?php echo $editType !== null ? 'check' : 'plus'; ?> me-1"></i><?php echo $editType !== null ? 'Update' : 'Add'; ?>
                </button>
                <?php if ($editType !== null): ?>
                    <a href="/venues/usage-types?venue=<?php echo $venueId; ?>" class="btn btn-outline-secondary btn-sm w-100 mt-1">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (count($types) === 0): ?>
    <div class="alert alert-info">No usage types yet.</div>
<?php else: ?>
    <div class="portal-data-list mb-3">
        <div class="portal-data-header">
            <div class="col-3">Type</div>
            <div class="col-2">Kind</div>
            <div class="col-3">Current window</div>
            <div class="col-1">Bookings</div>
            <div class="col-3 text-end">Actions</div>
        </div>
        <?php foreach ($types as $type): ?>
            <?php
            $resolved = $type['resolvedWindow'] ?? null;
            $windowText = ($resolved !== null && $resolved['start'] !== null && $resolved['end'] !== null)
                ? substr((string) $resolved['start'], 0, 5) . '–' . substr((string) $resolved['end'], 0, 5)
                : '—';
            ?>
            <div class="portal-data-row align-items-center">
                <div class="col-3">
                    <strong><?php echo htmlspecialchars((string) $type['typeName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ((int) $type['isActive'] === 0): ?><span class="badge bg-secondary ms-1">inactive</span><?php endif; ?>
                </div>
                <div class="col-2"><span class="badge bg-<?php echo $kindBadge[(string) $type['usageKind']] ?? 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst((string) $type['usageKind']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="col-3 small text-muted"><?php echo htmlspecialchars($windowText, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-1"><span class="badge bg-light text-dark border"><?php echo (int) $type['bookingCount']; ?></span></div>
                <div class="col-3 text-end">
                    <a href="/venues/usage-types?venue=<?php echo $venueId; ?>&edit=<?php echo (int) $type['usageTypeID']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                    <form method="post" action="/venues/usage-types?venue=<?php echo $venueId; ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle-type">
                        <input type="hidden" name="venue" value="<?php echo $venueId; ?>">
                        <input type="hidden" name="usageTypeID" value="<?php echo (int) $type['usageTypeID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?php echo (int) $type['isActive'] === 1 ? 'warning' : 'success'; ?>"
                                title="<?php echo (int) $type['isActive'] === 1 ? 'Deactivate' : 'Activate'; ?>">
                            <i class="fa-solid fa-toggle-<?php echo (int) $type['isActive'] === 1 ? 'on' : 'off'; ?>"></i>
                        </button>
                    </form>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#windows-<?php echo (int) $type['usageTypeID']; ?>" title="Window history">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </button>
                </div>
                <div class="col-12 collapse mt-2" id="windows-<?php echo (int) $type['usageTypeID']; ?>">
                    <div class="border rounded p-2">
                        <h3 class="h6">Window history</h3>
                        <?php if (count((array) $type['windows']) === 0): ?>
                            <p class="small text-muted mb-2">No windows recorded.</p>
                        <?php else: ?>
                            <div class="portal-data-list mb-2">
                                <?php foreach ((array) $type['windows'] as $window): ?>
                                    <div class="portal-data-row">
                                        <div class="col-3"><?php echo htmlspecialchars((string) $window['effectiveFrom'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="col-3 small">
                                            <?php echo $window['defaultStartTime'] !== null
                                                ? htmlspecialchars(substr((string) $window['defaultStartTime'], 0, 5) . '–' . substr((string) $window['defaultEndTime'], 0, 5), ENT_QUOTES, 'UTF-8')
                                                : '<span class="text-muted">no default</span>'; ?>
                                        </div>
                                        <div class="col-4 small text-muted"><?php echo htmlspecialchars((string) ($window['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="col-2 text-end">
                                            <form method="post" action="/venues/usage-types?venue=<?php echo $venueId; ?>" class="d-inline" data-confirm="Remove this window entry?" data-confirm-destructive="true">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="delete-window">
                                                <input type="hidden" name="venue" value="<?php echo $venueId; ?>">
                                                <input type="hidden" name="windowID" value="<?php echo (int) $window['windowID']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="row g-2 align-items-end">
                            <div class="col-auto">
                                <form method="post" action="/venues/usage-types?venue=<?php echo $venueId; ?>" class="row g-1 align-items-end">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="add-window">
                                    <input type="hidden" name="venue" value="<?php echo $venueId; ?>">
                                    <input type="hidden" name="usageTypeID" value="<?php echo (int) $type['usageTypeID']; ?>">
                                    <div class="col-auto">
                                        <label class="form-label small">Effective from</label>
                                        <input type="date" class="form-control form-control-sm" name="effectiveFrom" required>
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small">Start</label>
                                        <input type="time" class="form-control form-control-sm" name="defaultStartTime">
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small">End</label>
                                        <input type="time" class="form-control form-control-sm" name="defaultEndTime">
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small">Note</label>
                                        <input type="text" class="form-control form-control-sm" name="note" maxlength="255">
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Add window</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <?php if ((int) $type['bookingCount'] > 0): ?>
                            <form method="post" action="/venues/usage-types?venue=<?php echo $venueId; ?>" class="row g-1 align-items-end mt-2"
                                  data-confirm="Re-apply the current default window to every future booking of this type that hasn't had its times manually overridden?">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="reapply">
                                <input type="hidden" name="venue" value="<?php echo $venueId; ?>">
                                <input type="hidden" name="usageTypeID" value="<?php echo (int) $type['usageTypeID']; ?>">
                                <div class="col-auto">
                                    <label class="form-label small">Re-apply from date</label>
                                    <input type="date" class="form-control form-control-sm" name="fromDate" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">Re-apply changed defaults</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
