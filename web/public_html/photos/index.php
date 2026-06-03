<?php
// Path: public_html/photos/index.php
/**
 * Photos — album gallery list. Filters by viewer's tier.
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

$rank = Photos::userTierRank();
$rs = $db->query(
    'SELECT albumID, name, slug, description, visibility, coverPhotoID FROM tblPhotoAlbum '
    . 'WHERE siteID = ' . (int) $siteId . ' ORDER BY name'
);
$albums = [];
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        if (Photos::tierRank((string) $r['visibility']) <= $rank || App::isAdmin() === true) {
            $albums[] = $r;
        }
    }
    $rs->free();
}

$pageTitle   = 'Photos';
$pageSection = 'photos';
$breadcrumbs = ['Dashboard' => '/', 'Photos' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0"><i class="fa-solid fa-images me-2"></i>Photos</h1>
    <div class="d-flex gap-2">
        <?php if (Auth::check() === true): ?>
            <a class="btn btn-outline-primary btn-sm" href="/photos/upload"><i class="fa-solid fa-upload me-1"></i>Upload</a>
        <?php endif; ?>
        <?php if (App::isAdmin() === true): ?>
            <a class="btn btn-outline-warning btn-sm" href="/admin/photos/queue"><i class="fa-solid fa-clock me-1"></i>Moderation queue</a>
            <a class="btn btn-outline-secondary btn-sm" href="/admin/photos/albums">Albums</a>
        <?php endif; ?>
    </div>
</div>

<?php if (count($albums) === 0): ?>
    <div class="alert alert-info">No albums visible to you yet.</div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($albums as $a): ?>
            <div class="col-md-4">
                <div class="card h-100">
                    <?php if ($a['coverPhotoID'] !== null): ?>
                        <a href="/photos/album?id=<?php echo (int) $a['albumID']; ?>">
                            <img src="/photos/serve?id=<?php echo (int) $a['coverPhotoID']; ?>" class="card-img-top" style="max-height:180px;object-fit:cover;" alt="">
                        </a>
                    <?php endif; ?>
                    <div class="card-body">
                        <h5><a href="/photos/album?id=<?php echo (int) $a['albumID']; ?>" class="text-decoration-none"><?php echo htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8'); ?></a></h5>
                        <p class="small text-muted mb-0">
                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) $a['visibility'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
