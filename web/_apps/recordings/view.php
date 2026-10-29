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
use Portal\Core\Auth;
use Portal\Core\Markdown;
use Portal\Core\Recordings;
use Portal\Core\Site;

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
