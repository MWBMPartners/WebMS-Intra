<?php
// Path: public_html/documents/categories.php
/**
 * -----------------------------------------------------------------------------
 * Documents — Category Management
 * -----------------------------------------------------------------------------
 * Admin page to create/edit/delete document categories.
 *
 * @package   Portal\Documents
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/90
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Admin only
if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = 'You do not have permission to manage categories.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /documents');
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 📋 Handle POST (create/update/delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /documents/categories');
        exit();
    }

    $action     = $_POST['action'] ?? '';
    $categoryId = (int) ($_POST['categoryID'] ?? 0);
    $catName    = trim($_POST['categoryName'] ?? '');
    $catDesc    = trim($_POST['description'] ?? '');
    $sortOrder  = (int) ($_POST['sortOrder'] ?? 0);

    if ($action === 'delete' && $categoryId > 0) {
        // 📋 Delete category (documents become uncategorised)
        $delStmt = $mysqli->prepare('DELETE FROM tblDocCategories WHERE categoryID = ? AND siteID = ?');
        if ($delStmt !== false) {
            $delStmt->bind_param('ii', $categoryId, $siteId);
            $delStmt->execute();
            $delStmt->close();
        }
        Logger::activity('DocCategoryDeleted', 'Deleted document category ID: ' . $categoryId, $userId);
        $_SESSION['flash_msg']  = 'Category deleted.';
        $_SESSION['flash_type'] = 'info';
    } elseif ($catName !== '') {
        if ($categoryId > 0) {
            // 📋 Update
            $updStmt = $mysqli->prepare(
                'UPDATE tblDocCategories SET categoryName = ?, description = ?, sortOrder = ? WHERE categoryID = ? AND siteID = ?'
            );
            if ($updStmt !== false) {
                $updStmt->bind_param('ssiii', $catName, $catDesc, $sortOrder, $categoryId, $siteId);
                $updStmt->execute();
                $updStmt->close();
            }
            Logger::activity('DocCategoryUpdated', 'Updated document category: ' . $catName, $userId);
            $_SESSION['flash_msg']  = 'Category updated.';
            $_SESSION['flash_type'] = 'success';
        } else {
            // 📋 Create
            $insStmt = $mysqli->prepare(
                'INSERT INTO tblDocCategories (siteID, categoryName, description, sortOrder) VALUES (?, ?, ?, ?)'
            );
            if ($insStmt !== false) {
                $insStmt->bind_param('issi', $siteId, $catName, $catDesc, $sortOrder);
                $insStmt->execute();
                $insStmt->close();
            }
            Logger::activity('DocCategoryCreated', 'Created document category: ' . $catName, $userId);
            $_SESSION['flash_msg']  = 'Category created.';
            $_SESSION['flash_type'] = 'success';
        }
    }

    header('Location: /documents/categories');
    exit();
}

// 📋 Fetch categories
$categories = [];
$catStmt = $mysqli->prepare(
    'SELECT c.*, (SELECT COUNT(*) FROM tblDocuments d WHERE d.categoryID = c.categoryID AND d.isDeleted = 0) AS docCount '
    . 'FROM tblDocCategories c WHERE c.siteID = ? ORDER BY c.sortOrder, c.categoryName'
);
if ($catStmt !== false) {
    $catStmt->bind_param('i', $siteId);
    $catStmt->execute();
    $catResult = $catStmt->get_result();
    while ($row = $catResult->fetch_assoc()) {
        $categories[] = $row;
    }
    $catStmt->close();
}

// 📌 Page metadata
$pageTitle   = 'Document Categories';
$pageSection = 'documents';
$breadcrumbs = ['Dashboard' => '/', 'Documents' => '/documents', 'Categories' => ''];

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📁 Category Management -->
<h1 class="mb-4"><i class="fa-solid fa-folder me-2"></i>Document Categories</h1>

<!-- 📝 Add/Edit Form -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0">Add Category</h5></div>
    <div class="card-body">
        <form method="post" action="/documents/categories">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="categoryID" value="0">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="categoryName" class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="categoryName" name="categoryName" required maxlength="100">
                </div>
                <div class="col-md-4">
                    <label for="description" class="form-label">Description</label>
                    <input type="text" class="form-control" id="description" name="description" maxlength="255">
                </div>
                <div class="col-md-2">
                    <label for="sortOrder" class="form-label">Sort Order</label>
                    <input type="number" class="form-control" id="sortOrder" name="sortOrder" value="0">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-plus me-1"></i>Add
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- 📋 Existing Categories -->
<?php if (count($categories) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-info-circle me-2"></i>No categories yet.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-4">Name</div>
            <div class="col-3">Description</div>
            <div class="col-1">Order</div>
            <div class="col-2">Documents</div>
            <div class="col-2 text-end">Actions</div>
        </div>
        <?php foreach ($categories as $cat): ?>
            <div class="portal-data-row">
                <div class="col-4"><strong><?php echo htmlspecialchars($cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="col-3 small text-muted"><?php echo htmlspecialchars($cat['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-1"><?php echo (int) $cat['sortOrder']; ?></div>
                <div class="col-2"><span class="badge bg-secondary"><?php echo (int) $cat['docCount']; ?></span></div>
                <div class="col-2 text-end">
                    <form method="post" action="/documents/categories" class="d-inline" onsubmit="return confirm('Delete this category? Documents will become uncategorised.');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="categoryID" value="<?php echo (int) $cat['categoryID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<a href="/documents" class="btn btn-outline-secondary mt-3">
    <i class="fa-solid fa-arrow-left me-1"></i>Back to Documents
</a>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
