<?php
// Path: public_html/photos/serve-raw.php
/**
 * Photos — raw file with EXIF intact. Uploader or admin only.
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

$uid = (int) ($_SESSION['user_id'] ?? 0);
if (App::isAdmin() === false && $uid !== (int) $photo['uploadedByUserID']) {
    http_response_code(403);
    exit();
}

$origName = (string) ($photo['originalFilename'] ?? 'photo');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $origName) . '"');
if (Photos::stream($photo, false) === false) {
    http_response_code(404);
}
exit();
