<?php
// Path: public_html/recordings/search.php
/**
 * Recordings — full-text search across transcripts.
 *
 * @package   Portal\Recordings
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/276
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Site;
use Portal\Core\Transcription;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$q      = trim((string) ($_GET['q'] ?? ''));
$hits   = $q !== '' ? Transcription::search($siteId, $q) : [];

$pageTitle   = 'Search transcripts';
$pageSection = 'recordings';
$breadcrumbs = ['Dashboard' => '/', 'Recordings' => '/recordings', 'Search transcripts' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-magnifying-glass me-2"></i>Search transcripts</h1>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-9"><input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" placeholder="grace, justice, parable…"></div>
    <div class="col-md-3"><button class="btn btn-primary w-100">Search</button></div>
</form>

<?php if ($q === ''): ?>
    <p class="text-muted">Enter any word or phrase to search across every transcript in this site.</p>
<?php elseif (count($hits) === 0): ?>
    <div class="alert alert-info">No matches for <strong><?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?></strong>.</div>
<?php else: ?>
    <p class="text-secondary"><?php echo count($hits); ?> match<?php echo count($hits) === 1 ? '' : 'es'; ?> for <strong><?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?></strong></p>
    <?php foreach ($hits as $h): ?>
        <div class="card mb-2"><div class="card-body">
            <h5 class="mb-1"><a href="/recordings/transcript?id=<?php echo (int) $h['recordingID']; ?>" class="text-decoration-none">
                <?php echo htmlspecialchars((string) $h['title'], ENT_QUOTES, 'UTF-8'); ?>
            </a></h5>
            <?php if ($h['recordedAt'] !== null): ?>
                <p class="small text-muted mb-1"><?php echo htmlspecialchars(date('j M Y', (int) strtotime((string) $h['recordedAt'])), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <p class="mb-0"><?php echo htmlspecialchars((string) $h['snippet'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div></div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
