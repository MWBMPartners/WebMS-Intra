<?php
// Path: _apps/assets/categories.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Asset Categories 📦
 * -----------------------------------------------------------------------------
 * Manage per-site asset categories — list + add/edit (inline, via `?edit=`)
 * + active/inactive toggle. Self-posting (GET renders, POST mutates, both
 * on this same route) — mirrors `_apps/documents/categories.php` and
 * `_apps/giving/categories.php`'s established house pattern for this shape
 * of small reference-data CRUD screen.
 *
 * Categories are never hard-deleted (tblAssets.categoryID is
 * `ON DELETE SET NULL`, so nothing stops a real delete technically — but an
 * active/inactive toggle preserves the category's name on historical
 * assets/reports instead of quietly nulling it out from under them).
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
        header('Location: /assets/categories');
        exit();
    }

    $action     = (string) ($_POST['action'] ?? 'save');
    $categoryId = (int) ($_POST['categoryID'] ?? 0);

    if ($action === 'toggle') {
        AssetRegister::toggleCategoryActive($categoryId, $siteId, $userId);
        $_SESSION['flash_msg']  = 'Category updated.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $saved = AssetRegister::saveCategory($siteId, $categoryId, $_POST, $userId);
        $_SESSION['flash_msg']  = $saved > 0 ? 'Category saved.' : 'Category name is required.';
        $_SESSION['flash_type'] = $saved > 0 ? 'success' : 'danger';
    }

    header('Location: /assets/categories');
    exit();
}

$categories = AssetRegister::listCategories($siteId, false);

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

// ✏️ Inline "edit" — prefill the add form from ?edit=ID (no JS required).
$editId       = (int) ($_GET['edit'] ?? 0);
$editCategory = null;
if ($editId > 0) {
    foreach ($categories as $c) {
        if ((int) $c['categoryID'] === $editId) {
            $editCategory = $c;
            break;
        }
    }
}

$pageTitle   = 'Asset Categories';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Categories' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-tags me-2"></i>Asset Categories</h1>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5"><?php echo $editCategory !== null ? 'Edit category' : 'Add category'; ?></h2>
        <form method="post" action="/assets/categories" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="categoryID" value="<?php echo $editCategory !== null ? (int) $editCategory['categoryID'] : 0; ?>">
            <div class="col-md-4">
                <label class="form-label small" for="categoryName">Name</label>
                <input type="text" class="form-control form-control-sm" id="categoryName" name="categoryName" required maxlength="100"
                       value="<?php echo $editCategory !== null ? htmlspecialchars((string) $editCategory['categoryName'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="icon">Icon (Font Awesome class)</label>
                <input type="text" class="form-control form-control-sm" id="icon" name="icon" maxlength="50" placeholder="fa-solid fa-laptop"
                       value="<?php echo $editCategory !== null ? htmlspecialchars((string) ($editCategory['icon'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="sortOrder">Sort order</label>
                <input type="number" class="form-control form-control-sm" id="sortOrder" name="sortOrder"
                       value="<?php echo $editCategory !== null ? (int) $editCategory['sortOrder'] : 0; ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fa-solid fa-<?php echo $editCategory !== null ? 'check' : 'plus'; ?> me-1"></i><?php echo $editCategory !== null ? 'Update' : 'Add'; ?>
                </button>
                <?php if ($editCategory !== null): ?>
                    <a href="/assets/categories" class="btn btn-outline-secondary btn-sm w-100 mt-1">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (count($categories) === 0): ?>
    <div class="alert alert-info">No categories yet.</div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-4">Name</div>
            <div class="col-3">Icon</div>
            <div class="col-1">Order</div>
            <div class="col-4 text-end">Actions</div>
        </div>
        <?php foreach ($categories as $cat): ?>
            <div class="portal-data-row align-items-center">
                <div class="col-4">
                    <strong><?php echo htmlspecialchars((string) $cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ((int) $cat['isActive'] === 0): ?><span class="badge bg-secondary ms-1">inactive</span><?php endif; ?>
                </div>
                <div class="col-3 small text-muted"><?php echo htmlspecialchars((string) ($cat['icon'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-1"><?php echo (int) $cat['sortOrder']; ?></div>
                <div class="col-4 text-end">
                    <a href="/assets/categories?edit=<?php echo (int) $cat['categoryID']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                    <form method="post" action="/assets/categories" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="categoryID" value="<?php echo (int) $cat['categoryID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?php echo (int) $cat['isActive'] === 1 ? 'warning' : 'success'; ?>"
                                title="<?php echo (int) $cat['isActive'] === 1 ? 'Deactivate' : 'Activate'; ?>">
                            <i class="fa-solid fa-toggle-<?php echo (int) $cat['isActive'] === 1 ? 'on' : 'off'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<a href="/assets" class="btn btn-outline-secondary mt-3"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
