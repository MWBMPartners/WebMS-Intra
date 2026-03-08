<?php
// Path: public_html/admin/sites/users.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Site User Assignments 🌐👥
 * -----------------------------------------------------------------------------
 * Manage user-to-site assignments for a specific site. Umbrella admins can
 * add/remove users and toggle isSiteAdmin / isSiteRootAdmin flags.
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/45
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

// 🛡️ Umbrella admin only
if (App::isUmbrellaAdmin() === false) {
    http_response_code(403);
    echo 'Access denied. Umbrella admin privileges required.';
    exit();
}

$db     = App::db();
$siteId = (int) ($_GET['site'] ?? 0);

if ($siteId <= 0) {
    header('Location: /admin/sites', true, 302);
    exit();
}

// 📋 Fetch site info
$siteStmt = $db->prepare('SELECT siteID, siteName, siteKey FROM tblSites WHERE siteID = ? LIMIT 1');
if ($siteStmt === false) {
    http_response_code(500);
    echo 'Database error.';
    exit();
}
$siteStmt->bind_param('i', $siteId);
$siteStmt->execute();
$siteInfo = $siteStmt->get_result()->fetch_assoc();
$siteStmt->close();

if ($siteInfo === null) {
    $_SESSION['flash_msg'] = 'Site not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/sites', true, 302);
    exit();
}

// 📝 Handle POST actions (add user, remove user, toggle role)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        // ➕ Add user to site
        $addUserId = (int) ($_POST['userID'] ?? 0);
        if ($addUserId > 0) {
            $addStmt = $db->prepare(
                'INSERT IGNORE INTO tblUserSites (userID, siteID, isSiteAdmin, isSiteRootAdmin) VALUES (?, ?, 0, 0)'
            );
            if ($addStmt !== false) {
                $addStmt->bind_param('ii', $addUserId, $siteId);
                $addStmt->execute();
                $addStmt->close();
                Logger::activity('SiteUserAdd', 'Added user #' . $addUserId . ' to site #' . $siteId);
                $_SESSION['flash_msg'] = 'User added to site.';
                $_SESSION['flash_type'] = 'success';
            }
        }
    } elseif ($action === 'remove') {
        // ➖ Remove user from site
        $removeUserId = (int) ($_POST['userID'] ?? 0);
        if ($removeUserId > 0) {
            $rmStmt = $db->prepare('DELETE FROM tblUserSites WHERE userID = ? AND siteID = ?');
            if ($rmStmt !== false) {
                $rmStmt->bind_param('ii', $removeUserId, $siteId);
                $rmStmt->execute();
                $rmStmt->close();
                Logger::activity('SiteUserRemove', 'Removed user #' . $removeUserId . ' from site #' . $siteId);
                $_SESSION['flash_msg'] = 'User removed from site.';
                $_SESSION['flash_type'] = 'success';
            }
        }
    } elseif ($action === 'toggle_admin') {
        // 🔄 Toggle isSiteAdmin
        $toggleUserId = (int) ($_POST['userID'] ?? 0);
        if ($toggleUserId > 0) {
            $tStmt = $db->prepare(
                'UPDATE tblUserSites SET isSiteAdmin = IF(isSiteAdmin = 1, 0, 1) WHERE userID = ? AND siteID = ?'
            );
            if ($tStmt !== false) {
                $tStmt->bind_param('ii', $toggleUserId, $siteId);
                $tStmt->execute();
                $tStmt->close();
                Logger::activity('SiteUserRole', 'Toggled site admin for user #' . $toggleUserId . ' on site #' . $siteId);
                $_SESSION['flash_msg'] = 'Site admin role updated.';
                $_SESSION['flash_type'] = 'success';
            }
        }
    } elseif ($action === 'toggle_root_admin') {
        // 🔄 Toggle isSiteRootAdmin
        $toggleUserId = (int) ($_POST['userID'] ?? 0);
        if ($toggleUserId > 0) {
            $tStmt = $db->prepare(
                'UPDATE tblUserSites SET isSiteRootAdmin = IF(isSiteRootAdmin = 1, 0, 1) WHERE userID = ? AND siteID = ?'
            );
            if ($tStmt !== false) {
                $tStmt->bind_param('ii', $toggleUserId, $siteId);
                $tStmt->execute();
                $tStmt->close();
                Logger::activity('SiteUserRole', 'Toggled site root admin for user #' . $toggleUserId . ' on site #' . $siteId);
                $_SESSION['flash_msg'] = 'Site root admin role updated.';
                $_SESSION['flash_type'] = 'success';
            }
        }
    }

    header('Location: /admin/sites/users?site=' . $siteId, true, 302);
    exit();
}

// 📋 Fetch users assigned to this site
$assignedStmt = $db->prepare(
    'SELECT U.userID, U.fullName, U.emailAddress, U.isActive, U.isRootAdmin, '
    . 'US.isSiteAdmin, US.isSiteRootAdmin, US.isActive AS siteActive '
    . 'FROM tblUserSites US '
    . 'JOIN tblUsers U ON U.userID = US.userID '
    . 'WHERE US.siteID = ? '
    . 'ORDER BY U.fullName ASC'
);
$assignedUsers = [];
if ($assignedStmt !== false) {
    $assignedStmt->bind_param('i', $siteId);
    $assignedStmt->execute();
    $assignedUsers = $assignedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $assignedStmt->close();
}

// 📋 Fetch users NOT assigned to this site (for add dropdown)
$unassignedStmt = $db->prepare(
    'SELECT U.userID, U.fullName, U.emailAddress '
    . 'FROM tblUsers U '
    . 'WHERE U.isActive = 1 AND U.userID NOT IN ('
    . '  SELECT US.userID FROM tblUserSites US WHERE US.siteID = ?'
    . ') ORDER BY U.fullName ASC'
);
$unassignedUsers = [];
if ($unassignedStmt !== false) {
    $unassignedStmt->bind_param('i', $siteId);
    $unassignedStmt->execute();
    $unassignedUsers = $unassignedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $unassignedStmt->close();
}

// 📋 Flash messages
$flashMsg  = $_SESSION['flash_msg'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$siteName    = htmlspecialchars($siteInfo['siteName'], ENT_QUOTES, 'UTF-8');
$pageTitle   = 'Users — ' . $siteInfo['siteName'];
$pageSection = 'admin';
$breadcrumbs = ['Admin' => '/admin', 'Sites' => '/admin/sites', $siteInfo['siteName'] . ' Users' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="fa-solid fa-users me-2"></i> <?php echo $siteName; ?> — User Assignments
    </h1>
    <a href="/admin/sites" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Sites
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
<div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if (count($unassignedUsers) > 0): ?>
<!-- ➕ Add user form -->
<div class="card mb-4">
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-auto flex-grow-1">
                <label for="addUserSelect" class="form-label">Add User to Site</label>
                <select class="form-select" id="addUserSelect" name="userID" required>
                    <option value="">— Select user —</option>
                    <?php foreach ($unassignedUsers as $uu): ?>
                    <option value="<?php echo (int) $uu['userID']; ?>">
                        <?php echo htmlspecialchars($uu['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                        (<?php echo htmlspecialchars($uu['emailAddress'], ENT_QUOTES, 'UTF-8'); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-plus me-1"></i> Add
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 📋 Assigned users list -->
<?php if (count($assignedUsers) === 0): ?>
<div class="alert alert-info">
    <i class="fa-solid fa-info-circle me-1"></i> No users assigned to this site yet.
</div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>User</th>
                <th>Email</th>
                <th class="text-center">Site Admin</th>
                <th class="text-center">Site Root Admin</th>
                <th class="text-center">Umbrella</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($assignedUsers as $au): ?>
            <?php
            $auId       = (int) $au['userID'];
            $auName     = htmlspecialchars($au['fullName'], ENT_QUOTES, 'UTF-8');
            $auEmail    = htmlspecialchars($au['emailAddress'], ENT_QUOTES, 'UTF-8');
            $isSA       = ((string) $au['isSiteAdmin'] === '1');
            $isSRA      = ((string) $au['isSiteRootAdmin'] === '1');
            $isUmbrella = ((string) $au['isRootAdmin'] === '1');
            ?>
            <tr>
                <td><?php echo $auName; ?></td>
                <td class="text-muted"><?php echo $auEmail; ?></td>
                <td class="text-center">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle_admin">
                        <input type="hidden" name="userID" value="<?php echo $auId; ?>">
                        <button type="submit" class="btn btn-sm <?php echo $isSA ? 'btn-success' : 'btn-outline-secondary'; ?>"
                                title="<?php echo $isSA ? 'Remove site admin' : 'Make site admin'; ?>">
                            <i class="fa-solid <?php echo $isSA ? 'fa-check' : 'fa-minus'; ?>"></i>
                        </button>
                    </form>
                </td>
                <td class="text-center">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle_root_admin">
                        <input type="hidden" name="userID" value="<?php echo $auId; ?>">
                        <button type="submit" class="btn btn-sm <?php echo $isSRA ? 'btn-warning' : 'btn-outline-secondary'; ?>"
                                title="<?php echo $isSRA ? 'Remove site root admin' : 'Make site root admin'; ?>">
                            <i class="fa-solid <?php echo $isSRA ? 'fa-check' : 'fa-minus'; ?>"></i>
                        </button>
                    </form>
                </td>
                <td class="text-center">
                    <?php if ($isUmbrella === true): ?>
                    <span class="badge bg-primary"><i class="fa-solid fa-crown"></i></span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Remove this user from the site?')">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="userID" value="<?php echo $auId; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove from site">
                            <i class="fa-solid fa-user-minus"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
