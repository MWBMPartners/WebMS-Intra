<?php
// Path: public_html/announcements/index.php
/**
 * -----------------------------------------------------------------------------
 * Announcements — Noticeboard Listing
 * -----------------------------------------------------------------------------
 * Lists published announcements with pinned items at top. Supports pagination.
 *
 * @package   Portal\Announcements
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/89
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\I18n;
use Portal\Core\Site;

Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

// 📌 Page metadata
$pageTitle   = 'Announcements';
$pageSection = 'announcements';
$breadcrumbs = ['Dashboard' => '/', 'Announcements' => ''];

$siteId = Site::id();
$now    = date('Y-m-d H:i:s');

// 📊 Pagination
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// 📋 Count total published announcements
$totalItems = 0;
$cntStmt = $mysqli->prepare(
    'SELECT COUNT(*) AS cnt FROM tblAnnouncements '
    . 'WHERE siteID = ? AND isPublished = 1 AND isDeleted = 0 '
    . 'AND (publishAt IS NULL OR publishAt <= ?) '
    . 'AND (expiresAt IS NULL OR expiresAt > ?)'
);
if ($cntStmt !== false) {
    $cntStmt->bind_param('iss', $siteId, $now, $now);
    $cntStmt->execute();
    $totalItems = (int) ($cntStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $cntStmt->close();
}
$totalPages = max(1, (int) ceil($totalItems / $perPage));

// 📋 Fetch announcements (pinned first, then by date)
$announcements = [];
$stmt = $mysqli->prepare(
    'SELECT a.*, u.fullName AS authorName FROM tblAnnouncements a '
    . 'LEFT JOIN tblUsers u ON u.userID = a.createdByID '
    . 'WHERE a.siteID = ? AND a.isPublished = 1 AND a.isDeleted = 0 '
    . 'AND (a.publishAt IS NULL OR a.publishAt <= ?) '
    . 'AND (a.expiresAt IS NULL OR a.expiresAt > ?) '
    . 'ORDER BY a.isPinned DESC, a.createdAt DESC LIMIT ? OFFSET ?'
);
if ($stmt !== false) {
    $stmt->bind_param('issii', $siteId, $now, $now, $perPage, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    $stmt->close();
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📢 Announcements Listing -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-bullhorn me-2"></i>Announcements</h1>
    <?php if (App::isAdmin() === true): ?>
        <a href="/announcements/manage" class="btn btn-primary">
            <i class="fa-solid fa-plus me-1"></i>New Announcement
        </a>
    <?php endif; ?>
</div>

<?php if (count($announcements) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-info-circle me-2"></i>No announcements at this time.
    </div>
<?php else: ?>
    <?php foreach ($announcements as $ann): ?>
        <?php
        $priorityColors = ['urgent' => 'danger', 'important' => 'warning', 'normal' => 'secondary'];
        $borderColor     = $priorityColors[$ann['priority']] ?? 'secondary';
        ?>
        <div class="card mb-3 border-start border-<?php echo $borderColor; ?> border-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="card-title mb-1">
                            <?php if ((int) $ann['isPinned'] === 1): ?>
                                <i class="fa-solid fa-thumbtack text-warning me-1" title="Pinned"></i>
                            <?php endif; ?>
                            <a href="/announcements/view?slug=<?php echo htmlspecialchars(urlencode($ann['slug']), ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($ann['title'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </h5>
                        <p class="card-text text-muted mb-0">
                            <?php echo htmlspecialchars(mb_strimwidth(strip_tags($ann['body']), 0, 200, '...'), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                    <?php if ($ann['priority'] !== 'normal'): ?>
                        <span class="badge bg-<?php echo $borderColor; ?> ms-2">
                            <?php echo htmlspecialchars(ucfirst($ann['priority']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 small text-muted">
                    <i class="fa-regular fa-clock me-1"></i>
                    <?php echo htmlspecialchars(I18n::formatDate($ann['createdAt'], 'long'), ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($ann['authorName'] !== null): ?>
                        <span class="ms-2"><i class="fa-regular fa-user me-1"></i><?php echo htmlspecialchars($ann['authorName'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- 📊 Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav aria-label="Announcements pagination">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo ($page <= 1 ? 'disabled' : ''); ?>">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>">Previous</a>
                </li>
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?php echo ($p === $page ? 'active' : ''); ?>">
                        <a class="page-link" href="?page=<?php echo $p; ?>"><?php echo $p; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php echo ($page >= $totalPages ? 'disabled' : ''); ?>">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
