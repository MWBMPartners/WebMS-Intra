<?php
// _apps/worship/songs.php — Song library list + search (#309)
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$q      = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);

$songs = [];
if ($q !== '') {
    $stmt = $mysqli->prepare(
        'SELECT songID, title, author, ccliNumber, defaultKey FROM tblSongs '
        . 'WHERE siteID = ? AND isActive = 1 AND ('
        . '  title LIKE ? OR author LIKE ? OR lyrics LIKE ? OR ccliNumber LIKE ?'
        . ') ORDER BY title LIMIT 200'
    );
    $needle = '%' . $q . '%';
    $stmt->bind_param('issss', $siteId, $needle, $needle, $needle, $needle);
} else {
    $stmt = $mysqli->prepare(
        'SELECT songID, title, author, ccliNumber, defaultKey FROM tblSongs '
        . 'WHERE siteID = ? AND isActive = 1 ORDER BY title LIMIT 200'
    );
    $stmt->bind_param('i', $siteId);
}
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $songs[] = $r; }
$stmt->close();

$pageTitle = 'Song Library';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="fa-solid fa-music me-2 text-primary"></i>Song Library</h1>
        <?php if (App::isAdmin() === true): ?>
            <a href="/worship/song?new=1" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-1"></i>Add song</a>
        <?php endif; ?>
    </div>

    <form method="get" class="mb-3">
        <div class="input-group input-group-sm">
            <input type="text" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" maxlength="80" class="form-control" placeholder="Search title / author / lyric / CCLI #">
            <button class="btn btn-outline-primary"><i class="fa-solid fa-magnifying-glass"></i></button>
        </div>
    </form>

    <?php if (count($songs) === 0): ?>
        <div class="alert alert-info small">No songs<?php echo $q !== '' ? ' match "' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>.</div>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($songs as $s): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <a href="/worship/song?id=<?php echo (int) $s['songID']; ?>" class="text-decoration-none fw-semibold"><?php echo htmlspecialchars((string) $s['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php if (!empty($s['author'])): ?>
                        <span class="text-muted small"> — <?php echo htmlspecialchars((string) $s['author'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="portal-data-row-aside small text-muted">
                    <?php if (!empty($s['defaultKey'])): ?><span class="badge bg-secondary me-1">Key <?php echo htmlspecialchars((string) $s['defaultKey'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                    <?php if (!empty($s['ccliNumber'])): ?>CCLI <?php echo htmlspecialchars((string) $s['ccliNumber'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
