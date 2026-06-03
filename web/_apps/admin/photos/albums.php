<?php
// Path: public_html/admin/photos/albums.php
/**
 * Admin — Album CRUD: name, description, default visibility tier.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/236
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
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
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        $vis  = (string) ($_POST['visibility'] ?? 'staff');
        if (in_array($vis, ['public','volunteers','staff','admin_only'], true) === false) {
            $vis = 'staff';
        }
        if ($name !== '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '');
            $slug = trim($slug, '-');
            if ($slug === '') { $slug = 'album-' . bin2hex(random_bytes(3)); }
            $stmt = $db->prepare('INSERT INTO tblPhotoAlbum (siteID, name, slug, description, visibility, createdByID) VALUES (?, ?, ?, ?, ?, ?)');
            if ($stmt !== false) {
                $stmt->bind_param('issssi', $siteId, $name, $slug, $desc, $vis, $userId);
                $stmt->execute();
                $stmt->close();
            }
            $_SESSION['flash_msg']  = 'Album created.';
            $_SESSION['flash_type'] = 'success';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['albumID'] ?? 0);
        $stmt = $db->prepare('DELETE FROM tblPhotoAlbum WHERE albumID = ? AND siteID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('ii', $id, $siteId);
            $stmt->execute();
            $stmt->close();
        }
        $_SESSION['flash_msg']  = 'Album deleted. Photos retained as orphans for re-assignment.';
        $_SESSION['flash_type'] = 'info';
    }
    header('Location: /admin/photos/albums');
    exit();
}

$rs = $db->query('SELECT a.albumID, a.name, a.slug, a.visibility, COUNT(p.photoID) AS photoCount '
    . 'FROM tblPhotoAlbum a LEFT JOIN tblPhoto p ON p.albumID = a.albumID AND p.status = "approved" '
    . 'WHERE a.siteID = ' . (int) $siteId . ' GROUP BY a.albumID ORDER BY a.name');
$albums = [];
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

$pageTitle   = 'Photo albums';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Photos' => '/photos', 'Albums' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-folder me-2"></i>Photo albums</h1>

<div class="card mb-3"><div class="card-body">
    <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="add">
        <div class="col-md-3">
            <label class="form-label small">Name</label>
            <input type="text" class="form-control form-control-sm" name="name" required maxlength="255">
        </div>
        <div class="col-md-4">
            <label class="form-label small">Description</label>
            <input type="text" class="form-control form-control-sm" name="description" maxlength="500">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Default visibility</label>
            <select class="form-select form-select-sm" name="visibility">
                <option value="public">Public</option>
                <option value="volunteers">Volunteers</option>
                <option value="staff" selected>Staff</option>
                <option value="admin_only">Admin only</option>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add</button>
        </div>
    </form>
</div></div>

<?php if (count($albums) === 0): ?>
    <div class="alert alert-info">No albums yet.</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($albums as $a): ?>
                <div class="row py-2 border-bottom align-items-center">
                    <div class="col-md-4"><strong><?php echo htmlspecialchars((string) $a['name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-md-3"><span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) $a['visibility'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="col-md-3 small text-muted"><?php echo (int) $a['photoCount']; ?> photo<?php echo (int) $a['photoCount'] === 1 ? '' : 's'; ?></div>
                    <div class="col-md-2 text-end">
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="albumID" value="<?php echo (int) $a['albumID']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Delete this album? Photos stay (un-albumed) and can be re-assigned.">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
