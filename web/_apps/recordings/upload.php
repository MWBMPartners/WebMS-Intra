<?php
// Path: public_html/recordings/upload.php
/**
 * Recordings — upload form + handler (admin only).
 *
 * Accepts a media file OR an external URL (YouTube/Vimeo). Stores files
 * under _uploads/recordings/ with a randomised safe filename.
 *
 * @package   Portal\Recordings
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/264
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Recordings;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db        = App::db();
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$maxMb     = (int) (App::settings()['recordings']['max_upload_mb'] ?? 200);
$maxBytes  = $maxMb * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }

    $title       = trim((string) ($_POST['title'] ?? ''));
    $presenter   = trim((string) ($_POST['presenterText'] ?? ''));
    $recordedAt  = (string) ($_POST['recordedAt'] ?? '');
    $kind        = (string) ($_POST['kind'] ?? 'sermon');
    $scripture   = trim((string) ($_POST['scripture'] ?? ''));
    $topics      = trim((string) ($_POST['topics'] ?? ''));
    $summary     = (string) ($_POST['summary'] ?? '');
    $externalUrl = trim((string) ($_POST['externalUrl'] ?? ''));
    $duration    = (int) ($_POST['durationSeconds'] ?? 0);

    if (in_array($kind, ['sermon','teaching','music','event','other'], true) === false) {
        $kind = 'other';
    }
    if ($externalUrl !== '' && filter_var($externalUrl, FILTER_VALIDATE_URL) === false) {
        $externalUrl = '';
    }
    if ($title === '') {
        $_SESSION['flash_msg']  = 'Title is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /recordings/upload');
        exit();
    }

    $filePath  = null;
    $fileSize  = null;
    $mimeType  = null;

    // 📁 Optional file upload — accept audio/video.
    if (isset($_FILES['media']) === true && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['media'];
        if ((int) $f['size'] > $maxBytes) {
            $_SESSION['flash_msg']  = 'File exceeds max ' . $maxMb . ' MB.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /recordings/upload');
            exit();
        }
        $mt = (string) $f['type'];
        if (strpos($mt, 'audio/') !== 0 && strpos($mt, 'video/') !== 0) {
            $_SESSION['flash_msg']  = 'Only audio or video files are accepted.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /recordings/upload');
            exit();
        }
        $ext      = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
        $dest     = Recordings::uploadDir() . DIRECTORY_SEPARATOR . $safeName;
        if (move_uploaded_file((string) $f['tmp_name'], $dest) === false) {
            $_SESSION['flash_msg']  = 'Failed to save uploaded file.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /recordings/upload');
            exit();
        }
        $filePath = $safeName;
        $fileSize = (int) $f['size'];
        $mimeType = $mt;
    } elseif ($externalUrl === '') {
        $_SESSION['flash_msg']  = 'Provide either a media file or an external URL.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /recordings/upload');
        exit();
    }

    $stmt = $db->prepare(
        'INSERT INTO tblRecording '
        . '(siteID, title, presenterText, recordedAt, durationSeconds, kind, scripture, topics, summary, '
        . ' filePath, fileSize, mimeType, externalUrl, uploadedByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $recordedOrNull = $recordedAt !== '' && strtotime($recordedAt) !== false ? $recordedAt : null;
        $durOrNull      = $duration > 0 ? $duration : null;
        $extOrNull      = $externalUrl !== '' ? $externalUrl : null;
        $stmt->bind_param(
            'isssissssisssi',
            $siteId, $title, $presenter, $recordedOrNull, $durOrNull,
            $kind, $scripture, $topics, $summary,
            $filePath, $fileSize, $mimeType, $extOrNull, $userId
        );
        $stmt->execute();
        $stmt->close();
    }

    Recordings::syncTopics($siteId, $topics);

    $_SESSION['flash_msg']  = 'Recording uploaded.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /recordings/manage');
    exit();
}

$pageTitle   = 'Upload Recording';
$pageSection = 'recordings';
$breadcrumbs = ['Dashboard' => '/', 'Recordings' => '/recordings', 'Upload' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-upload me-2"></i>Upload recording</h1>
<p class="text-secondary">Attach a media file (max <?php echo (int) $maxMb; ?> MB) or paste an external URL.</p>

<div class="card">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-8">
                <label class="form-label">Title *</label>
                <input type="text" class="form-control" name="title" required maxlength="255">
            </div>
            <div class="col-md-4">
                <label class="form-label">Kind</label>
                <select class="form-select" name="kind">
                    <?php foreach (['sermon','teaching','music','event','other'] as $k): ?>
                        <option value="<?php echo $k; ?>"><?php echo ucfirst($k); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Presenter (text)</label>
                <input type="text" class="form-control" name="presenterText" maxlength="255">
            </div>
            <div class="col-md-4">
                <label class="form-label">Recorded on</label>
                <input type="date" class="form-control" name="recordedAt">
            </div>
            <div class="col-md-4">
                <label class="form-label">Duration (seconds)</label>
                <input type="number" min="0" class="form-control" name="durationSeconds" placeholder="optional">
            </div>
            <div class="col-md-6">
                <label class="form-label">Scripture / reference</label>
                <input type="text" class="form-control" name="scripture" maxlength="255" placeholder="e.g. John 3:16">
            </div>
            <div class="col-md-6">
                <label class="form-label">Topics (comma-separated)</label>
                <input type="text" class="form-control" name="topics" maxlength="500" placeholder="grace, faith, hope">
            </div>
            <div class="col-12">
                <label class="form-label">Summary (markdown)</label>
                <textarea class="form-control" name="summary" rows="4"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Media file</label>
                <input type="file" class="form-control" name="media" accept="audio/*,video/*">
                <small class="text-muted">Audio/video, max <?php echo (int) $maxMb; ?> MB.</small>
            </div>
            <div class="col-md-6">
                <label class="form-label">External URL (instead of file)</label>
                <input type="url" class="form-control" name="externalUrl" placeholder="https://youtu.be/…">
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-upload me-1"></i>Save recording</button>
                <a href="/recordings/manage" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
