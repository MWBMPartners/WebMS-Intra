<?php
// Path: public_html/photos/upload.php
/**
 * Photos — upload form. Logged-in users upload to the queue with
 * pending_approval status. EXIF retained on disk.
 *
 * @package   Portal\Photos
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/236
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Photos;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db        = App::db();
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$settings  = App::settings()['photos'] ?? [];
$maxMb     = (int) ($settings['maxUploadMb'] ?? 15);
$maxBytes  = $maxMb * 1024 * 1024;

$albums = [];
$rs = $db->query('SELECT albumID, name FROM tblPhotoAlbum WHERE siteID = ' . (int) $siteId . ' ORDER BY name');
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $albums[] = $r;
    }
    $rs->free();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }
    if (isset($_FILES['photo']) === false || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_msg']  = 'No file uploaded.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /photos/upload');
        exit();
    }
    $f = $_FILES['photo'];
    if ((int) $f['size'] > $maxBytes) {
        $_SESSION['flash_msg']  = 'File exceeds ' . $maxMb . ' MB.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /photos/upload');
        exit();
    }
    $mime = (string) $f['type'];
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) === false) {
        $_SESSION['flash_msg']  = 'Only JPEG / PNG / WebP accepted.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /photos/upload');
        exit();
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    $name = 'q-' . date('Ymd_His') . '-' . bin2hex(random_bytes(6)) . ($ext !== '' ? '.' . $ext : '');
    $dst  = Photos::queueDir() . DIRECTORY_SEPARATOR . $name;
    if (move_uploaded_file((string) $f['tmp_name'], $dst) === false) {
        $_SESSION['flash_msg']  = 'Could not save file.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /photos/upload');
        exit();
    }

    $width = null;
    $height = null;
    $info = @getimagesize($dst);
    if ($info !== false) {
        $width  = (int) $info[0];
        $height = (int) $info[1];
    }
    $taken = Photos::exifTakenAt($dst);

    $albumId = (int) ($_POST['albumID'] ?? 0) ?: null;
    $caption = trim((string) ($_POST['caption'] ?? ''));
    $vis     = (string) ($_POST['visibility'] ?? 'inherit');
    if (in_array($vis, ['public','volunteers','staff','admin_only','inherit'], true) === false) {
        $vis = 'inherit';
    }
    $rel = 'queue' . DIRECTORY_SEPARATOR . $name;
    $size = (int) $f['size'];

    $stmt = $db->prepare(
        'INSERT INTO tblPhoto (siteID, albumID, uploadedByUserID, filePath, originalFilename, '
        . 'mimeType, fileSize, widthPx, heightPx, caption, visibility, status, takenAt) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending_approval", ?)'
    );
    if ($stmt !== false) {
        $origName = (string) $f['name'];
        $stmt->bind_param(
            'iiissssiisss',
            $siteId, $albumId, $userId, $rel, $origName, $mime, $size, $width, $height, $caption, $vis, $taken
        );
        $stmt->execute();
        $stmt->close();
    }

    $_SESSION['flash_msg']  = 'Photo uploaded — pending moderator approval.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /photos');
    exit();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Upload photo';
$pageSection = 'photos';
$breadcrumbs = ['Dashboard' => '/', 'Photos' => '/photos', 'Upload' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-upload me-2"></i>Upload photo</h1>
<p class="text-secondary">Photos are reviewed by a moderator before publishing. Max <?php echo (int) $maxMb; ?> MB. JPEG / PNG / WebP.</p>

<form method="post" enctype="multipart/form-data" class="card"><div class="card-body row g-3">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="col-md-6">
        <label class="form-label">Photo</label>
        <input type="file" class="form-control" name="photo" accept="image/jpeg,image/png,image/webp" required>
    </div>
    <div class="col-md-6">
        <label class="form-label">Album</label>
        <select class="form-select" name="albumID">
            <option value="0">— No album (admin to assign) —</option>
            <?php foreach ($albums as $a): ?>
                <option value="<?php echo (int) $a['albumID']; ?>"><?php echo htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-8">
        <label class="form-label">Caption (optional)</label>
        <input type="text" class="form-control" name="caption" maxlength="500">
    </div>
    <div class="col-md-4">
        <label class="form-label">Visibility</label>
        <select class="form-select" name="visibility">
            <option value="inherit">Inherit from album</option>
            <option value="public">Public</option>
            <option value="volunteers">Volunteers</option>
            <option value="staff">Staff</option>
            <option value="admin_only">Admin only</option>
        </select>
    </div>
    <div class="col-12">
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-upload me-1"></i>Upload for review</button>
        <a href="/photos" class="btn btn-outline-secondary">Cancel</a>
    </div>
</div></form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
