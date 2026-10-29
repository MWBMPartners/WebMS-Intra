<?php
// Path: public_html/recordings/view.php
/**
 * Recordings — playback page. HTML5 player with seek (uses /recordings/stream
 * with Range support). Logs a play event on first view per session.
 *
 * @package   Portal\Recordings
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/264
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\InstallationSettings;
use Portal\Core\Markdown;
use Portal\Core\Recordings;
use Portal\Core\Site;
use Portal\Core\Transcription;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_GET['id'] ?? 0);

$rec = null;
$stmt = $db->prepare(
    'SELECT r.*, u.fullName AS presenterName '
    . 'FROM tblRecording r LEFT JOIN tblUsers u ON u.userID = r.presenterID '
    . 'WHERE r.recordingID = ? AND r.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $rec = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($rec === null) {
    http_response_code(404);
    exit('Recording not found');
}

// 📊 Log a play (once per session per recording).
$playKey = 'rec_play_' . $id;
if (isset($_SESSION[$playKey]) === false) {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $pStmt = $db->prepare('INSERT INTO tblRecordingPlay (recordingID, userID, ipHash) VALUES (?, ?, ?)');
    if ($pStmt !== false) {
        $uidOrNull = $userId > 0 ? $userId : null;
        $pStmt->bind_param('iis', $id, $uidOrNull, $ipHash);
        $pStmt->execute();
        $pStmt->close();
    }
    $_SESSION[$playKey] = 1;
}

// 🗒 TRANSCRIPTION (#276). The button below is the ONLY thing anywhere that
//    reaches `recordings/transcribe`. Until 29 October 2026 there was none, so
//    the handler and its registered address sat unreachable, no recording was
//    ever queued, and `/recordings/search` searched an empty table for ever.
//
//    `Transcription::whyNotQueueable()` is the ONE place that decides whether a
//    recording may be queued, and the handler asks it again — this page's copy
//    only decides what to DRAW. A hidden button is a courtesy, not a boundary.
$canQueue        = App::isAdmin() === true;
$transcriptionOn = AppRegistry::isEnabled('transcription');
$tState          = Transcription::stateFor($id, $siteId);
$tReadable       = $tState !== null && $tState['status'] === 'completed' && $tState['hasText'] === true;
$tReason         = '';
if ($canQueue === true) {
    $tSettings = App::settings()['transcription'] ?? [];
    $tReason   = Transcription::whyNotQueueable([
        'appIsOn'       => $transcriptionOn,
        'hasMedia'      => ($rec['filePath'] !== null && (string) $rec['filePath'] !== ''),
        'providerReady' => Transcription::providerIsReady($tSettings),
        'stillRunning'  => $tState !== null && $tState['stillRunning'] === true,
        // 💳 The installation's layer on its own: for a limit the
        //    installation's value caps the organisation's (#668).
        'capPence'      => Transcription::capFrom(App::settings(), InstallationSettings::layer()),
        'spentPence'    => Transcription::monthSpendPence($siteId),
    ]);
}

// 🎴 Whether the card appears at all.
//
//    A recording that only links out to another website can NEVER be
//    transcribed here, so 'no-media' shows nothing rather than putting an
//    explanation an administrator cannot act on under every such recording.
//    Every other reason is something somebody can fix, so it is said.
$tShowCard = $tReadable === true
    || ($canQueue === true && $tState !== null)
    || ($canQueue === true && $transcriptionOn === true && $tReason !== 'no-media');

// 📣 The flash message. THIS PAGE HAD NO FLASH BLOCK, and nothing in this
//    portal shows one centrally: 141 pages print their own flash and nothing
//    in `header.php` or `footer.php` does it for them. So
//    `recordings/transcribe` redirected back here with a message and it was
//    swallowed every time — the button would have looked as though it did
//    nothing at all, whether it worked or was refused.
$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$presenter = (string) ($rec['presenterName'] ?? '') !== ''
    ? (string) $rec['presenterName']
    : (string) ($rec['presenterText'] ?? '');
$isVideo = strpos((string) ($rec['mimeType'] ?? ''), 'video/') === 0;
$external = (string) ($rec['externalUrl'] ?? '');

$pageTitle   = (string) $rec['title'];
$pageSection = 'recordings';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Recordings' => Site::url('recordings'), (string) $rec['title'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-2"><?php echo htmlspecialchars((string) $rec['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
<p class="text-secondary mb-4">
    <span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) $rec['kind'], ENT_QUOTES, 'UTF-8'); ?></span>
    <?php if ($presenter !== ''): ?>· <?php echo htmlspecialchars($presenter, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    <?php if ($rec['recordedAt'] !== null): ?>· <?php echo htmlspecialchars(date('l, j F Y', (int) strtotime((string) $rec['recordedAt'])), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    <?php if ($rec['durationSeconds'] !== null): ?>· <?php echo htmlspecialchars(Recordings::formatDuration((int) $rec['durationSeconds']), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
</p>

<div class="card mb-4">
    <div class="card-body">
        <?php if ($external !== ''): ?>
            <p><a href="<?php echo htmlspecialchars($external, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Open external recording
            </a></p>
        <?php elseif ($rec['filePath'] !== null): ?>
            <?php if ($isVideo === true): ?>
                <video controls preload="metadata" class="w-100" style="max-height:480px;">
                    <source src="<?php echo htmlspecialchars(Site::url('recordings/stream'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $rec['recordingID']; ?>" type="<?php echo htmlspecialchars((string) $rec['mimeType'], ENT_QUOTES, 'UTF-8'); ?>">
                </video>
            <?php else: ?>
                <audio controls preload="metadata" class="w-100">
                    <source src="<?php echo htmlspecialchars(Site::url('recordings/stream'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $rec['recordingID']; ?>" type="<?php echo htmlspecialchars((string) ($rec['mimeType'] ?? 'audio/mpeg'), ENT_QUOTES, 'UTF-8'); ?>">
                </audio>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-muted">No media file or external URL is attached to this recording.</p>
        <?php endif; ?>
    </div>
</div>

<?php // 🗒 Transcript — the card that makes transcription reachable at all. ?>
<?php if ($tShowCard === true): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-closed-captioning me-2"></i>Transcript</span>
                <?php if ($tState !== null): ?>
                    <span class="badge bg-<?php
                        echo htmlspecialchars(match ((string) $tState['status']) {
                            'completed'  => 'success',
                            'processing' => 'info',
                            'failed'     => 'danger',
                            default      => 'secondary',
                        }, ENT_QUOTES, 'UTF-8');
                    ?>"><?php echo htmlspecialchars(Transcription::statusWords((string) $tState['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </h2>

            <?php if ($tReadable === true): ?>
                <p class="mb-2">
                    <a class="btn btn-outline-primary btn-sm"
                       href="<?php echo htmlspecialchars(Site::url('recordings/transcript'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $rec['recordingID']; ?>">
                        <i class="fa-solid fa-book-open me-1"></i>Read the transcript
                    </a>
                </p>
            <?php elseif ($tState === null): ?>
                <p class="text-muted small mb-2">This recording has not been transcribed.</p>
            <?php elseif ((string) $tState['status'] === 'queued'): ?>
                <p class="text-muted small mb-2">
                    Waiting in the queue. Nothing is sent to the transcription company until an
                    administrator presses &ldquo;Process next&rdquo; on the Transcription page.
                </p>
            <?php elseif ($tState['stillRunning'] === true): ?>
                <p class="text-muted small mb-2">The audio is with the transcription company now. This can take several minutes.</p>
            <?php elseif ((string) $tState['status'] === 'processing'): ?>
                <?php // ⏱️ `processing` but started too long ago to still be running — the shape
                      //    a shared host leaves behind when it kills a long request. Said plainly,
                      //    because such a row looks busy for ever and is the one thing that
                      //    explains a recording that never finishes. ?>
                <p class="text-muted small mb-2">
                    This was sent to the transcription company but never came back &mdash; the
                    request was most likely cut short by the server. It can be sent again.
                </p>
            <?php else: ?>
                <p class="text-muted small mb-2">
                    The last attempt did not produce a transcript.
                    <?php if ($canQueue === true && $tState['errorMsg'] !== null): ?>
                        <?php // 🔒 The provider's own words are shown to an ADMINISTRATOR only.
                              //    A fault's message can quote what was sent, and this card is
                              //    read by every member who can see the recording. ?>
                        <br><span class="text-secondary">Reported reason: <?php echo htmlspecialchars((string) $tState['errorMsg'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($canQueue === true): ?>
                <?php if ($tReason !== ''): ?>
                    <p class="text-muted small mb-0"><i class="fa-solid fa-circle-info me-1"></i><?php echo htmlspecialchars(Transcription::refusalWords($tReason), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php else: ?>
                    <?php
                    // 💸 WHY THERE IS NO "ARE YOU SURE" BOX. Transcription costs money, and a
                    //    second press after one that already finished is charged again — so a
                    //    confirmation box looks like the obvious thing to add. It is not what
                    //    makes this safe, and adding it would need a script carrying this
                    //    answer's nonce for the sake of something the server already handles:
                    //    `tblTranscript` has one row per recording, so pressing twice while a
                    //    job is waiting leaves ONE waiting job, and pressing while the audio
                    //    is with the provider is refused outright by the lock in
                    //    `Transcription::queue()`. The warning below is there so nobody is
                    //    surprised by a bill, not to stop a double click.
                    $tAgain = $tState !== null;
                    ?>
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('recordings/transcribe'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="recordingID" value="<?php echo (int) $rec['recordingID']; ?>">
                        <button class="btn btn-primary btn-sm" type="submit">
                            <i class="fa-solid fa-closed-captioning me-1"></i><?php echo $tAgain === true ? 'Transcribe again' : 'Transcribe this recording'; ?>
                        </button>
                        <div class="form-text">
                            <?php // 🔐 Said on the button, not buried in a help page: the audio
                                  //    LEAVES THIS SERVER. A recording of a pastoral talk or a
                                  //    members' meeting goes to a third party, and whoever presses
                                  //    this should know that before they do, not afterwards. ?>
                            The audio is sent to the transcription company your administrator has chosen, which charges for it<?php echo $tAgain === true ? ', including each time it is sent again' : ''; ?>.
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (($rec['scripture'] ?? '') !== '' || ($rec['topics'] ?? '') !== ''): ?>
    <div class="mb-4 small">
        <?php if (($rec['scripture'] ?? '') !== ''): ?>
            <div><strong>Scripture:</strong> <?php echo htmlspecialchars((string) $rec['scripture'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (($rec['topics'] ?? '') !== ''): ?>
            <div class="mt-1"><strong>Topics:</strong>
                <?php foreach (array_filter(array_map('trim', explode(',', (string) $rec['topics']))) as $t): ?>
                    <a href="<?php echo htmlspecialchars(Site::url('recordings'), ENT_QUOTES, 'UTF-8'); ?>?topic=<?php echo urlencode($t); ?>" class="badge bg-secondary text-decoration-none me-1"><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (($rec['summary'] ?? '') !== ''): ?>
    <div class="card">
        <div class="card-body">
            <?php echo Markdown::render((string) $rec['summary'], ['allow_links' => true]); ?>
        </div>
    </div>
<?php endif; ?>

<?php
// 📝 Sermon notes (#569).
//
//    These were WRITTEN AND NEVER SHOWN. `recordings/notes-edit.php` and
//    `notes-save.php` have been storing notes in `tblRecordingNote` for some
//    time, and no page anywhere read that table — this page showed only
//    `summary`. So an administrator could write a set of notes, publish them,
//    and no member could ever see them.
//
//    WHO SEES WHAT. `publishedAt` is the rule the table already states in its
//    own column comment: NULL means an unpublished draft that only its author
//    and administrators see. That is honoured here rather than reinvented.
//
//    Only `markdown` notes are shown, because only markdown is ever written:
//    `notes-save.php` hard-codes that format, and nothing in the portal serves
//    a file from `_uploads/recording-notes/`. The other two values of the
//    `format` column are unused, so showing a "download" for them would be a
//    link to nothing.
$notesCanEdit = App::isAdmin() === true;
$viewerId     = (int) ($_SESSION['user_id'] ?? 0);
$notes        = [];
$stmt = $db->prepare(
    'SELECT noteID, body, publishedAt FROM tblRecordingNote '
    . "WHERE recordingID = ? AND format = 'markdown' AND body IS NOT NULL AND body <> '' "
    . '  AND (publishedAt IS NOT NULL OR createdByID = ? OR ? = 1) '
    . 'ORDER BY publishedAt IS NULL, noteID'
);
if ($stmt !== false) {
    $isAdminFlag = $notesCanEdit === true ? 1 : 0;
    $recordingId = (int) $rec['recordingID'];
    $stmt->bind_param('iii', $recordingId, $viewerId, $isAdminFlag);
    $stmt->execute();
    $rows = $stmt->get_result();
    while (($row = $rows->fetch_assoc()) !== null) {
        $notes[] = $row;
    }
    $stmt->close();
}
?>
<?php if ($notes !== [] || $notesCanEdit === true): ?>
    <div class="card mt-4">
        <div class="card-body">
            <h2 class="h5 d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-note-sticky me-2"></i>Notes</span>
                <?php if ($notesCanEdit === true): ?>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="<?php echo htmlspecialchars(Site::url('recordings/notes-edit'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $rec['recordingID']; ?>">
                        <?php echo $notes === [] ? 'Add notes' : 'Edit notes'; ?>
                    </a>
                <?php endif; ?>
            </h2>
            <?php if ($notes === []): ?>
                <p class="text-muted small mb-0">No notes yet for this recording.</p>
            <?php else: ?>
                <?php foreach ($notes as $note): ?>
                    <?php if ($note['publishedAt'] === null): ?>
                        <div class="alert alert-secondary py-1 px-2 small mb-2">
                            <i class="fa-solid fa-pen me-1"></i>Draft &mdash; only you and administrators can see this.
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <?php echo Markdown::render((string) $note['body'], ['allow_links' => true]); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
