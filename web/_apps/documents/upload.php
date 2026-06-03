<?php
// Path: public_html/documents/upload.php
/**
 * -----------------------------------------------------------------------------
 * Documents — Upload Handler
 * -----------------------------------------------------------------------------
 * Displays upload form (GET) and handles file upload (POST). Admin only.
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
    $_SESSION['flash_msg']  = 'You do not have permission to upload documents.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /documents');
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$maxSize = (int) (App::settings('documents.maxFileSize') ?? 10485760);

// 📋 Handle POST upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /documents/upload');
        exit();
    }

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categoryId  = ($_POST['categoryID'] ?? '') !== '' ? (int) $_POST['categoryID'] : null;

    // 🔍 Validate file
    if (isset($_FILES['document']) === false || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected.',
        ];
        $errCode = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
        $_SESSION['flash_msg']  = $errorMessages[$errCode] ?? 'File upload failed.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /documents/upload');
        exit();
    }

    $file = $_FILES['document'];

    if ((int) $file['size'] > $maxSize) {
        $_SESSION['flash_msg']  = 'File exceeds maximum size of ' . round($maxSize / 1048576, 1) . ' MB.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /documents/upload');
        exit();
    }

    if ($title === '') {
        $title = pathinfo($file['name'], PATHINFO_FILENAME);
    }

    // 📁 Ensure upload directory exists
    $uploadDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'documents';
    if (is_dir($uploadDir) === false) {
        mkdir($uploadDir, 0755, true);
    }

    // 📋 Generate safe filename
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
    $destPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

    if (move_uploaded_file($file['tmp_name'], $destPath) === false) {
        $_SESSION['flash_msg']  = 'Failed to save uploaded file.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /documents/upload');
        exit();
    }

    // 📋 Insert record
    $stmt = $mysqli->prepare(
        'INSERT INTO tblDocuments (siteID, categoryID, title, description, fileName, filePath, fileSize, mimeType, uploadedByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $origName = $file['name'];
        $fileSize = (int) $file['size'];
        $mimeType = $file['type'];
        $stmt->bind_param(
            'iissssisi',
            $siteId, $categoryId, $title, $description, $origName, $safeName, $fileSize, $mimeType, $userId
        );
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('DocumentUploaded', 'Uploaded document: ' . $title, $userId);
    $_SESSION['flash_msg']  = 'Document uploaded successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /documents');
    exit();
}

// 📋 Fetch categories for dropdown
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

// 📌 Page metadata
$pageTitle   = 'Upload Document';
$pageSection = 'documents';
$breadcrumbs = ['Dashboard' => '/', 'Documents' => '/documents', 'Upload' => ''];

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📤 Upload Form -->
<h1 class="mb-4"><i class="fa-solid fa-upload me-2"></i>Upload Document</h1>

<div class="card">
    <div class="card-body">
        <form method="post" action="/documents/upload" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="mb-3">
                <label for="document" class="form-label">File <span class="text-danger">*</span></label>
                <input type="file" class="form-control" id="document" name="document" required
                       accept="application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,image/*,text/plain,text/csv">
                <small class="text-muted">Maximum size: <?php echo round($maxSize / 1048576, 1); ?> MB · iOS users will see Photo Library + Files alongside the file picker.</small>
            </div>

            <div class="mb-3">
                <label for="title" class="form-label">Title</label>
                <input type="text" class="form-control" id="title" name="title" maxlength="255"
                       placeholder="Leave blank to use filename">
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
            </div>

            <div class="mb-3">
                <label for="categoryID" class="form-label">Category</label>
                <select class="form-select" id="categoryID" name="categoryID">
                    <option value="">Uncategorised</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int) $cat['categoryID']; ?>">
                            <?php echo htmlspecialchars($cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-upload me-1"></i>Upload
                </button>
                <a href="/documents" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
