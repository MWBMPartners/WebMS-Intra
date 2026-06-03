<?php
// Path: public_html/photos/view.php
/**
 * Photos — single-photo page. Renders the EXIF-stripped image; admin
 * sees a "View metadata" panel + link to the raw EXIF download.
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

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_GET['id'] ?? 0);

$photo = null;
$stmt = $db->prepare('SELECT * FROM tblPhoto WHERE photoID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $photo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($photo === null) {
    http_response_code(404);
    exit('Photo not found');
}

$album = null;
if ($photo['albumID'] !== null) {
    $stmt = $db->prepare('SELECT * FROM tblPhotoAlbum WHERE albumID = ? LIMIT 1');
    if ($stmt !== false) {
        $aid = (int) $photo['albumID'];
        $stmt->bind_param('i', $aid);
        $stmt->execute();
        $album = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
if ((string) $photo['status'] !== 'approved' && App::isAdmin() === false && (int) ($_SESSION['user_id'] ?? 0) !== (int) $photo['uploadedByUserID']) {
    http_response_code(404);
    exit('Photo not visible');
}
if (Photos::canView($photo, $album) === false) {
    http_response_code(403);
    exit('Not visible to your role');
}

$isUploader = (int) ($_SESSION['user_id'] ?? 0) === (int) $photo['uploadedByUserID'];
$showExif   = App::isAdmin() === true || $isUploader === true;
$exif       = $showExif === true ? Photos::readExif($photo) : [];

$pageTitle   = 'Photo';
$pageSection = 'photos';
$breadcrumbs = ['Dashboard' => '/', 'Photos' => '/photos'];
if ($album !== null) {
    $breadcrumbs[(string) $album['name']] = '/photos/album?id=' . (int) $album['albumID'];
}
$breadcrumbs['Photo'] = '';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="text-center mb-3">
    <img src="/photos/serve?id=<?php echo $id; ?>" class="img-fluid rounded" style="max-height:75vh;" alt="<?php echo htmlspecialchars((string) ($photo['caption'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
</div>

<?php if (($photo['caption'] ?? '') !== ''): ?>
    <p class="text-center"><?php echo htmlspecialchars((string) $photo['caption'], ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<?php if ($showExif === true): ?>
    <div class="card mt-3"><div class="card-body">
        <h5><i class="fa-solid fa-info-circle me-1"></i>Metadata</h5>
        <p class="small text-muted">EXIF visible because you are the uploader or an admin. Non-privileged viewers receive an EXIF-stripped copy.</p>
        <p>
            <a class="btn btn-outline-primary btn-sm" href="/photos/serve-raw?id=<?php echo $id; ?>">
                <i class="fa-solid fa-download me-1"></i>Download with full EXIF
            </a>
        </p>
        <?php if (count($exif) > 0): ?>
            <pre class="small bg-body-tertiary p-2 rounded" style="max-height:240px;overflow:auto;"><?php
                foreach ($exif as $section => $vals) {
                    if (is_array($vals) === false) { continue; }
                    echo "[" . htmlspecialchars((string) $section, ENT_QUOTES, 'UTF-8') . "]\n";
                    foreach ($vals as $k => $v) {
                        if (is_scalar($v) === true) {
                            echo '  ' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . ' = '
                                . htmlspecialchars(mb_substr((string) $v, 0, 200), ENT_QUOTES, 'UTF-8') . "\n";
                        }
                    }
                }
            ?></pre>
        <?php else: ?>
            <p class="text-muted small mb-0">No EXIF read.</p>
        <?php endif; ?>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
