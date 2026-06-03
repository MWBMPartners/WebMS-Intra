<?php
// Path: public_html/photos/album.php
/**
 * Photos — single-album grid. Filters per-photo by viewer tier.
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

$db      = App::db();
$siteId  = Site::id();
$albumId = (int) ($_GET['id'] ?? 0);

$album = null;
$stmt = $db->prepare('SELECT * FROM tblPhotoAlbum WHERE albumID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $albumId, $siteId);
    $stmt->execute();
    $album = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($album === null) {
    http_response_code(404);
    exit('Album not found');
}

$rank = Photos::userTierRank();
if (App::isAdmin() === false && Photos::tierRank((string) $album['visibility']) > $rank) {
    http_response_code(403);
    exit('Album not visible to your role');
}

$rows = [];
$stmt = $db->prepare(
    'SELECT photoID, albumID, uploadedByUserID, filePath, mimeType, caption, visibility, takenAt '
    . 'FROM tblPhoto WHERE albumID = ? AND siteID = ? AND status = "approved" ORDER BY takenAt DESC, photoID DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $albumId, $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        if (Photos::canView($r, $album) === true) {
            $rows[] = $r;
        }
    }
    $stmt->close();
}

$pageTitle   = (string) $album['name'];
$pageSection = 'photos';
$breadcrumbs = ['Dashboard' => '/', 'Photos' => '/photos', (string) $album['name'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><?php echo htmlspecialchars((string) $album['name'], ENT_QUOTES, 'UTF-8'); ?>
    <span class="badge bg-light text-dark ms-2"><?php echo htmlspecialchars((string) $album['visibility'], ENT_QUOTES, 'UTF-8'); ?></span>
</h1>
<?php if (($album['description'] ?? '') !== ''): ?>
    <p class="text-secondary"><?php echo htmlspecialchars((string) $album['description'], ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">No photos in this album yet.</div>
<?php else: ?>
    <div class="row g-2">
        <?php foreach ($rows as $p): ?>
            <div class="col-6 col-md-3">
                <a href="/photos/view?id=<?php echo (int) $p['photoID']; ?>" class="d-block">
                    <img src="/photos/serve?id=<?php echo (int) $p['photoID']; ?>" class="img-fluid rounded" loading="lazy" alt="<?php echo htmlspecialchars((string) ($p['caption'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
