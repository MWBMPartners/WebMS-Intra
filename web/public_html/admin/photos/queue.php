<?php
// Path: public_html/admin/photos/queue.php
/**
 * Admin — Photo moderation queue.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/236
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Photos;
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

$rows = [];
$stmt = $db->prepare(
    'SELECT p.photoID, p.albumID, p.uploadedByUserID, p.originalFilename, p.mimeType, p.fileSize, '
    . '       p.widthPx, p.heightPx, p.caption, p.visibility, p.takenAt, p.createdAt, '
    . '       u.fullName, a.name AS albumName '
    . 'FROM tblPhoto p '
    . 'LEFT JOIN tblUsers u ON u.userID = p.uploadedByUserID '
    . 'LEFT JOIN tblPhotoAlbum a ON a.albumID = p.albumID '
    . 'WHERE p.siteID = ? AND p.status = "pending_approval" ORDER BY p.createdAt ASC LIMIT 200'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$albums = [];
$rs = $db->query('SELECT albumID, name FROM tblPhotoAlbum WHERE siteID = ' . (int) $siteId . ' ORDER BY name');
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $albums[] = $r;
    }
    $rs->free();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Photo moderation';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Photos' => '/photos', 'Moderation' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-clock me-2"></i>Photo moderation queue</h1>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">Nothing pending. Time for a cup of tea ☕.</div>
<?php else: ?>
    <?php foreach ($rows as $p): ?>
        <div class="card mb-3">
            <div class="card-body row g-3">
                <div class="col-md-3">
                    <img src="/photos/serve?id=<?php echo (int) $p['photoID']; ?>" class="img-fluid rounded" style="max-height:200px;" alt="">
                </div>
                <div class="col-md-9">
                    <div class="row mb-2 small">
                        <div class="col-md-6"><strong>By:</strong> <?php echo htmlspecialchars((string) ($p['fullName'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-6"><strong>Uploaded:</strong> <?php echo htmlspecialchars((string) $p['createdAt'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-6"><strong>File:</strong> <?php echo htmlspecialchars((string) ($p['originalFilename'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo (int) ($p['widthPx'] ?? 0); ?>×<?php echo (int) ($p['heightPx'] ?? 0); ?></div>
                        <div class="col-md-6"><strong>Taken:</strong> <?php echo htmlspecialchars((string) ($p['takenAt'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php if (($p['caption'] ?? '') !== ''): ?>
                        <p class="text-muted small">"<?php echo htmlspecialchars((string) $p['caption'], ENT_QUOTES, 'UTF-8'); ?>"</p>
                    <?php endif; ?>
                    <form method="post" action="/admin/photos/moderate" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="photoID" value="<?php echo (int) $p['photoID']; ?>">
                        <div class="col-md-3">
                            <label class="form-label small">Move to album</label>
                            <select class="form-select form-select-sm" name="albumID">
                                <option value="0">— None —</option>
                                <?php foreach ($albums as $a): ?>
                                    <option value="<?php echo (int) $a['albumID']; ?>" <?php echo (int) $p['albumID'] === (int) $a['albumID'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Visibility</label>
                            <select class="form-select form-select-sm" name="visibility">
                                <?php foreach (['inherit','public','volunteers','staff','admin_only'] as $v): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $v === (string) $p['visibility'] ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Reject reason (if rejecting)</label>
                            <input type="text" class="form-control form-control-sm" name="rejectionReason" maxlength="500" placeholder="optional">
                        </div>
                        <div class="col-md-3 text-end d-flex gap-2 align-items-end">
                            <button class="btn btn-success btn-sm flex-fill" name="action" value="approve" type="submit">
                                <i class="fa-solid fa-check me-1"></i>Approve
                            </button>
                            <button class="btn btn-outline-danger btn-sm flex-fill" name="action" value="reject" type="submit" data-confirm="Reject this photo? Uploader will be notified.">
                                <i class="fa-solid fa-xmark me-1"></i>Reject
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
