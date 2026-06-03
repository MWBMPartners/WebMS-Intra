<?php
// Path: public_html/recordings/delete.php
/**
 * Recordings — delete record + remove underlying file.
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_POST['recordingID'] ?? 0);

if ($id > 0) {
    $stmt = $db->prepare('SELECT filePath FROM tblRecording WHERE recordingID = ? AND siteID = ? LIMIT 1');
    $filePath = null;
    if ($stmt !== false) {
        $stmt->bind_param('ii', $id, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $filePath = $row['filePath'] ?? null;
    }

    $stmt = $db->prepare('DELETE FROM tblRecording WHERE recordingID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $id, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    if ($filePath !== null && $filePath !== '') {
        $path = Recordings::uploadDir() . DIRECTORY_SEPARATOR . basename((string) $filePath);
        if (is_file($path) === true) {
            @unlink($path);
        }
    }

    $_SESSION['flash_msg']  = 'Recording deleted.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: /recordings/manage');
exit();
