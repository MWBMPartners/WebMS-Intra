<?php
// Path: public_html/recordings/stream.php
/**
 * Recordings — Range-aware file stream. Files live under _uploads/recordings/
 * outside the webroot; access is gated by login + site scope.
 *
 * @package   Portal\Recordings
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/264
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Recordings;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_GET['id'] ?? 0);

$rec = null;
$stmt = $db->prepare('SELECT filePath, mimeType FROM tblRecording WHERE recordingID = ? AND siteID = ? AND isPublished = 1 LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $rec = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($rec === null || $rec['filePath'] === null || $rec['filePath'] === '') {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Not found');
}

// 🛡️ Constrain path to the uploads dir — no traversal.
$safeName = basename((string) $rec['filePath']);
$path     = Recordings::uploadDir() . DIRECTORY_SEPARATOR . $safeName;
$mime     = (string) ($rec['mimeType'] ?? 'application/octet-stream');

if (Recordings::streamFile($path, $mime) === false) {
    if (headers_sent() === false) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'Not found';
    }
}
exit();
