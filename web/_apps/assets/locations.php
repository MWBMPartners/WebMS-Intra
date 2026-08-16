<?php
// Path: _apps/assets/locations.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Asset Locations 📦
 * -----------------------------------------------------------------------------
 * Manage per-site asset storage/deployment locations — list + add/edit
 * (inline, via `?edit=`) + active/inactive toggle + self-nesting via
 * parentLocationID (e.g. Building → Room → Cupboard). Self-posting, same
 * shape as `_apps/assets/categories.php` — see that file's header for the
 * house-pattern rationale.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
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

// 🛡️ Manager gate — admins or the asset_manager role only.
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 CSRF FIRST — before any side-effect.
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /assets/locations');
        exit();
    }

    $action     = (string) ($_POST['action'] ?? 'save');
    $locationId = (int) ($_POST['locationID'] ?? 0);

    if ($action === 'toggle') {
        AssetRegister::toggleLocationActive($locationId, $siteId, $userId);
        $_SESSION['flash_msg']  = 'Location updated.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $saved = AssetRegister::saveLocation($siteId, $locationId, $_POST, $userId);
        $_SESSION['flash_msg']  = $saved > 0 ? 'Location saved.' : 'Location name is required.';
        $_SESSION['flash_type'] = $saved > 0 ? 'success' : 'danger';
    }

    header('Location: /assets/locations');
    exit();
}

$locations = AssetRegister::listLocations($siteId, false);

// 🗂️ locationID → locationName lookup for the "Parent" column display.
$locationNames = [];
foreach ($locations as $l) {
    $locationNames[(int) $l['locationID']] = (string) $l['locationName'];
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

// ✏️ Inline "edit" — prefill the add form from ?edit=ID (no JS required).
$editId       = (int) ($_GET['edit'] ?? 0);
$editLocation = null;
if ($editId > 0) {
    foreach ($locations as $l) {
        if ((int) $l['locationID'] === $editId) {
            $editLocation = $l;
            break;
        }
    }
}

$pageTitle   = 'Asset Locations';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Locations' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-location-dot me-2"></i>Asset Locations</h1>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5"><?php echo $editLocation !== null ? 'Edit location' : 'Add location'; ?></h2>
        <form method="post" action="/assets/locations" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="locationID" value="<?php echo $editLocation !== null ? (int) $editLocation['locationID'] : 0; ?>">
            <div class="col-md-4">
                <label class="form-label small" for="locationName">Name</label>
                <input type="text" class="form-control form-control-sm" id="locationName" name="locationName" required maxlength="150"
                       value="<?php echo $editLocation !== null ? htmlspecialchars((string) $editLocation['locationName'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="details">Details</label>
                <input type="text" class="form-control form-control-sm" id="details" name="details" maxlength="500"
                       value="<?php echo $editLocation !== null ? htmlspecialchars((string) ($editLocation['details'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="parentLocationID">Parent location</label>
                <select class="form-select form-select-sm" id="parentLocationID" name="parentLocationID">
                    <option value="">None</option>
                    <?php foreach ($locations as $l): ?>
                        <?php if ($editLocation !== null && (int) $l['locationID'] === (int) $editLocation['locationID']) { continue; /* 🔁 can't be its own parent */ } ?>
                        <option value="<?php echo (int) $l['locationID']; ?>"
                            <?php echo ($editLocation !== null && (int) ($editLocation['parentLocationID'] ?? 0) === (int) $l['locationID']) ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $l['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fa-solid fa-<?php echo $editLocation !== null ? 'check' : 'plus'; ?> me-1"></i><?php echo $editLocation !== null ? 'Update' : 'Add'; ?>
                </button>
                <?php if ($editLocation !== null): ?>
                    <a href="/assets/locations" class="btn btn-outline-secondary btn-sm w-100 mt-1">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (count($locations) === 0): ?>
    <div class="alert alert-info">No locations yet.</div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-3">Name</div>
            <div class="col-3">Parent</div>
            <div class="col-2">Details</div>
            <div class="col-4 text-end">Actions</div>
        </div>
        <?php foreach ($locations as $loc): ?>
            <?php $parentId = (int) ($loc['parentLocationID'] ?? 0); ?>
            <div class="portal-data-row align-items-center">
                <div class="col-3">
                    <strong><?php echo htmlspecialchars((string) $loc['locationName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ((int) $loc['isActive'] === 0): ?><span class="badge bg-secondary ms-1">inactive</span><?php endif; ?>
                </div>
                <div class="col-3 small text-muted">
                    <?php echo $parentId > 0 && isset($locationNames[$parentId]) === true ? htmlspecialchars($locationNames[$parentId], ENT_QUOTES, 'UTF-8') : '—'; ?>
                </div>
                <div class="col-2 small text-muted"><?php echo htmlspecialchars((string) ($loc['details'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-4 text-end">
                    <a href="/assets/locations?edit=<?php echo (int) $loc['locationID']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                    <form method="post" action="/assets/locations" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="locationID" value="<?php echo (int) $loc['locationID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?php echo (int) $loc['isActive'] === 1 ? 'warning' : 'success'; ?>"
                                title="<?php echo (int) $loc['isActive'] === 1 ? 'Deactivate' : 'Activate'; ?>">
                            <i class="fa-solid fa-toggle-<?php echo (int) $loc['isActive'] === 1 ? 'on' : 'off'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<a href="/assets" class="btn btn-outline-secondary mt-3"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
