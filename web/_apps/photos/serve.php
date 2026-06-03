<?php
// Path: public_html/photos/serve.php
/**
 * Photos — stream a photo with EXIF stripped (unless viewer is the
 * uploader or an admin, in which case the raw bytes pass through).
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
    exit();
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

// Status check — non-approved photos only visible to uploader + admin.
$uid = (int) ($_SESSION['user_id'] ?? 0);
if ((string) $photo['status'] !== 'approved' && App::isAdmin() === false && $uid !== (int) $photo['uploadedByUserID']) {
    http_response_code(404);
    exit();
}

if (Photos::canView($photo, $album) === false) {
    http_response_code(403);
    exit();
}

$keepExif = Photos::viewerMayKeepExif($photo);
if (Photos::stream($photo, $keepExif === false) === false) {
    http_response_code(404);
}
exit();
