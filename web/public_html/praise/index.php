<?php
// Path: public_html/praise/index.php
/**
 * -----------------------------------------------------------------------------
 * Praise Reports — List 🎉
 * -----------------------------------------------------------------------------
 * Counterpart to Prayer Requests. Shares the tblPrayerRequests schema
 * filtered by kind='praise'.
 *
 * @package   Portal\Praise
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/260
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Markdown;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();

$rows = [];
$stmt = $db->prepare(
    "SELECT p.requestID, p.subject, p.body, p.isAnonymous, p.createdAt, u.fullName AS submitterName "
    . "FROM tblPrayerRequests p "
    . "LEFT JOIN tblUsers u ON u.userID = p.submitterID "
    . "WHERE p.siteID = ? AND p.kind = 'praise' AND p.status IN ('active','answered') "
    . "ORDER BY p.createdAt DESC LIMIT 50"
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Praise Reports';
$pageSection = 'praise';
$breadcrumbs = ['Dashboard' => '/', 'Praise Reports' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-hands-clapping me-2 text-success"></i>Praise Reports</h1>
        <p class="text-secondary mb-0">Gratitude, answered prayers, celebrations from across the community.</p>
    </div>
    <a href="/praise/new" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Share praise</a>
</div>

<?php if (count($rows) === 0): ?>
    <div class="card">
        <div class="card-body text-center text-muted">
            <p class="mb-2">No praise reports yet — be the first!</p>
            <a href="/praise/new" class="btn btn-primary btn-sm">Share praise</a>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($rows as $r): ?>
            <div class="col-md-6">
                <div class="card h-100 border-success">
                    <div class="card-body">
                        <h2 class="h6 mb-1"><?php echo htmlspecialchars((string) $r['subject'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p class="small text-muted mb-2">
                            <?php
                            $author = ((int) $r['isAnonymous']) === 1 || $r['submitterName'] === null
                                ? 'Anonymous'
                                : (string) $r['submitterName'];
                            echo htmlspecialchars($author, ENT_QUOTES, 'UTF-8');
                            ?>
                            &middot; <?php echo htmlspecialchars(date('j M Y', strtotime((string) $r['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <div class="portal-markdown">
                            <?php echo Markdown::render((string) $r['body'], ['allow_links' => true]); ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
