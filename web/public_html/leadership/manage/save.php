<?php
// Path: public_html/leadership/manage/save.php
/**
 * -----------------------------------------------------------------------------
 * Leadership — Role Management Save Handler 💾
 * -----------------------------------------------------------------------------
 * Handles create and toggle (activate/deactivate) actions for leadership roles.
 * Admin-only endpoint.
 *
 * @package   Portal\Leadership
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/38
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /leadership/manage');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /leadership/manage');
    exit();
}

$action = $_POST['action'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

// 🌐 Multi-site scope
$siteId = Site::id();

// -----------------------------------------------------------------------------
// ➕ Create role
// -----------------------------------------------------------------------------
if ($action === 'create') {
    $roleName    = trim($_POST['roleName'] ?? '');
    $description = trim($_POST['description'] ?? '') !== '' ? trim($_POST['description']) : null;
    $sortOrder   = (int) ($_POST['sortOrder'] ?? 0);

    // 🔍 Validation
    if ($roleName === '') {
        $_SESSION['flash_msg']  = 'Role name is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership/manage');
        exit();
    }

    // 🔤 Generate slug from role name
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $roleName), '-'));

    // 🔍 Ensure slug is unique within this site
    $originalSlug = $slug;
    $counter = 1;
    while (true) {
        $stmt = $mysqli->prepare(
            'SELECT roleID FROM tblLeadershipRoles WHERE roleSlug = ? AND siteID = ? LIMIT 1'
        );
        if ($stmt !== false) {
            $stmt->bind_param('si', $slug, $siteId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($exists === null) {
                break;
            }
        }
        $slug = $originalSlug . '-' . $counter;
        $counter++;
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO tblLeadershipRoles (siteID, roleName, roleSlug, description, sortOrder) '
        . 'VALUES (?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = 'Database error creating role.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership/manage');
        exit();
    }

    $stmt->bind_param('isssi', $siteId, $roleName, $slug, $description, $sortOrder);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    Logger::activity('LeadershipRoleCreated', 'Created leadership role: ' . $roleName . ' (ID:' . $newId . ')', $userId);

    $_SESSION['flash_msg']  = 'Role "' . $roleName . '" created successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /leadership/manage');
    exit();
}

// -----------------------------------------------------------------------------
// 🔄 Toggle active/inactive
// -----------------------------------------------------------------------------
if ($action === 'toggle') {
    $roleID = (int) ($_POST['roleID'] ?? 0);
    if ($roleID <= 0) {
        $_SESSION['flash_msg']  = 'Invalid role ID.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership/manage');
        exit();
    }

    // 📋 Get current state
    $currentActive = null;
    $roleName = '';
    $stmt = $mysqli->prepare(
        'SELECT isActive, roleName FROM tblLeadershipRoles WHERE roleID = ? AND siteID = ? LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $roleID, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row !== null) {
            $currentActive = (int) $row['isActive'];
            $roleName = $row['roleName'];
        }
        $stmt->close();
    }

    if ($currentActive === null) {
        $_SESSION['flash_msg']  = 'Role not found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership/manage');
        exit();
    }

    $newActive = $currentActive === 1 ? 0 : 1;
    $stmt = $mysqli->prepare('UPDATE tblLeadershipRoles SET isActive = ? WHERE roleID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('iii', $newActive, $roleID, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    $stateLabel = $newActive === 1 ? 'activated' : 'deactivated';
    Logger::activity('LeadershipRoleToggled', ucfirst($stateLabel) . ' leadership role: ' . $roleName . ' (ID:' . $roleID . ')', $userId);

    $_SESSION['flash_msg']  = 'Role "' . $roleName . '" ' . $stateLabel . '.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /leadership/manage');
    exit();
}

// 🚫 Unknown action
$_SESSION['flash_msg']  = 'Unknown action.';
$_SESSION['flash_type'] = 'warning';
header('Location: /leadership/manage');
exit();
