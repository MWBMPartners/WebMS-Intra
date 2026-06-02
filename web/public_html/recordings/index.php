<?php
// Path: public_html/recordings/index.php
/**
 * Recordings — searchable library.
 *
 * @package   Portal\Recordings
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/264
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Recordings;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();

$q     = trim((string) ($_GET['q'] ?? ''));
$kind  = (string) ($_GET['kind'] ?? '');
$topic = trim((string) ($_GET['topic'] ?? ''));

$sql = 'SELECT r.recordingID, r.title, r.recordedAt, r.durationSeconds, r.kind, r.scripture, '
    . '       r.topics, r.filePath, r.externalUrl, r.presenterText, '
    . '       u.fullName AS presenterName '
    . 'FROM tblRecording r LEFT JOIN tblUsers u ON u.userID = r.presenterID '
    . 'WHERE r.siteID = ? AND r.isPublished = 1';
$types  = 'i';
$params = [$siteId];
if ($q !== '') {
    $sql .= ' AND (r.title LIKE ? OR r.summary LIKE ? OR r.scripture LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sss';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if (in_array($kind, ['sermon','teaching','music','event','other'], true) === true) {
    $sql .= ' AND r.kind = ?';
    $types .= 's';
    $params[] = $kind;
}
if ($topic !== '') {
    $sql .= ' AND FIND_IN_SET(?, REPLACE(LOWER(r.topics), \' \', \'\')) > 0';
    $types .= 's';
    $params[] = strtolower($topic);
}
$sql .= ' ORDER BY r.recordedAt DESC, r.recordingID DESC LIMIT 200';

$recordings = [];
$stmt = $db->prepare($sql);
if ($stmt !== false) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $recordings[] = $r;
    }
    $stmt->close();
}

$topics = [];
$tStmt = $db->prepare('SELECT topic, useCount FROM tblRecordingTopic WHERE siteID = ? ORDER BY useCount DESC, topic LIMIT 30');
if ($tStmt !== false) {
    $tStmt->bind_param('i', $siteId);
    $tStmt->execute();
    $tRs = $tStmt->get_result();
    while ($r = $tRs->fetch_assoc()) {
        $topics[] = $r;
    }
    $tStmt->close();
}

$displayName = (string) (App::settings()['recordings']['displayName'] ?? 'Recordings');

$pageTitle   = $displayName;
$pageSection = 'recordings';
$breadcrumbs = ['Dashboard' => '/', $displayName => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-microphone-lines me-2"></i><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-secondary mb-0">Audio + video archive — searchable and podcast-ready.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/recordings.rss" class="btn btn-outline-warning btn-sm" target="_blank"><i class="fa-solid fa-rss me-1"></i>RSS</a>
        <?php if (App::isAdmin() === true): ?>
            <a href="/recordings/manage" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-gear me-1"></i>Manage</a>
            <a href="/recordings/upload" class="btn btn-primary btn-sm"><i class="fa-solid fa-upload me-1"></i>Upload</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="row g-2 mb-4">
    <div class="col-md-5">
        <input type="search" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" class="form-control" placeholder="Search title / summary / scripture">
    </div>
    <div class="col-md-2">
        <select name="kind" class="form-select">
            <option value="">All kinds</option>
            <?php foreach (['sermon','teaching','music','event','other'] as $k): ?>
                <option value="<?php echo $k; ?>" <?php echo $kind === $k ? 'selected' : ''; ?>><?php echo ucfirst($k); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <input type="text" name="topic" value="<?php echo htmlspecialchars($topic, ENT_QUOTES, 'UTF-8'); ?>" class="form-control" placeholder="Topic tag">
    </div>
    <div class="col-md-2">
        <button class="btn btn-outline-primary w-100">Filter</button>
    </div>
</form>

<?php if (count($topics) > 0): ?>
    <div class="mb-4 small">
        <span class="text-muted me-2">Popular tags:</span>
        <?php foreach ($topics as $t): ?>
            <a href="/recordings?topic=<?php echo urlencode((string) $t['topic']); ?>" class="badge bg-secondary text-decoration-none me-1"><?php echo htmlspecialchars((string) $t['topic'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo (int) $t['useCount']; ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (count($recordings) === 0): ?>
    <div class="alert alert-info">No recordings match. <?php if (App::isAdmin() === true): ?><a href="/recordings/upload">Upload the first →</a><?php endif; ?></div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($recordings as $r):
                    $presenter = (string) ($r['presenterName'] ?? '') !== ''
                        ? (string) $r['presenterName']
                        : (string) ($r['presenterText'] ?? '');
                    $duration = $r['durationSeconds'] !== null
                        ? Recordings::formatDuration((int) $r['durationSeconds'])
                        : null;
                ?>
                    <div class="row py-3 border-bottom align-items-center">
                        <div class="col-md-1 text-center text-secondary fs-3">
                            <?php $icon = (string) $r['externalUrl'] !== '' ? 'fa-link' : 'fa-circle-play'; ?>
                            <i class="fa-solid <?php echo $icon; ?>"></i>
                        </div>
                        <div class="col-md-7">
                            <a href="/recordings/view?id=<?php echo (int) $r['recordingID']; ?>" class="text-decoration-none">
                                <strong><?php echo htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </a>
                            <div class="small text-muted">
                                <span class="badge bg-light text-dark me-1"><?php echo htmlspecialchars((string) $r['kind'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($presenter !== ''): ?>· <?php echo htmlspecialchars($presenter, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                                <?php if (($r['scripture'] ?? '') !== ''): ?>· <?php echo htmlspecialchars((string) $r['scripture'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-2 small text-muted">
                            <?php if ($r['recordedAt'] !== null): ?>
                                <?php echo htmlspecialchars(date('j M Y', (int) strtotime((string) $r['recordedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2 small text-end text-muted">
                            <?php echo $duration !== null ? htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') : '—'; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
