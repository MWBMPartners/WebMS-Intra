<?php
// Path: _apps/recordings/transcribe.php
/**
 * -----------------------------------------------------------------------------
 * Recordings — queue a recording for transcription 🗒
 * -----------------------------------------------------------------------------
 * This handler already existed, already worked, and was UNREACHABLE: its
 * address was registered and no page anywhere posted to it, so no recording had
 * ever been queued and `/recordings/search` searched an empty table. The button
 * that reaches it now lives on `recordings/view`.
 *
 * WHAT WAS ADDED HERE WHEN THE BUTTON WENT IN
 * -----------------------------------------------------------------------------
 * The page decides whether to DRAW a button. This file decides whether to ACT,
 * and the two are not the same thing: a hidden button is a courtesy to somebody
 * reading the page, never a boundary, because anything at all can be posted.
 * So every reason the page would not offer the button is tested again here, and
 * the person is told which one applied.
 *
 * `Transcription::whyNotQueueable()` is the ONE place those reasons are decided
 * and `refusalWords()` the ONE place they are put into words. Do not write a
 * second test in a page: it would drift from this one, and the drift would show
 * up as a button that does nothing.
 *
 * ASKED TWICE, ON PURPOSE
 * -----------------------------------------------------------------------------
 * The question is asked here on facts read a moment ago, and `queue()` asks the
 * one racy part of it AGAIN inside a row lock. Only "is it with the provider
 * right now" can change between the two, and that is the one that costs money
 * to get wrong, so it is settled where it cannot race rather than here. The
 * same shape as `ExpenseRules::whyNotEditable()`, for the same reason.
 *
 * @package   Portal\Recordings
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\InstallationSettings;
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

$db          = App::db();
$siteId      = Site::id();
$recordingId = (int) ($_POST['recordingID'] ?? 0);

// 🛡️ The recording has to be THIS organisation's. Read in one go with the two
//    facts the decision needs, so nothing is fetched twice and the answer below
//    is about the same row throughout.
//
//    A recording that is not found and one that belongs to another organisation
//    get the SAME answer, deliberately: telling the difference would confirm to
//    a stranger that a particular recording exists somewhere on the
//    installation.
$rec  = null;
$stmt = $db->prepare(
    'SELECT recordingID, title, filePath, externalUrl FROM tblRecording '
    . 'WHERE recordingID = ? AND siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $recordingId, $siteId);
    $stmt->execute();
    $rec = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($rec === null) {
    http_response_code(404);
    exit('Recording not found');
}

$settings = App::settings()['transcription'] ?? [];
$state    = Transcription::stateFor($recordingId, $siteId);

// 🎚️ Every reason the page would have withheld the button, asked again.
$reason = Transcription::whyNotQueueable([
    // The app switch is read through AppRegistry, which is the one place that
    // decides whether an app is on for this organisation. Reading
    // `transcription.enabled` here instead would be a second version of that
    // rule — and the setting ships OFF, while `admin/transcription` is gated on
    // the same switch by the router. Without this test an administrator could
    // queue work on an installation where the only page that can drain the
    // queue is unreachable.
    'appIsOn'       => AppRegistry::isEnabled('transcription'),
    // A recording that only links out to somebody else's website has no file
    // here to send. Queueing it would fail with 'no-file' and read to a
    // customer as though the recording were at fault.
    'hasMedia'      => ($rec['filePath'] !== null && (string) $rec['filePath'] !== ''),
    'providerReady' => Transcription::providerIsReady($settings),
    'stillRunning'  => $state !== null && $state['stillRunning'] === true,
    // 💳 The installation's own layer, read ON ITS OWN: for a limit the
    //    installation's value caps the organisation's, and the merged
    //    $SETTINGS cannot tell the two apart (#668).
    'capPence'      => Transcription::capFrom(App::settings(), InstallationSettings::layer()),
    'spentPence'    => Transcription::monthSpendPence($siteId),
]);

if ($reason !== '') {
    $_SESSION['flash_msg']  = Transcription::refusalWords($reason);
    $_SESSION['flash_type'] = $reason === 'busy' ? 'info' : 'warning';

    // 📋 ONLY THE REFUSALS THAT MEAN SOMETHING ARE RECORDED.
    //
    //    This used to record every one, justified by a comment about a party
    //    asking for more than the installation allows — which is true of two
    //    of the five reasons and of none of the others. `no-media` in
    //    particular is reached by an administrator opening any recording that
    //    only links elsewhere, so it would fill the log with lines nobody can
    //    act on, and a log nobody reads is where a real entry goes unseen.
    //
    //    Kept: asking to spend past what the installation allows (a line
    //    between two parties), and the double-bill guard firing (money, and
    //    the one case where a refusal means something was nearly charged
    //    twice). Not kept: the app being switched off, no company set up, and
    //    a recording with nothing to send — all three are plainly visible on
    //    the page itself.
    //
    //    The REASON is recorded, never the recording's title: a title can name
    //    a person.
    $worthRecording = ['over-cap', 'no-budget', 'busy'];
    if (in_array($reason, $worthRecording, true) === true) {
        Logger::activity('TranscriptionRefused', 'Recording ' . $recordingId . ' — ' . $reason);
    }

    header('Location: ' . Site::url('recordings/view') . '?id=' . $recordingId);
    exit();
}

$provider = (string) ($settings['provider'] ?? 'openai');
$outcome  = Transcription::queue($recordingId, $provider);

// 📣 What the person is told, and nothing more than is known. "Queued" does not
//    mean "transcribed": nothing drains the queue by itself, so the sentence
//    says who has to press what next. Saying "your transcript is on its way"
//    would be untrue on an installation where nobody ever opens that page.
$queuePage = Site::url('admin/transcription');
switch ($outcome) {
    case 'queued':
    case 'requeued':
        $_SESSION['flash_msg'] = ($outcome === 'requeued'
            ? 'This recording has been put back in the queue for transcription. '
            : 'This recording is now waiting to be transcribed. ')
            . 'Nothing is sent to the transcription company until an administrator presses '
            . '"Process next" on the Transcription page (' . $queuePage . ').';
        $_SESSION['flash_type'] = 'success';
        Logger::activity('TranscriptionQueued', 'Recording ' . $recordingId . ' — ' . $outcome . ', provider ' . $provider);
        break;

    case 'busy':
        // Reached when the sweep claimed the row between the test above and the
        // lock inside queue(). This is the race that costs money, and it is
        // caught where it can be: a refusal here is the guard working.
        $_SESSION['flash_msg']  = Transcription::refusalWords('busy');
        $_SESSION['flash_type'] = 'info';
        Logger::activity('TranscriptionRefused', 'Recording ' . $recordingId . ' — busy');
        break;

    default:
        $_SESSION['flash_msg']  = 'This recording could not be put in the queue. Nothing was changed.';
        $_SESSION['flash_type'] = 'danger';
        Logger::activity('TranscriptionQueueFailed', 'Recording ' . $recordingId);
        break;
}

header('Location: ' . Site::url('recordings/view') . '?id=' . $recordingId);
exit();
