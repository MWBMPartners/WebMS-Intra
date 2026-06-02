<?php
// Path: public_html/recordings/transcribe.php
/**
 * Recordings — queue a transcription for an existing recording.
 *
 * @package   Portal\Recordings
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Transcription;

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

$db          = App::db();
$siteId      = Site::id();
$recordingId = (int) ($_POST['recordingID'] ?? 0);

// Verify recording belongs to this site.
$ok = false;
$stmt = $db->prepare('SELECT 1 FROM tblRecording WHERE recordingID = ? AND siteID = ?');
if ($stmt !== false) {
    $stmt->bind_param('ii', $recordingId, $siteId);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
}
if ($ok === false) {
    http_response_code(404);
    exit('Recording not found');
}

$settings = App::settings()['transcription'] ?? [];
$provider = (string) ($settings['provider'] ?? 'openai');
if (Transcription::queue($recordingId, $provider) === true) {
    $_SESSION['flash_msg']  = 'Transcription queued — processing happens via /admin/transcription.';
    $_SESSION['flash_type'] = 'success';
} else {
    $_SESSION['flash_msg']  = 'Could not queue transcription.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /recordings/view?id=' . $recordingId);
exit();
