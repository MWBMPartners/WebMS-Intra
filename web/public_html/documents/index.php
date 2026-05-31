<?php
// Path: public_html/documents/index.php
/**
 * -----------------------------------------------------------------------------
 * Documents — File Library Listing
 * -----------------------------------------------------------------------------
 * Displays uploaded documents grouped by category with download links.
 * Supports filtering by category.
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
use Portal\Core\I18n;
use Portal\Core\Site;

Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

// 📌 Page metadata
$pageTitle   = 'Documents';
$pageSection = 'documents';
$breadcrumbs = ['Dashboard' => '/', 'Documents' => ''];

$siteId    = Site::id();
$filterCat = isset($_GET['cat']) ? (int) $_GET['cat'] : null;

// 📋 Fetch categories
$categories = [];
$catStmt = $mysqli->prepare(
    'SELECT * FROM tblDocCategories WHERE siteID = ? ORDER BY sortOrder, categoryName'
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

// 📋 Fetch documents
$documents = [];
if ($filterCat !== null) {
    $docStmt = $mysqli->prepare(
        'SELECT d.*, c.categoryName, u.fullName AS uploaderName FROM tblDocuments d '
        . 'LEFT JOIN tblDocCategories c ON c.categoryID = d.categoryID '
        . 'LEFT JOIN tblUsers u ON u.userID = d.uploadedByID '
        . 'WHERE d.siteID = ? AND d.isPublished = 1 AND d.isDeleted = 0 AND d.categoryID = ? '
        . 'ORDER BY d.title'
    );
    if ($docStmt !== false) {
        $docStmt->bind_param('ii', $siteId, $filterCat);
        $docStmt->execute();
        $docResult = $docStmt->get_result();
        while ($row = $docResult->fetch_assoc()) {
            $documents[] = $row;
        }
        $docStmt->close();
    }
} else {
    $docStmt = $mysqli->prepare(
        'SELECT d.*, c.categoryName, u.fullName AS uploaderName FROM tblDocuments d '
        . 'LEFT JOIN tblDocCategories c ON c.categoryID = d.categoryID '
        . 'LEFT JOIN tblUsers u ON u.userID = d.uploadedByID '
        . 'WHERE d.siteID = ? AND d.isPublished = 1 AND d.isDeleted = 0 '
        . 'ORDER BY c.sortOrder, c.categoryName, d.title'
    );
    if ($docStmt !== false) {
        $docStmt->bind_param('i', $siteId);
        $docStmt->execute();
        $docResult = $docStmt->get_result();
        while ($row = $docResult->fetch_assoc()) {
            $documents[] = $row;
        }
        $docStmt->close();
    }
}

// 📊 Group documents by category
$grouped = [];
foreach ($documents as $doc) {
    $catName = $doc['categoryName'] ?? 'Uncategorised';
    $grouped[$catName][] = $doc;
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📁 Document Library -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-folder-open me-2"></i>Documents</h1>
    <?php if (App::isAdmin() === true): ?>
        <div class="d-flex gap-2">
            <a href="/documents/categories" class="btn btn-outline-secondary">
                <i class="fa-solid fa-folder me-1"></i>Categories
            </a>
            <a href="/documents/upload" class="btn btn-primary">
                <i class="fa-solid fa-upload me-1"></i>Upload
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- 🏷️ Category filter -->
<?php if (count($categories) > 0): ?>
    <div class="mb-4">
        <a href="/documents" class="btn btn-sm <?php echo ($filterCat === null ? 'btn-primary' : 'btn-outline-primary'); ?> me-1">All</a>
        <?php foreach ($categories as $cat): ?>
            <a href="/documents?cat=<?php echo (int) $cat['categoryID']; ?>"
               class="btn btn-sm <?php echo ($filterCat === (int) $cat['categoryID'] ? 'btn-primary' : 'btn-outline-primary'); ?> me-1 mb-1">
                <?php echo htmlspecialchars($cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (count($documents) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-info-circle me-2"></i>No documents available.
    </div>
<?php else: ?>
    <?php foreach ($grouped as $catName => $docs): ?>
        <h5 class="mt-4 mb-3">
            <i class="fa-solid fa-folder text-warning me-1"></i>
            <?php echo htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'); ?>
            <span class="badge bg-secondary ms-1"><?php echo count($docs); ?></span>
        </h5>
        <div class="portal-data-list mb-3">
            <?php foreach ($docs as $doc): ?>
                <?php
                $sizeKb = round((int) $doc['fileSize'] / 1024);
                $sizeDisplay = $sizeKb >= 1024 ? round($sizeKb / 1024, 1) . ' MB' : $sizeKb . ' KB';
                $iconMap = [
                    'application/pdf' => 'fa-file-pdf text-danger',
                    'application/msword' => 'fa-file-word text-primary',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'fa-file-word text-primary',
                    'application/vnd.ms-excel' => 'fa-file-excel text-success',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'fa-file-excel text-success',
                    'application/vnd.ms-powerpoint' => 'fa-file-powerpoint text-warning',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'fa-file-powerpoint text-warning',
                    'image/jpeg' => 'fa-file-image text-info',
                    'image/png' => 'fa-file-image text-info',
                    'text/plain' => 'fa-file-lines text-muted',
                ];
                $fileIcon = $iconMap[$doc['mimeType'] ?? ''] ?? 'fa-file text-muted';
                ?>
                <div class="portal-data-row align-items-center">
                    <div class="col-6 col-md-5">
                        <i class="fa-solid <?php echo $fileIcon; ?> me-2"></i>
                        <strong><?php echo htmlspecialchars($doc['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if ($doc['description'] !== null && $doc['description'] !== ''): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($doc['description'], ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="col-3 col-md-2 small text-muted">
                        <?php echo htmlspecialchars($sizeDisplay, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-3 col-md-3 small text-muted d-none d-md-block">
                        <?php echo htmlspecialchars(I18n::formatDate($doc['createdAt'], 'short'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($doc['uploaderName'] !== null): ?>
                            — <?php echo htmlspecialchars($doc['uploaderName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-3 col-md-2 text-end">
                        <a href="/documents/download?id=<?php echo (int) $doc['documentID']; ?>" class="btn btn-sm btn-outline-primary" title="Download">
                            <i class="fa-solid fa-download"></i>
                        </a>
                        <?php if (App::isAdmin() === true): ?>
                            <form method="post" action="/documents/delete" class="d-inline" data-confirm="Delete this document?" data-confirm-destructive="true">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="documentID" value="<?php echo (int) $doc['documentID']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
