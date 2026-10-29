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

$settings = App::settings()['transcription'] ?? [];
$cap      = (int) ($settings['batchSize'] ?? 5);

// 🚧 A `disabled` BUTTON IS NOT A BOUNDARY, and this handler had nothing else.
//
//    WHAT WAS WRONG, found by the independent check. The page draws "Process
//    next" with `disabled` when no transcription company is set up, and that
//    was the only thing standing between a queue and a provider that cannot be
//    called. Anything at all can be posted, so a replayed or hand-made request
//    reached here, claimed every waiting row, got `…-not-configured` back from
//    the provider, and marked the lot FAILED — up to `batchSize` recordings
//    moved out of the queue in one go, each to be found and queued again by
//    hand, for a fault that had nothing to do with any of them.
//
//    It is the reachable case rather than a contrived one: queue some
//    recordings while OpenAI is configured, then switch the company to
//    AssemblyAI without entering its key.
//
//    `providerIsReady()` is the same question the page asks, which is the
//    point — one place decides, both ask it. `transcribe.php` already worked
//    this way and said so in its header; this handler did not, and the
//    principle is no use applied to one of two doors.
if (Transcription::providerIsReady($settings) === false) {
    Logger::activity(
        'TranscriptionRunRefused',
        'No transcription company is set up, so nothing was sent',
        (int) ($_SESSION['user_id'] ?? 0)
    );
    $_SESSION['flash_msg']  = 'Nothing was sent: no transcription company is set up. A global '
        . 'administrator can choose one and enter its key on this page. Everything waiting is '
        . 'still waiting — nothing was marked as failed.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . Site::url('admin/transcription'));
    exit();
}

// 🏢 THIS ORGANISATION'S QUEUE ONLY. It used to be every queued row on the
//    installation, and this page is reachable by an administrator of a single
//    organisation — so pressing the button sent ANOTHER organisation's audio to
//    the provider and charged the installation's one API key for it.
$result = Transcription::processQueue($cap, Site::id());

$done    = (int) $result['done'];
$failed  = (int) $result['failed'];
$skipped = (int) $result['skipped'];
$stopped = $result['stoppedByCap'] === true;

Logger::activity(
    'TranscriptionRun',
    sprintf('Transcribed %d, failed %d, already claimed %d%s', $done, $failed, $skipped, $stopped === true ? ', stopped by the monthly budget' : ''),
    (int) ($_SESSION['user_id'] ?? 0)
);

// 📣 Only what is known. A run that transcribed nothing because the budget ran
//    out reads exactly like one that found nothing to do, so the two are said
//    differently — otherwise an administrator presses the button again and
//    again at a limit nobody mentioned.
$words = sprintf(
    '%d recording%s transcribed, %d could not be done.',
    $done,
    $done === 1 ? '' : 's',
    $failed
);
if ($skipped > 0) {
    $words .= sprintf(' %d had already been claimed by another run.', $skipped);
}
if ($stopped === true) {
    $words .= ' The run stopped because this month\'s transcription budget is used up;'
        . ' anything still waiting stays in the queue.';
}
$_SESSION['flash_msg']  = $words;
$_SESSION['flash_type'] = ($failed > 0 || $stopped === true) ? 'warning' : 'success';
header('Location: ' . Site::url('admin/transcription'));
exit();
