<?php
// _apps/worship/song.php — View / edit single song (#309)
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Settings;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$isNew  = ($_GET['new'] ?? '') === '1';
$songId = (int) ($_GET['id'] ?? 0);
$song   = null;

if ($isNew === true) {
    if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }
    $song = ['songID' => 0, 'title' => '', 'author' => '', 'ccliNumber' => '', 'copyrightLine' => '', 'defaultKey' => '', 'defaultTempo' => '', 'lyrics' => '', 'tags' => ''];
} elseif ($songId > 0) {
    $stmt = $mysqli->prepare('SELECT * FROM tblSongs WHERE songID = ? AND siteID = ? AND isActive = 1');
    $stmt->bind_param('ii', $songId, $siteId);
    $stmt->execute();
    $song = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}
if ($song === null) { http_response_code(404); exit('Song not found'); }

$pageTitle = ($isNew === true) ? 'New song' : (string) $song['title'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$ccliAcct = (string) Settings::get('worship.ccli_account_number', '');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:720px;">
    <h1 class="h4 mb-3"><i class="fa-solid fa-music me-2 text-primary"></i><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>

    <?php if (App::isAdmin() === true): ?>
        <form method="post" action="/worship/songs/save">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="songID" value="<?php echo (int) $song['songID']; ?>">
            <div class="row g-2">
                <div class="col-md-8"><label class="form-label small">Title <span class="text-danger">*</span></label><input type="text" name="title" value="<?php echo htmlspecialchars((string) $song['title'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="255" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small">Author</label><input type="text" name="author" value="<?php echo htmlspecialchars((string) $song['author'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="255" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small">CCLI #</label><input type="text" name="ccliNumber" value="<?php echo htmlspecialchars((string) $song['ccliNumber'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="40" class="form-control form-control-sm"></div>
                <div class="col-md-2"><label class="form-label small">Key</label><input type="text" name="defaultKey" value="<?php echo htmlspecialchars((string) $song['defaultKey'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="10" class="form-control form-control-sm" placeholder="G"></div>
                <div class="col-md-3"><label class="form-label small">Tempo</label><input type="text" name="defaultTempo" value="<?php echo htmlspecialchars((string) $song['defaultTempo'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="20" class="form-control form-control-sm" placeholder="120 bpm"></div>
                <div class="col-md-3"><label class="form-label small">Tags</label><input type="text" name="tags" value="<?php echo htmlspecialchars((string) $song['tags'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="255" class="form-control form-control-sm" placeholder="praise, communion"></div>
                <div class="col-12"><label class="form-label small">Copyright</label><input type="text" name="copyrightLine" value="<?php echo htmlspecialchars((string) $song['copyrightLine'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="500" class="form-control form-control-sm"></div>
                <div class="col-12"><label class="form-label small">Lyrics</label><textarea name="lyrics" rows="12" class="form-control"><?php echo htmlspecialchars((string) $song['lyrics'], ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                <div class="col-12 d-flex gap-2 mt-3">
                    <button class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>Save</button>
                    <?php if ($isNew !== true): ?>
                        <button name="delete" value="1" class="btn btn-outline-danger" onclick="return confirm('Archive this song?');"><i class="fa-solid fa-box-archive me-1"></i>Archive</button>
                    <?php endif; ?>
                    <a href="/worship/songs" class="btn btn-link ms-auto">Back</a>
                </div>
            </div>
        </form>
    <?php else: ?>
        <p class="text-muted small">
            <?php if (!empty($song['author'])): ?><?php echo htmlspecialchars((string) $song['author'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
            <?php if (!empty($song['ccliNumber'])): ?> &middot; CCLI <?php echo htmlspecialchars((string) $song['ccliNumber'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
            <?php if (!empty($song['defaultKey'])): ?> &middot; Key <?php echo htmlspecialchars((string) $song['defaultKey'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
        </p>
        <?php if (!empty($song['copyrightLine'])): ?>
            <p class="small text-muted"><?php echo htmlspecialchars((string) $song['copyrightLine'], ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <pre style="white-space: pre-wrap; font-family: inherit; font-size: 1.05em;"><?php echo htmlspecialchars((string) $song['lyrics'], ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php if ($ccliAcct !== ''): ?>
            <hr><p class="small text-muted">Reported under CCLI account <?php echo htmlspecialchars($ccliAcct, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
