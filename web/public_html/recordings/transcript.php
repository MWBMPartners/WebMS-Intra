<?php
// Path: public_html/recordings/transcript.php
/**
 * Recordings — transcript view for a single recording. Segments are
 * rendered as click-to-seek anchors that drive the audio/video player
 * back on /recordings/view via a query-string hash.
 *
 * @package   Portal\Recordings
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_GET['id'] ?? 0);

$row = null;
$stmt = $db->prepare(
    'SELECT t.fullText, t.jsonSegments, t.status, t.language, t.generatedAt, t.errorMsg, '
    . '       r.title '
    . 'FROM tblTranscript t INNER JOIN tblRecording r ON r.recordingID = t.recordingID '
    . 'WHERE t.recordingID = ? AND r.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$segments = [];
if ($row !== null && $row['jsonSegments'] !== null) {
    $decoded = json_decode((string) $row['jsonSegments'], true);
    if (is_array($decoded) === true) {
        $segments = $decoded;
    }
}

$pageTitle   = 'Transcript';
$pageSection = 'recordings';
$breadcrumbs = ['Dashboard' => '/', 'Recordings' => '/recordings', 'Transcript' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-closed-captioning me-2"></i>Transcript — <?php echo htmlspecialchars((string) ($row['title'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?></h1>

<?php if ($row === null): ?>
    <div class="alert alert-info">No transcript exists for this recording yet. Admin can queue one from the recording's page.</div>
<?php elseif ((string) $row['status'] !== 'completed'): ?>
    <div class="alert alert-warning">
        Transcript status: <strong><?php echo htmlspecialchars((string) $row['status'], ENT_QUOTES, 'UTF-8'); ?></strong>.
        <?php if ($row['errorMsg'] !== null): ?>
            Last error: <?php echo htmlspecialchars((string) $row['errorMsg'], ENT_QUOTES, 'UTF-8'); ?>.
        <?php endif; ?>
    </div>
<?php else: ?>
    <p class="text-muted small">Generated <?php echo htmlspecialchars((string) ($row['generatedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        · Language <?php echo htmlspecialchars((string) ($row['language'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>

    <p><a href="/recordings/view?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm">← Back to player</a></p>

    <div class="card">
        <div class="card-body" style="line-height:1.7;">
            <?php if (count($segments) > 0): ?>
                <?php foreach ($segments as $s):
                    $start = (float) ($s['start'] ?? 0);
                    $text  = (string) ($s['text']  ?? '');
                    $h = (int) floor($start / 3600);
                    $m = (int) floor(($start % 3600) / 60);
                    $sec = (int) floor($start % 60);
                    $ts = ($h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec));
                ?>
                    <a href="/recordings/view?id=<?php echo $id; ?>&t=<?php echo (int) $start; ?>#t=<?php echo (int) $start; ?>"
                       class="text-decoration-none text-muted small me-1"><?php echo htmlspecialchars($ts, ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?php echo nl2br(htmlspecialchars((string) ($row['fullText'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
