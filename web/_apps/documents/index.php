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
 * @version   0.9.0
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
                            <!-- ✏️ Edit metadata (#gap-fix D6) — opens the shared #docEditModal below,
                                 populated from these data-* attributes; index.php previously exposed
                                 only Download/Delete even though api/update.php already supported it. -->
                            <button type="button" class="btn btn-sm btn-outline-secondary portal-doc-edit-btn" title="Edit"
                                    data-doc-id="<?php echo (int) $doc['documentID']; ?>"
                                    data-doc-title="<?php echo htmlspecialchars($doc['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-doc-description="<?php echo htmlspecialchars((string) ($doc['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-doc-category-id="<?php echo $doc['categoryID'] !== null ? (int) $doc['categoryID'] : ''; ?>"
                                    data-doc-published="<?php echo ((int) $doc['isPublished'] === 1) ? '1' : '0'; ?>">
                                <i class="fa-solid fa-pen"></i>
                            </button>
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

<?php if (App::isAdmin() === true): ?>
<!-- ✏️ Shared "Edit document" modal (#gap-fix D6). Populated per-row by
     portal-doc-edit-btn's data-* attributes; submits JSON to the existing
     dual-mode (bearer OR admin session + CSRF) api/documents/update.php via
     window.Portal.fetch (portal.js — global on every page), so this reuses
     the SAME title/description/categoryID/isPublished validation the REST
     API already enforces rather than duplicating it in a new handler. -->
<div class="modal fade" id="docEditModal" tabindex="-1" aria-labelledby="docEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <form id="docEditForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="docEditModalLabel">Edit document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="docEditId" value="">
                    <div class="mb-3">
                        <label class="form-label" for="docEditTitle">Title</label>
                        <input type="text" class="form-control" id="docEditTitle" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="docEditDescription">Description</label>
                        <textarea class="form-control" id="docEditDescription" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="docEditCategory">Category</label>
                        <select class="form-select" id="docEditCategory">
                            <option value="">— Uncategorised —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int) $cat['categoryID']; ?>">
                                    <?php echo htmlspecialchars($cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="docEditPublished">
                        <label class="form-check-label" for="docEditPublished">Published</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('docEditModal');
    if (!modalEl || !window.bootstrap) {
        return;
    }
    var modal = new bootstrap.Modal(modalEl);
    var form  = document.getElementById('docEditForm');

    // 🖱️ Open + populate the modal from the clicked row's data-* attributes.
    document.querySelectorAll('.portal-doc-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('docEditId').value = btn.getAttribute('data-doc-id') || '';
            document.getElementById('docEditTitle').value = btn.getAttribute('data-doc-title') || '';
            document.getElementById('docEditDescription').value = btn.getAttribute('data-doc-description') || '';
            document.getElementById('docEditCategory').value = btn.getAttribute('data-doc-category-id') || '';
            document.getElementById('docEditPublished').checked = btn.getAttribute('data-doc-published') === '1';
            modal.show();
        });
    });

    // 💾 Submit — JSON body via window.Portal.fetch (adds X-CSRF-Token from
    // the page's csrf-token meta tag automatically; see portal.js).
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var id = document.getElementById('docEditId').value;
        if (!id) {
            return;
        }
        var categoryVal = document.getElementById('docEditCategory').value;
        var payload = {
            title: document.getElementById('docEditTitle').value,
            description: document.getElementById('docEditDescription').value,
            categoryID: categoryVal === '' ? null : parseInt(categoryVal, 10),
            isPublished: document.getElementById('docEditPublished').checked
        };

        window.Portal.fetch(
            '/api/documents/update?id=' + encodeURIComponent(id),
            { method: 'POST', body: payload },
            function () {
                if (window.Portal.toast) {
                    window.Portal.toast('Document updated.', 'success');
                }
                window.location.reload();
            },
            function (message) {
                if (window.Portal.toast) {
                    window.Portal.toast('Update failed: ' + message, 'danger');
                } else {
                    window.alert('Update failed: ' + message);
                }
            }
        );
    });
});
</script>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
