<?php
// Path: public_html/calendar/manage/series.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Series Management 🔄
 * -----------------------------------------------------------------------------
 * Admin page for managing event series (create, edit, delete).
 * Series can be nested (parent/child) and events link to a series.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Manage Event Series';
$pageSection = 'calendar';
$breadcrumbs = ['Dashboard' => '/', 'Calendar' => '/calendar', 'Manage' => '/calendar/manage', 'Series' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🌐 Multi-site scope
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 💾 Handle POST actions (create, update, delete)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /calendar/manage/series');
        exit();
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['seriesName'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $parentID = ((int) ($_POST['parentID'] ?? 0)) ?: null;
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));

        if ($name !== '') {
            $stmt = $mysqli->prepare(
                'INSERT INTO tblEventSeries (seriesName, seriesSlug, description, parentID, siteID) VALUES (?, ?, ?, ?, ?)'
            );
            if ($stmt !== false) {
                $stmt->bind_param('sssii', $name, $slug, $desc, $parentID, $siteId);
                $stmt->execute();
                $stmt->close();
            }
            Logger::activity('SeriesCreated', 'Created series: ' . $name, $_SESSION['user_id'] ?? null);
            $_SESSION['flash_msg']  = 'Series "' . $name . '" created.';
            $_SESSION['flash_type'] = 'success';
        }
    }

    if ($action === 'update') {
        $seriesID = (int) ($_POST['seriesID'] ?? 0);
        $name     = trim($_POST['seriesName'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $parentID = ((int) ($_POST['parentID'] ?? 0)) ?: null;

        if ($seriesID > 0 && $name !== '') {
            $stmt = $mysqli->prepare(
                'UPDATE tblEventSeries SET seriesName = ?, description = ?, parentID = ? WHERE seriesID = ? AND siteID = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ssiii', $name, $desc, $parentID, $seriesID, $siteId);
                $stmt->execute();
                $stmt->close();
            }
            Logger::activity('SeriesUpdated', 'Updated series #' . $seriesID, $_SESSION['user_id'] ?? null);
            $_SESSION['flash_msg']  = 'Series updated.';
            $_SESSION['flash_type'] = 'success';
        }
    }

    if ($action === 'delete') {
        $seriesID = (int) ($_POST['seriesID'] ?? 0);
        if ($seriesID > 0) {
            // 🔄 Unlink events from series before deleting
            $stmt = $mysqli->prepare('UPDATE tblEvents SET seriesID = NULL WHERE seriesID = ? AND siteID = ?');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $seriesID, $siteId);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $mysqli->prepare('DELETE FROM tblEventSeries WHERE seriesID = ? AND siteID = ?');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $seriesID, $siteId);
                $stmt->execute();
                $stmt->close();
            }
            Logger::activity('SeriesDeleted', 'Deleted series #' . $seriesID, $_SESSION['user_id'] ?? null);
            $_SESSION['flash_msg']  = 'Series deleted.';
            $_SESSION['flash_type'] = 'success';
        }
    }

    header('Location: /calendar/manage/series');
    exit();
}

// -----------------------------------------------------------------------------
// 📋 Fetch series list
// -----------------------------------------------------------------------------
$seriesList = [];
$stmtSeries = $mysqli->prepare(
    'SELECT s.*, p.seriesName AS parentName, '
    . '(SELECT COUNT(*) FROM tblEvents e WHERE e.seriesID = s.seriesID AND e.isDeleted = 0) AS eventCount '
    . 'FROM tblEventSeries s '
    . 'LEFT JOIN tblEventSeries p ON p.seriesID = s.parentID '
    . 'WHERE s.siteID = ? '
    . 'ORDER BY s.seriesName'
);
if ($stmtSeries !== false) {
    $stmtSeries->bind_param('i', $siteId);
    $stmtSeries->execute();
    $resultSeries = $stmtSeries->get_result();
    while ($r = $resultSeries->fetch_assoc()) {
        $seriesList[] = $r;
    }
    $stmtSeries->close();
}

// 📋 Flash message
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-layer-group me-2"></i>Event Series</h1>
    <div class="d-flex gap-2">
        <a href="/calendar/manage" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Events</a>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addSeriesModal">
            <i class="fa-solid fa-plus me-1"></i> Add Series
        </button>
    </div>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 📋 Series list -->
<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-4">Series Name</div>
        <div class="col-md-3">Parent</div>
        <div class="col-md-2">Events</div>
        <div class="col-md-3 text-end">Actions</div>
    </div>

    <?php if (count($seriesList) === 0): ?>
        <div class="portal-data-row">
            <div class="col-12 text-center text-muted py-3">No series created yet.</div>
        </div>
    <?php endif; ?>

    <?php foreach ($seriesList as $s): ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">Series: </span>
                <strong><?php echo htmlspecialchars($s['seriesName'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Parent: </span>
                <?php echo htmlspecialchars($s['parentName'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Events: </span>
                <span class="badge bg-secondary"><?php echo (int) $s['eventCount']; ?></span>
            </div>
            <div class="col-12 col-md-3 text-md-end mt-2 mt-md-0">
                <a href="/calendar/manage/series-edit?seriesID=<?php echo (int) $s['seriesID']; ?>"
                   class="btn btn-sm btn-outline-success" title="Bulk edit events in this series">
                    <i class="fa-solid fa-pen-ruler"></i>
                </a>
                <button class="btn btn-sm btn-outline-primary portal-edit-series-btn" data-bs-toggle="modal" data-bs-target="#editSeriesModal"
                        data-id="<?php echo (int) $s['seriesID']; ?>"
                        data-name="<?php echo htmlspecialchars($s['seriesName'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-desc="<?php echo htmlspecialchars($s['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        data-parent="<?php echo (int) ($s['parentID'] ?? 0); ?>">
                    <i class="fa-solid fa-pen"></i>
                </button>
                <form method="post" action="/calendar/manage/series" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="seriesID" value="<?php echo (int) $s['seriesID']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                            onclick="return confirm('Delete this series? Events will be unlinked.');">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ➕ Add Series Modal -->
<div class="modal fade" id="addSeriesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="/calendar/manage/series">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Add Event Series</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Series Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="seriesName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Series</label>
                        <select name="parentID" class="form-select">
                            <option value="">— None (top-level) —</option>
                            <?php foreach ($seriesList as $s): ?>
                                <option value="<?php echo (int) $s['seriesID']; ?>">
                                    <?php echo htmlspecialchars($s['seriesName'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Create Series</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ✏️ Edit Series Modal -->
<div class="modal fade" id="editSeriesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="/calendar/manage/series">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="seriesID" id="editSeries-id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Event Series</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Series Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="seriesName" id="editSeries-name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="editSeries-desc" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Series</label>
                        <select name="parentID" class="form-select" id="editSeries-parent">
                            <option value="">— None (top-level) —</option>
                            <?php foreach ($seriesList as $s): ?>
                                <option value="<?php echo (int) $s['seriesID']; ?>">
                                    <?php echo htmlspecialchars($s['seriesName'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var editSeriesModal = document.getElementById('editSeriesModal');
editSeriesModal.addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    document.getElementById('editSeries-id').value     = btn.getAttribute('data-id');
    document.getElementById('editSeries-name').value   = btn.getAttribute('data-name');
    document.getElementById('editSeries-desc').value   = btn.getAttribute('data-desc');
    document.getElementById('editSeries-parent').value = btn.getAttribute('data-parent');
});
</script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
