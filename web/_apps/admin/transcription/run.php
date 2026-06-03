<?php
// Path: public_html/admin/transcription/run.php
/**
 * Admin — Drain the transcription queue (manual trigger; cron can hit
 * this same endpoint with a session token in the future).
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
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

$settings = App::settings()['transcription'] ?? [];
$cap      = (int) ($settings['batchSize'] ?? 5);
$result   = Transcription::processQueue($cap);

Logger::activity('TranscriptionRun', sprintf('Processed %d, failed %d', (int) $result['done'], (int) $result['failed']), (int) ($_SESSION['user_id'] ?? 0));

$_SESSION['flash_msg']  = sprintf('Processed %d transcript(s), %d failed.', (int) $result['done'], (int) $result['failed']);
$_SESSION['flash_type'] = (int) $result['failed'] > 0 ? 'warning' : 'success';
header('Location: /admin/transcription');
exit();
