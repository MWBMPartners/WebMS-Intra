<?php
// Path: public_html/recordings/manage.php
/**
 * Recordings — admin manage list with inline publish toggle + edit links.
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

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_GET['id'] ?? 0);

$editing = null;
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM tblRecording WHERE recordingID = ? AND siteID = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $id, $siteId);
        $stmt->execute();
        $editing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$recordings = [];
$stmt = $db->prepare(
    'SELECT recordingID, title, kind, recordedAt, durationSeconds, isPublished, '
    . '       (SELECT COUNT(*) FROM tblRecordingPlay p WHERE p.recordingID = r.recordingID) AS playCount '
    . 'FROM tblRecording r WHERE siteID = ? ORDER BY recordedAt DESC, recordingID DESC LIMIT 200'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $recordings[] = $r;
    }
    $stmt->close();
}

$csrf = Auth::csrfToken();

$pageTitle   = 'Manage Recordings';
$pageSection = 'recordings';
$breadcrumbs = ['Dashboard' => '/', 'Recordings' => '/recordings', 'Manage' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-gear me-2"></i>Manage recordings</h1>
    <a href="/recordings/upload" class="btn btn-primary btn-sm"><i class="fa-solid fa-upload me-1"></i>Upload new</a>
</div>

<?php if ($editing !== null): ?>
    <div class="card mb-4">
        <div class="card-header"><strong>Edit: <?php echo htmlspecialchars((string) $editing['title'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="card-body">
            <form method="post" action="/recordings/save" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="recordingID" value="<?php echo (int) $editing['recordingID']; ?>">
                <div class="col-md-8">
                    <label class="form-label">Title</label>
                    <input type="text" class="form-control" name="title" value="<?php echo htmlspecialchars((string) $editing['title'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Kind</label>
                    <select class="form-select" name="kind">
                        <?php foreach (['sermon','teaching','music','event','other'] as $k): ?>
                            <option value="<?php echo $k; ?>" <?php echo ((string) $editing['kind']) === $k ? 'selected' : ''; ?>><?php echo ucfirst($k); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Presenter</label>
                    <input type="text" class="form-control" name="presenterText" value="<?php echo htmlspecialchars((string) ($editing['presenterText'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Recorded on</label>
                    <input type="date" class="form-control" name="recordedAt" value="<?php echo htmlspecialchars((string) ($editing['recordedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Duration (sec)</label>
                    <input type="number" min="0" class="form-control" name="durationSeconds" value="<?php echo (int) ($editing['durationSeconds'] ?? 0); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Scripture</label>
                    <input type="text" class="form-control" name="scripture" value="<?php echo htmlspecialchars((string) ($editing['scripture'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Topics</label>
                    <input type="text" class="form-control" name="topics" value="<?php echo htmlspecialchars((string) ($editing['topics'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">External URL</label>
                    <input type="url" class="form-control" name="externalUrl" value="<?php echo htmlspecialchars((string) ($editing['externalUrl'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="isPublished" name="isPublished" value="1" <?php echo (int) $editing['isPublished'] === 1 ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="isPublished">Published</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Summary</label>
                    <textarea class="form-control" name="summary" rows="4"><?php echo htmlspecialchars((string) ($editing['summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Save changes</button>
                    <a href="/recordings/manage" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (count($recordings) === 0): ?>
    <div class="alert alert-info">No recordings yet. <a href="/recordings/upload">Upload the first →</a></div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($recordings as $r): ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-5">
                            <a href="/recordings/view?id=<?php echo (int) $r['recordingID']; ?>"><strong><?php echo htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8'); ?></strong></a>
                            <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars((string) $r['kind'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="col-md-2 small text-muted">
                            <?php echo $r['recordedAt'] !== null ? htmlspecialchars(date('j M Y', (int) strtotime((string) $r['recordedAt'])), ENT_QUOTES, 'UTF-8') : '—'; ?>
                        </div>
                        <div class="col-md-1 small text-muted">
                            <?php echo $r['durationSeconds'] !== null ? htmlspecialchars(Recordings::formatDuration((int) $r['durationSeconds']), ENT_QUOTES, 'UTF-8') : '—'; ?>
                        </div>
                        <div class="col-md-1 small text-muted"><?php echo (int) $r['playCount']; ?> plays</div>
                        <div class="col-md-1">
                            <?php if ((int) $r['isPublished'] === 1): ?>
                                <span class="badge bg-success">Live</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Hidden</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2 text-end">
                            <a href="/recordings/manage?id=<?php echo (int) $r['recordingID']; ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                            <form method="post" action="/recordings/delete" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="recordingID" value="<?php echo (int) $r['recordingID']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Delete this recording and its file?">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
