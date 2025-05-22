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

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Logger;

// Only Admins
Auth::requireLogin();
// TODO: role check to ensure current user is Admin or higher

// -----------------------------------------------------------------------------
// 1. Fetch settings list
// -----------------------------------------------------------------------------
$rows = [];
$stmt = $mysqli->prepare('SELECT settingID, settingKey, settingValue, isSensitive, updatedAt FROM tblSettings ORDER BY settingKey');
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

// -----------------------------------------------------------------------------
// 2. Render page
// -----------------------------------------------------------------------------
?>
<!doctype html>
<html lang="en" data-bs-theme="<?php echo ($SETTINGS['features']['darkModeEnabled'] ?? 'false') === 'true' ? 'dark' : 'light'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings &bull; <?php echo htmlspecialchars($SETTINGS['site']['name']); ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <script src="/assets/js/bootstrap.bundle.min.js" defer></script>
</head>
<body>
<div class="container py-4">
    <h1 class="mb-4">Settings</h1>
    <table class="table table-hover align-middle">
        <thead class="table-light">
        <tr>
            <th scope="col">Key</th>
            <th scope="col">Value</th>
            <th scope="col">Updated</th>
            <th scope="col">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="text-monospace small"><?php echo htmlspecialchars($row['settingKey']); ?></td>
                <td>
                    <?php if ($row['isSensitive'] == '1'): ?>
                        <span class="text-muted">••••••</span>
                    <?php else: ?>
                        <?php echo htmlspecialchars(str_replace("\n", '⏎ ', $row['settingValue'])); ?>
                    <?php endif; ?>
                </td>
                <td class="small text-secondary"><?php echo htmlspecialchars($row['updatedAt']); ?></td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal"
                            data-id="<?php echo $row['settingID']; ?>"
                            data-key="<?php echo htmlspecialchars($row['settingKey']); ?>"
                            data-value="<?php echo htmlspecialchars($row['settingValue']); ?>"
                            data-sensitive="<?php echo $row['isSensitive']; ?>">
                        Edit
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">Add Setting</button>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="/settings/save.php">
                <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
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

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="/settings/save.php">
                <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
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

<script>
// Populate edit modal with row data
var editModal = document.getElementById('editModal');
editModal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    document.getElementById('edit-settingID').value   = button.getAttribute('data-id');
    document.getElementById('edit-settingKey').value  = button.getAttribute('data-key');
    document.getElementById('edit-settingValue').value= button.getAttribute('data-value');
});
</script>
</body>
</html>
