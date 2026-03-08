<?php
// Path: public_html/announcements/view.php
/**
 * -----------------------------------------------------------------------------
 * Announcements — Single Announcement View
 * -----------------------------------------------------------------------------
 * Displays full announcement detail by slug.
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
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

// 🔍 Get announcement by slug
$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    Router::renderError(404);
    return;
}

$siteId = Site::id();
$now    = date('Y-m-d H:i:s');

$announcement = null;
$stmt = $mysqli->prepare(
    'SELECT a.*, u.fullName AS authorName FROM tblAnnouncements a '
    . 'LEFT JOIN tblUsers u ON u.userID = a.createdByID '
    . 'WHERE a.slug = ? AND a.siteID = ? AND a.isPublished = 1 AND a.isDeleted = 0 '
    . 'AND (a.publishAt IS NULL OR a.publishAt <= ?) '
    . 'AND (a.expiresAt IS NULL OR a.expiresAt > ?) LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('siss', $slug, $siteId, $now, $now);
    $stmt->execute();
    $announcement = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($announcement === null) {
    Router::renderError(404);
    return;
}

// 📌 Page metadata
$pageTitle   = $announcement['title'];
$pageSection = 'announcements';
$breadcrumbs = ['Dashboard' => '/', 'Announcements' => '/announcements', $announcement['title'] => ''];

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 📢 Announcement Detail -->
<article class="mb-5">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="mb-2">
                <?php if ((int) $announcement['isPinned'] === 1): ?>
                    <i class="fa-solid fa-thumbtack text-warning me-1" title="Pinned"></i>
                <?php endif; ?>
                <?php echo htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8'); ?>
            </h1>
            <div class="d-flex flex-wrap gap-2 mb-2">
                <?php if ($announcement['priority'] !== 'normal'): ?>
                    <?php
                    $priorityColors = ['urgent' => 'danger', 'important' => 'warning'];
                    $badgeColor     = $priorityColors[$announcement['priority']] ?? 'secondary';
                    $textClass      = $announcement['priority'] === 'important' ? ' text-dark' : '';
                    ?>
                    <span class="badge bg-<?php echo $badgeColor . $textClass; ?>">
                        <?php echo htmlspecialchars(ucfirst($announcement['priority']), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
            </div>
            <p class="text-muted small mb-0">
                <i class="fa-regular fa-clock me-1"></i>
                <?php echo htmlspecialchars(I18n::formatDate($announcement['createdAt'], 'long'), ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($announcement['authorName'] !== null): ?>
                    <span class="ms-3"><i class="fa-regular fa-user me-1"></i><?php echo htmlspecialchars($announcement['authorName'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if ($announcement['updatedAt'] !== null && $announcement['updatedAt'] !== $announcement['createdAt']): ?>
                    <span class="ms-3"><i class="fa-solid fa-pen me-1"></i>Updated <?php echo htmlspecialchars(I18n::formatDate($announcement['updatedAt'], 'long'), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </p>
        </div>
        <?php if (App::isAdmin() === true): ?>
            <a href="/announcements/manage?edit=<?php echo (int) $announcement['announcementID']; ?>" class="btn btn-outline-primary btn-sm">
                <i class="fa-solid fa-pen me-1"></i>Edit
            </a>
        <?php endif; ?>
    </div>

    <hr>

    <div class="announcement-body">
        <?php echo nl2br(htmlspecialchars($announcement['body'], ENT_QUOTES, 'UTF-8')); ?>
    </div>

    <hr>

    <a href="/announcements" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Announcements
    </a>
</article>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
