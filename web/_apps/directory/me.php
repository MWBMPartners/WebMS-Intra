<?php
// Path: public_html/directory/me.php
/**
 * Member Directory — user edits own profile + per-field visibility.
 *
 * @package   Portal\Directory
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/261
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$u = null;
$stmt = $db->prepare(
    'SELECT displayBio, displayPhone, displayAddress, '
    . '       visibilityName, visibilityRoles, visibilityEmail, visibilityPhone, visibilityAddress, '
    . '       visibilityBio, visibilityPhoto '
    . 'FROM tblUsers WHERE userID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$pageTitle   = 'My directory profile';
$pageSection = 'directory';
$breadcrumbs = ['Dashboard' => '/', 'Directory' => '/directory', 'My profile' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();

$visRow = function (string $field, string $label, string $current, string $help = '') use ($csrf): void {
    $opts = ['private' => 'Private (just me + admins)', 'team' => 'Team-mates', 'members' => 'All members', 'public' => 'Public (no login)'];
    echo '<div class="row mb-2 align-items-center"><div class="col-md-4"><label class="form-label small">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label></div>'
       . '<div class="col-md-5"><select name="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '" class="form-select form-select-sm">';
    foreach ($opts as $val => $optLabel) {
        $sel = $val === $current ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select></div>';
    if ($help !== '') {
        echo '<div class="col-md-3 small text-muted">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    echo '</div>';
};
?>

<h1 class="mb-3"><i class="fa-solid fa-user-pen me-2"></i>My directory profile</h1>
<p class="text-muted">Update what you share and who sees it.</p>

<form method="post" action="/directory/save">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">About me</h2>
            <div class="mb-2"><label class="form-label small">Short bio (markdown supported)</label>
                <textarea name="displayBio" class="form-control form-control-sm" rows="3"><?php echo htmlspecialchars((string) ($u['displayBio'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="mb-2"><label class="form-label small">Phone</label>
                <input type="tel" name="displayPhone" class="form-control form-control-sm" maxlength="50" value="<?php echo htmlspecialchars((string) ($u['displayPhone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="mb-2"><label class="form-label small">Address</label>
                <textarea name="displayAddress" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars((string) ($u['displayAddress'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Who sees what</h2>
            <?php
            $visRow('visibilityName',    'Name',              (string) ($u['visibilityName']    ?? 'members'));
            $visRow('visibilityEmail',   'Email',             (string) ($u['visibilityEmail']   ?? 'private'));
            $visRow('visibilityPhone',   'Phone',             (string) ($u['visibilityPhone']   ?? 'private'));
            $visRow('visibilityAddress', 'Address',           (string) ($u['visibilityAddress'] ?? 'private'));
            $visRow('visibilityBio',     'Bio',               (string) ($u['visibilityBio']     ?? 'members'));
            $visRow('visibilityRoles',   'Roles / leadership',(string) ($u['visibilityRoles']   ?? 'members'));
            ?>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Save</button>
    <a href="/directory" class="btn btn-outline-secondary">Cancel</a>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
