<?php
// Path: public_html/announcements/manage.php
/**
 * -----------------------------------------------------------------------------
 * Announcements — Admin Management
 * -----------------------------------------------------------------------------
 * Lists all announcements (including drafts) for admin editing. Shows create/edit
 * form when ?edit= parameter is present.
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
Auth::requireLogin();

// 🛡️ Admin only
if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = 'You do not have permission to manage announcements.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /announcements');
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🔍 Editing mode?
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;

if ($editId !== null && $editId > 0) {
    $eStmt = $mysqli->prepare(
        'SELECT * FROM tblAnnouncements WHERE announcementID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
    );
    if ($eStmt !== false) {
        $eStmt->bind_param('ii', $editId, $siteId);
        $eStmt->execute();
        $editing = $eStmt->get_result()->fetch_assoc();
        $eStmt->close();
    }
}

// 📌 Page metadata
$pageTitle   = ($editId === 0 || $editId === null) && $editing === null
    ? ($editId === 0 ? 'New Announcement' : 'Manage Announcements')
    : 'Edit Announcement';
$pageSection = 'announcements';
$breadcrumbs = ['Dashboard' => '/', 'Announcements' => '/announcements', 'Manage' => ''];

// 📋 Fetch all announcements for listing
$announcements = [];
$stmt = $mysqli->prepare(
    'SELECT a.*, u.fullName AS authorName FROM tblAnnouncements a '
    . 'LEFT JOIN tblUsers u ON u.userID = a.createdByID '
    . 'WHERE a.siteID = ? AND a.isDeleted = 0 '
    . 'ORDER BY a.isPinned DESC, a.createdAt DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
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

<!-- 📢 Announcement Management -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-bullhorn me-2"></i>Manage Announcements</h1>
    <a href="/announcements/manage?edit=0" class="btn btn-primary">
        <i class="fa-solid fa-plus me-1"></i>New Announcement
    </a>
</div>

<?php if ($editId !== null): ?>
    <!-- 📝 Create / Edit Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><?php echo $editing !== null ? 'Edit' : 'New'; ?> Announcement</h5>
        </div>
        <div class="card-body">
            <form method="post" action="/announcements/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="announcementID" value="<?php echo (int) ($editing['announcementID'] ?? 0); ?>">

                <div class="mb-3">
                    <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title" name="title" required maxlength="255"
                           value="<?php echo htmlspecialchars($editing['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="mb-3">
                    <label for="body" class="form-label">Body <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="body" name="body" rows="8" required><?php echo htmlspecialchars($editing['body'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label for="priority" class="form-label">Priority</label>
                        <select class="form-select" id="priority" name="priority">
                            <?php foreach (['normal', 'important', 'urgent'] as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo (($editing['priority'] ?? 'normal') === $opt ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars(ucfirst($opt), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="publishAt" class="form-label">Publish Date</label>
                        <input type="datetime-local" class="form-control" id="publishAt" name="publishAt"
                               value="<?php echo $editing !== null && $editing['publishAt'] !== null ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($editing['publishAt'])), ENT_QUOTES, 'UTF-8') : ''; ?>">
                        <small class="text-muted">Leave blank to publish immediately</small>
                    </div>
                    <div class="col-md-4">
                        <label for="expiresAt" class="form-label">Expires</label>
                        <input type="datetime-local" class="form-control" id="expiresAt" name="expiresAt"
                               value="<?php echo $editing !== null && $editing['expiresAt'] !== null ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($editing['expiresAt'])), ENT_QUOTES, 'UTF-8') : ''; ?>">
                        <small class="text-muted">Leave blank to never expire</small>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" id="isPinned" name="isPinned" value="1"
                                   <?php echo (($editing['isPinned'] ?? '0') === '1' ? 'checked' : ''); ?>>
                            <label class="form-check-label" for="isPinned">Pin to top</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" id="isPublished" name="isPublished" value="1"
                                   <?php echo (($editing['isPublished'] ?? '0') === '1' || $editing === null ? 'checked' : ''); ?>>
                            <label class="form-check-label" for="isPublished">Published</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk me-1"></i><?php echo $editing !== null ? 'Update' : 'Create'; ?>
                    </button>
                    <a href="/announcements/manage" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- 📋 Announcements List -->
<?php if (count($announcements) === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-info-circle me-2"></i>No announcements yet. Create one to get started.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-5">Title</div>
            <div class="col-2">Priority</div>
            <div class="col-2">Status</div>
            <div class="col-2">Date</div>
            <div class="col-1 text-end">Actions</div>
        </div>
        <?php foreach ($announcements as $ann): ?>
            <?php
            $priorityColors = ['urgent' => 'danger', 'important' => 'warning', 'normal' => 'secondary'];
            $pColor = $priorityColors[$ann['priority']] ?? 'secondary';
            ?>
            <div class="portal-data-row">
                <div class="col-5">
                    <?php if ((int) $ann['isPinned'] === 1): ?>
                        <i class="fa-solid fa-thumbtack text-warning me-1" title="Pinned"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($ann['title'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-2">
                    <span class="badge bg-<?php echo $pColor; ?><?php echo ($ann['priority'] === 'important' ? ' text-dark' : ''); ?>">
                        <?php echo htmlspecialchars(ucfirst($ann['priority']), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-2">
                    <?php if ((int) $ann['isPublished'] === 1): ?>
                        <span class="badge bg-success">Published</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Draft</span>
                    <?php endif; ?>
                </div>
                <div class="col-2 small">
                    <?php echo htmlspecialchars(I18n::formatDate($ann['createdAt'], 'short'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-1 text-end">
                    <a href="/announcements/manage?edit=<?php echo (int) $ann['announcementID']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                    <form method="post" action="/announcements/delete" class="d-inline" data-confirm="Delete this announcement?" data-confirm-destructive="true">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="announcementID" value="<?php echo (int) $ann['announcementID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
