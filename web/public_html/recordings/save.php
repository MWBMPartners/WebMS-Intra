<?php
// Path: public_html/recordings/save.php
/**
 * Recordings — edit save handler.
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

$title       = trim((string) ($_POST['title'] ?? ''));
$presenter   = trim((string) ($_POST['presenterText'] ?? ''));
$recordedAt  = (string) ($_POST['recordedAt'] ?? '');
$duration    = (int) ($_POST['durationSeconds'] ?? 0);
$kind        = (string) ($_POST['kind'] ?? 'sermon');
$scripture   = trim((string) ($_POST['scripture'] ?? ''));
$topics      = trim((string) ($_POST['topics'] ?? ''));
$summary     = (string) ($_POST['summary'] ?? '');
$externalUrl = trim((string) ($_POST['externalUrl'] ?? ''));
$published   = isset($_POST['isPublished']) === true ? 1 : 0;

if (in_array($kind, ['sermon','teaching','music','event','other'], true) === false) {
    $kind = 'other';
}
if ($externalUrl !== '' && filter_var($externalUrl, FILTER_VALIDATE_URL) === false) {
    $externalUrl = '';
}

if ($id > 0 && $title !== '') {
    $stmt = $db->prepare(
        'UPDATE tblRecording SET '
        . 'title = ?, presenterText = ?, recordedAt = ?, durationSeconds = ?, kind = ?, '
        . 'scripture = ?, topics = ?, summary = ?, externalUrl = ?, isPublished = ? '
        . 'WHERE recordingID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $recordedOrNull = $recordedAt !== '' && strtotime($recordedAt) !== false ? $recordedAt : null;
        $durOrNull      = $duration > 0 ? $duration : null;
        $extOrNull      = $externalUrl !== '' ? $externalUrl : null;
        $stmt->bind_param(
            'sssisssssiii',
            $title, $presenter, $recordedOrNull, $durOrNull, $kind,
            $scripture, $topics, $summary, $extOrNull, $published,
            $id, $siteId
        );
        $stmt->execute();
        $stmt->close();
    }
    Recordings::syncTopics($siteId, $topics);
    $_SESSION['flash_msg']  = 'Recording updated.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: /recordings/manage');
exit();
