<?php
// Path: _apps/recordings/notes-edit.php
/**
 * -----------------------------------------------------------------------------
 * Recordings — Sermon Notes Editor 📝 (#301)
 * -----------------------------------------------------------------------------
 * Admin-only editor for the Markdown notes attached to a recording. v1 ships
 * Markdown only; PDF + fill-in-the-blanks formats deferred to future PRs.
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
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$recordingId = (int) ($_GET['id'] ?? 0);
$siteId      = Site::id();

$recording = null;
$stmt = $mysqli->prepare(
    'SELECT recordingID, title, scripture FROM tblRecording WHERE recordingID = ? AND siteID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $recordingId, $siteId);
    $stmt->execute();
    $recording = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

if ($recording === null) {
    http_response_code(404);
    exit('Recording not found');
}

$note = null;
$stmt = $mysqli->prepare(
    'SELECT noteID, body, publishedAt FROM tblRecordingNote '
    . 'WHERE recordingID = ? AND format = "markdown" '
    . 'ORDER BY createdAt DESC LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $recordingId);
    $stmt->execute();
    $note = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$pageTitle   = 'Sermon Notes — ' . (string) $recording['title'];
$pageSection = 'recordings';
$csrf        = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <h1 class="h4 mb-1"><i class="fa-solid fa-file-lines me-2 text-primary"></i>Sermon Notes</h1>
    <p class="text-muted">
        <a href="/recordings/view?id=<?php echo $recordingId; ?>"><?php echo htmlspecialchars((string) $recording['title'], ENT_QUOTES, 'UTF-8'); ?></a>
        <?php if ($recording['scripture'] !== null && $recording['scripture'] !== ''): ?>
            &middot; <em><?php echo htmlspecialchars((string) $recording['scripture'], ENT_QUOTES, 'UTF-8'); ?></em>
        <?php endif; ?>
    </p>

    <form method="post" action="/recordings/notes-save">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="recordingID" value="<?php echo $recordingId; ?>">
        <?php if ($note !== null): ?>
            <input type="hidden" name="noteID" value="<?php echo (int) $note['noteID']; ?>">
        <?php endif; ?>

        <div class="mb-3">
            <label for="body" class="form-label">Notes (Markdown)</label>
            <textarea id="body" name="body" class="form-control font-monospace" rows="18"
                      placeholder="## Outline&#10;1. ..."><?php echo htmlspecialchars((string) ($note['body'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            <div class="form-text">
                Standard Markdown — headings, lists, links, code blocks. Scripture
                references like <code>Matt 5:1-12</code> are rendered as plain text
                in v1; auto-linking to the reading-plans lookup is a v2 follow-up.
            </div>
        </div>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="publish" name="publish" value="1"
                   <?php echo $note !== null && $note['publishedAt'] !== null ? 'checked' : ''; ?>>
            <label class="form-check-label" for="publish">
                Publish — make visible to anyone who can see the recording
            </label>
        </div>

        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i> Save notes</button>
        <a href="/recordings/view?id=<?php echo $recordingId; ?>" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
