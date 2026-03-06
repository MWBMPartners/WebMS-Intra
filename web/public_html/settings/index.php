<?php
// Path: apps/settings/index.php
/**
 * -----------------------------------------------------------------------------
 * Settings Admin UI ⚙️
 * -----------------------------------------------------------------------------
 * Allows Admin / Global Admin users to view and edit portal & app settings that
 * are stored in tblSettings.  Sensitive items are masked unless the user is a
 * Global Admin.  New settings can be added via a modal form.
 * -----------------------------------------------------------------------------
 * @package    Portal\Settings
 * @license    MIT
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

// 📌 Page metadata for the template system
$pageTitle   = 'Settings';
$pageSection = 'settings';
$breadcrumbs = ['Dashboard' => '/', 'Settings' => ''];

// 🛡️ Admin access check (Router handles isProtected login enforcement)
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// -----------------------------------------------------------------------------
// 1. 📋 Fetch settings list
// -----------------------------------------------------------------------------
$rows = [];
$stmt = $mysqli->prepare('SELECT settingID, settingKey, settingValue, isSensitive, updatedAt FROM tblSettings ORDER BY settingKey');
if ($stmt !== false) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- ⚙️ Settings Management -->
<h1 class="mb-4">Settings</h1>

<!-- 📋 Responsive data list (replaces <table>) -->
<div class="portal-data-list">
    <!-- 🏷️ Header row (visible on md+ screens) -->
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-4">Key</div>
        <div class="col-md-4">Value</div>
        <div class="col-md-2">Updated</div>
        <div class="col-md-2 text-end">Actions</div>
    </div>

    <?php foreach ($rows as $row): ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">Key: </span>
                <code class="small"><?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?></code>
            </div>
            <div class="col-12 col-md-4">
                <span class="d-md-none fw-semibold">Value: </span>
                <?php if ($row['isSensitive'] === '1'): ?>
                    <span class="text-muted">------</span>
                <?php else: ?>
                    <?php echo htmlspecialchars(str_replace("\n", ' ', $row['settingValue']), ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2 small text-secondary">
                <span class="d-md-none fw-semibold">Updated: </span>
                <?php echo htmlspecialchars($row['updatedAt'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal"
                        data-id="<?php echo (int) $row['settingID']; ?>"
                        data-key="<?php echo htmlspecialchars($row['settingKey'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-value="<?php echo htmlspecialchars($row['settingValue'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-sensitive="<?php echo htmlspecialchars($row['isSensitive'], ENT_QUOTES, 'UTF-8'); ?>">
                    Edit
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<button class="btn btn-success mt-3" data-bs-toggle="modal" data-bs-target="#addModal">Add Setting</button>

<!-- 📝 Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="/settings/save.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="settingID" id="edit-settingID">
                <div class="modal-header">
                    <h5 class="modal-title" id="editLabel">Edit Setting</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Key</label>
                        <input type="text" class="form-control" name="settingKey" id="edit-settingKey" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Value</label>
                        <textarea class="form-control" name="settingValue" id="edit-settingValue" rows="4"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ➕ Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="/settings/save.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="addLabel">Add New Setting</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Key</label>
                        <input type="text" class="form-control" name="settingKey" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Value</label>
                        <textarea class="form-control" name="settingValue" rows="4"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" name="isSensitive" id="add-sensitive">
                        <label class="form-check-label" for="add-sensitive">
                            Sensitive (encrypt in DB)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Setting</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 📦 Edit modal population script -->
<script>
var editModal = document.getElementById('editModal');
editModal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    document.getElementById('edit-settingID').value    = button.getAttribute('data-id');
    document.getElementById('edit-settingKey').value   = button.getAttribute('data-key');
    document.getElementById('edit-settingValue').value = button.getAttribute('data-value');
});
</script>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
