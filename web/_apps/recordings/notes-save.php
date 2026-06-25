<?php
// Path: _apps/recordings/notes-save.php
/**
 * -----------------------------------------------------------------------------
 * Recordings — Save Sermon Notes 📝 (#301)
 * -----------------------------------------------------------------------------
 * POST endpoint. INSERT or UPDATE a markdown note attached to a recording.
 * Admin-only, CSRF-checked.
 *
 * @package   Portal\Recordings
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/301
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('RecordingNoteSaveRejected', 'Invalid CSRF on notes-save');
    http_response_code(400);
    exit('Bad request');
}

$recordingId = (int) ($_POST['recordingID'] ?? 0);
$noteId      = (int) ($_POST['noteID'] ?? 0);
$body        = trim((string) ($_POST['body'] ?? ''));
$shouldPublish     = (string) ($_POST['publish'] ?? '') === '1';
$userId      = (int) ($_SESSION['user_id'] ?? 0);
$siteId      = Site::id();

if ($recordingId <= 0) {
    http_response_code(400);
    exit('Missing recordingID');
}

// 🛡️ Confirm the recording exists in THIS site (cross-site write guard).
$stmt = $mysqli->prepare('SELECT recordingID FROM tblRecording WHERE recordingID = ? AND siteID = ?');
if ($stmt === false) {
    http_response_code(500);
    exit('DB error');
}
$stmt->bind_param('ii', $recordingId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) {
    http_response_code(404);
    exit('Recording not found');
}

// 🛡️ Static SQL — branch by $shouldPublish chooses one of two fully-formed
//    prepared statements, no string interpolation into the SET / VALUES body.
if ($noteId > 0) {
    $sql = $shouldPublish === true
        ? 'UPDATE tblRecordingNote SET body = ?, publishedAt = IFNULL(publishedAt, NOW()) WHERE noteID = ? AND recordingID = ?'
        : 'UPDATE tblRecordingNote SET body = ?, publishedAt = NULL WHERE noteID = ? AND recordingID = ?';
    $stmt = $mysqli->prepare($sql);
    if ($stmt !== false) {
        $stmt->bind_param('sii', $body, $noteId, $recordingId);
        $stmt->execute();
        $stmt->close();
    }
} else {
    $sql = $shouldPublish === true
        ? 'INSERT INTO tblRecordingNote (recordingID, format, body, publishedAt, createdByID) VALUES (?, "markdown", ?, NOW(), ?)'
        : 'INSERT INTO tblRecordingNote (recordingID, format, body, publishedAt, createdByID) VALUES (?, "markdown", ?, NULL, ?)';
    $stmt = $mysqli->prepare($sql);
    if ($stmt !== false) {
        $stmt->bind_param('isi', $recordingId, $body, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

Logger::activity('RecordingNoteSaved', 'Recording #' . $recordingId . ', publish=' . ($shouldPublish === true ? 'yes' : 'no'));

header('Location: /recordings/view?id=' . $recordingId, true, 303);
exit();
