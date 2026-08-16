<?php
// Path: _apps/assets/maintenance.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Asset Maintenance 🔧
 * -----------------------------------------------------------------------------
 * Two views in one route, switched on whether `?assetID=` is present:
 *
 *   - `?assetID=` set   — the full maintenance/service history for ONE
 *     asset (`AssetRegister::listMaintenance()`), plus an add-entry form
 *     when the viewer holds maintenance authority for it
 *     (`AssetRegister::canManageMaintenance()`). Any logged-in viewer who
 *     can see the asset at all may read its log — same read-visible/
 *     edit-authority-gated split as item.php's Loans/Owners/Identifiers
 *     panels (see that file's header). This is the standalone equivalent
 *     of item.php's own Maintenance panel, useful as a direct link/
 *     bookmark to one asset's service history without the rest of
 *     item.php's detail view.
 *   - No `assetID`      — a SITE-WIDE "maintenance due" view via
 *     `AssetRegister::listUpcomingMaintenance()`: every entry with
 *     status='scheduled' and a nextDueDate set, across every asset on this
 *     site, soonest due first (mirrors loans.php's own site-wide register
 *     pattern). Confidential-asset visibility here mirrors loans.php's own
 *     rule exactly — `listUpcomingMaintenance()`'s `$includeConfidential`
 *     param is passed the SITE-WIDE `$canManageSiteWide` flag (admin/
 *     asset_manager), never a per-row isResponsibleFor()/
 *     canManageMaintenance() check — a non-manager sees NO due entries for
 *     a confidential asset here, even one they hold maintenance authority
 *     for; they can still see and manage that specific asset's maintenance
 *     from item.php's panel (or from this same page with `?assetID=`
 *     set), both of which DO apply the finer-grained per-asset check.
 *
 * Every add/edit/delete action posts to `maintenance-save.php`, which
 * re-derives `canManageMaintenance()` independently server-side — this
 * page choosing not to render the add-form/edit-row controls for a
 * non-authority viewer is a UX nicety, never the only thing stopping an
 * unauthorised POST from succeeding.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/399
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db      = App::db();
$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$assetId = (int) ($_GET['assetID'] ?? 0);

// 🛡️ Site-wide manager flag — used both for the confidential-asset filter
// on the site-wide view below AND as the first half of the per-asset
// privileged check (see item.php's identical ACCESS MODEL note).
$canManageSiteWide = App::isAdmin() === true || App::hasRole('asset_manager') === true;

$asset               = null;
$canManageMaintenance = false;
$maintenanceRows      = [];
$maintCandidateUsers  = [];

if ($assetId > 0) {
    // 🔎 Per-asset maintenance log.
    $asset = AssetRegister::get($assetId);
    if ($asset === null) {
        Router::renderError(404);
        return;
    }

    // 🔒 Confidential-asset gate — identical rule to item.php/loan.php's
    // own ACCESS MODEL note: a plain 404 (never 403) for a confidential
    // asset the viewer isn't privileged for, so this page can't be used
    // as an existence oracle.
    $isResponsible = $canManageSiteWide === false && AssetRegister::isResponsibleFor($assetId, $userId);
    $privileged    = $canManageSiteWide === true || $isResponsible === true;
    if ((int) $asset['isConfidential'] === 1 && $privileged === false) {
        Router::renderError(404);
        return;
    }

    $maintenanceRows      = AssetRegister::listMaintenance($assetId);
    $canManageMaintenance = AssetRegister::canManageMaintenance($assetId, $userId);

    if ($canManageMaintenance === true) {
        // 👤 Site-scoped active users for the "performed by" picker —
        // mirrors item.php's/loan.php's own counterparty-user picker query.
        $uStmt = $db->prepare(
            'SELECT u.userID, u.fullName FROM tblUsers u '
            . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
            . 'WHERE u.isActive = 1 ORDER BY u.fullName ASC'
        );
        if ($uStmt !== false) {
            $uStmt->bind_param('i', $siteId);
            $uStmt->execute();
            $uResult = $uStmt->get_result();
            while ($row = $uResult->fetch_assoc()) {
                $maintCandidateUsers[] = $row;
            }
            $uStmt->close();
        }
    }
} else {
    // 🗓️ Site-wide "maintenance due" view — see file header.
    $maintenanceRows = AssetRegister::listUpcomingMaintenance($siteId, $canManageSiteWide);
}

$csrf = Auth::csrfToken();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$maintTypeIcon = [
    'service'     => 'fa-screwdriver-wrench',
    'repair'      => 'fa-wrench',
    'inspection'  => 'fa-magnifying-glass',
    'calibration' => 'fa-sliders',
    'upgrade'     => 'fa-arrow-up',
    'other'       => 'fa-clipboard-list',
];
$maintStatusBadge = ['scheduled' => 'info', 'completed' => 'success', 'cancelled' => 'secondary'];

$pageTitle   = $asset !== null ? 'Maintenance — ' . (string) $asset['name'] : 'Maintenance Due';
$pageSection = 'assets';
$breadcrumbs = $asset !== null
    ? ['Dashboard' => '/', 'Assets' => '/assets', (string) $asset['name'] => '/assets/item?id=' . $assetId, 'Maintenance' => '']
    : ['Dashboard' => '/', 'Assets' => '/assets', 'Maintenance Due' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($asset !== null): ?>
    <!-- ============================================================ -->
    <!-- 🔎 Per-asset maintenance log                                  -->
    <!-- ============================================================ -->
    <h1 class="mb-1"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Maintenance</h1>
    <p class="text-muted mb-4">
        For <a href="/assets/item?id=<?php echo $assetId; ?>"><?php echo htmlspecialchars((string) $asset['name'], ENT_QUOTES, 'UTF-8'); ?></a>
    </p>

    <div class="card mb-3">
        <div class="card-body">
            <?php if (count($maintenanceRows) === 0): ?>
                <p class="text-muted">No maintenance recorded yet for this asset.</p>
            <?php else: ?>
                <div class="portal-data-list mb-3">
                    <?php foreach ($maintenanceRows as $m): ?>
                        <?php
                        $mStatus     = (string) $m['status'];
                        $mIsOverdue  = (bool) ($m['isOverdue'] ?? false);
                        $mIsUpcoming = (bool) ($m['isUpcoming'] ?? false);
                        ?>
                        <div class="portal-data-row align-items-start <?php echo $mIsOverdue === true ? 'bg-danger-subtle' : ''; ?>">
                            <div class="col-6 col-md-3">
                                <i class="fa-solid <?php echo htmlspecialchars($maintTypeIcon[(string) $m['maintType']] ?? 'fa-clipboard-list', ENT_QUOTES, 'UTF-8'); ?> me-2 text-muted"></i>
                                <?php echo htmlspecialchars((string) $m['title'], ENT_QUOTES, 'UTF-8'); ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars(ucwords((string) $m['maintType']), ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                            <div class="col-6 col-md-3">
                                <?php echo $m['performedByDisplay'] !== null ? htmlspecialchars((string) $m['performedByDisplay'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">&mdash;</span>'; ?>
                                <?php if ($m['performedAt'] !== null): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars((string) $m['performedAt'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-3 col-md-2">
                                <?php echo $m['costPence'] !== null
                                    ? htmlspecialchars((string) $asset['currency'], ENT_QUOTES, 'UTF-8') . ' ' . number_format(((int) $m['costPence']) / 100, 2)
                                    : '<span class="text-muted">&mdash;</span>'; ?>
                            </div>
                            <div class="col-3 col-md-2">
                                <span class="badge bg-<?php echo htmlspecialchars($maintStatusBadge[$mStatus] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(ucwords($mStatus), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php if ($m['nextDueDate'] !== null): ?>
                                    <br><small class="text-muted">Due <?php echo htmlspecialchars((string) $m['nextDueDate'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php if ($mIsOverdue === true): ?>
                                        <br><span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Overdue</span>
                                    <?php elseif ($mIsUpcoming === true): ?>
                                        <br><span class="badge bg-info">Upcoming</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($canManageMaintenance === true): ?>
                            <div class="col-12 col-md-2 text-md-end">
                                <form method="post" action="/assets/maintenance-save" class="d-inline"
                                      data-confirm="Remove this maintenance entry?" data-confirm-destructive="true">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                    <input type="hidden" name="maintID" value="<?php echo (int) $m['maintID']; ?>">
                                    <input type="hidden" name="returnTo" value="maintenance">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($canManageMaintenance === true): ?>
                <hr>
                <h2 class="h6">Add a maintenance entry</h2>
                <form method="post" action="/assets/maintenance-save" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                    <input type="hidden" name="returnTo" value="maintenance">
                    <div class="col-md-3">
                        <label class="form-label small" for="maintType">Type</label>
                        <select class="form-select form-select-sm" id="maintType" name="maintType">
                            <?php foreach (AssetRegister::MAINTENANCE_TYPES as $mt): ?>
                                <option value="<?php echo htmlspecialchars($mt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords($mt), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small" for="title">Title</label>
                        <input type="text" class="form-control form-control-sm" id="title" name="title" required maxlength="255" placeholder="e.g. Annual PAT test">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="status">Status</label>
                        <select class="form-select form-select-sm" id="status" name="status">
                            <?php foreach (AssetRegister::MAINTENANCE_STATUSES as $ms): ?>
                                <option value="<?php echo htmlspecialchars($ms, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $ms === 'completed' ? ' selected' : ''; ?>><?php echo htmlspecialchars(ucwords($ms), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small" for="details">Details <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control form-control-sm" id="details" name="details" rows="2"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="performedByUserID">Performed by (portal user)</label>
                        <select class="form-select form-select-sm" id="performedByUserID" name="performedByUserID">
                            <option value="0">— Use free text instead —</option>
                            <?php foreach ($maintCandidateUsers as $mu): ?>
                                <option value="<?php echo (int) $mu['userID']; ?>"><?php echo htmlspecialchars((string) $mu['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="performedByName">…or free text <span class="text-muted">(external contractor)</span></label>
                        <input type="text" class="form-control form-control-sm" id="performedByName" name="performedByName" maxlength="255">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="costPounds">Cost (&pound;) <span class="text-muted">(optional)</span></label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="costPounds" name="costPounds" placeholder="0.00">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="performedAt">Performed on <span class="text-muted">(optional)</span></label>
                        <input type="date" class="form-control form-control-sm" id="performedAt" name="performedAt">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="nextDueDate">Next due <span class="text-muted">(optional)</span></label>
                        <input type="date" class="form-control form-control-sm" id="nextDueDate" name="nextDueDate">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-1"></i>Add entry</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <p><a href="/assets/item?id=<?php echo $assetId; ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to asset</a></p>

<?php else: ?>
    <!-- ============================================================ -->
    <!-- 🗓️ Site-wide maintenance due view                             -->
    <!-- ============================================================ -->
    <h1 class="mb-1"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Maintenance due</h1>
    <p class="text-secondary mb-4">Every scheduled maintenance entry with a due date, across the whole site, soonest first.</p>

    <?php if (count($maintenanceRows) === 0): ?>
        <div class="alert alert-info">
            <i class="fa-solid fa-circle-info me-2"></i>Nothing scheduled with a due date right now.
        </div>
    <?php else: ?>
        <div class="portal-data-list">
            <?php foreach ($maintenanceRows as $m): ?>
                <?php $mIsOverdue = (bool) ($m['isOverdue'] ?? false); ?>
                <div class="portal-data-row align-items-start <?php echo $mIsOverdue === true ? 'bg-danger-subtle' : ''; ?>">
                    <div class="col-12 col-md-4">
                        <i class="fa-solid <?php echo htmlspecialchars($maintTypeIcon[(string) $m['maintType']] ?? 'fa-clipboard-list', ENT_QUOTES, 'UTF-8'); ?> me-2 text-muted"></i>
                        <a href="/assets/item?id=<?php echo (int) $m['assetID']; ?>" class="text-decoration-none">
                            <strong><?php echo htmlspecialchars((string) $m['assetName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                        <br><small class="text-muted"><?php echo htmlspecialchars((string) $m['title'], ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                    <div class="col-4 col-md-3">
                        <?php echo htmlspecialchars(ucwords((string) $m['maintType']), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($m['performedByDisplay'] !== null): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars((string) $m['performedByDisplay'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="col-5 col-md-3">
                        Due <?php echo htmlspecialchars((string) $m['nextDueDate'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($mIsOverdue === true): ?>
                            <br><span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Overdue</span>
                        <?php else: ?>
                            <br><span class="badge bg-info">Upcoming</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-3 col-md-2 text-md-end">
                        <a href="/assets/maintenance?assetID=<?php echo (int) $m['assetID']; ?>" class="btn btn-sm btn-outline-primary" title="View log">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="mt-3"><a href="/assets" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a></p>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
