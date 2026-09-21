<?php
// Path: _apps/admin/roles/save.php
/**
 * -----------------------------------------------------------------------------
 * Create, rename or delete a role 🏷️ (#516)
 * -----------------------------------------------------------------------------
 * The write half of `admin/roles/index.php` — see that file's header for
 * what this page manages and what it deliberately does not.
 *
 * WHO MAY DO THIS: any administrator of the organisation currently open
 * (owner decision, #516 plan Q7) — `App::isAdmin()`, the same gate the
 * page itself uses. This is NOT an account-reach decision (nobody's
 * account is being changed), so `AccountGuard` is not involved here —
 * unlike `admin/users/roles-save.php`, which changes what a specific
 * ACCOUNT holds and does need it.
 *
 * WHY A ROLE'S KEY IS NEVER READ FROM AN UPDATE POST: keys are fixed for
 * life once a role exists, standard or not, because workflow steps,
 * newsletter segments, the pre-release channel gate and settings all name
 * a role by its key. Letting an update silently change the key would
 * break every one of those without any warning.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/516
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Roles;
use Portal\Core\Router;
use Portal\Core\Site;

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

$siteId  = Site::id();
$actorId = ApiAuth::actorUserId();
$action  = (string) ($_POST['action'] ?? '');

if ($action === 'create') {
    $roleKey    = Roles::normaliseKey((string) ($_POST['roleKey'] ?? ''));
    $roleName   = trim((string) ($_POST['roleName'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if (Roles::isValidNewKey($roleKey) === false) {
        $_SESSION['flash_msg']  = 'A key is 2-50 characters: lower-case letters, digits and underscores, starting with a letter.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    if ($roleName === '') {
        $_SESSION['flash_msg']  = 'A label is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO tblRoles (siteID, roleKey, roleName, description, isStandard) VALUES (?, ?, ?, ?, 0)'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = t('error.database');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    $descriptionVal = $description !== '' ? $description : null;
    try {
        $stmt->bind_param('isss', $siteId, $roleKey, $roleName, $descriptionVal);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();
    } catch (\mysqli_sql_exception $e) {
        $stmt->close();
        if ($e->getCode() === 1062) {
            $_SESSION['flash_msg']  = 'A role with that key already exists here.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . Site::url('admin/roles'), true, 302);
            exit();
        }
        throw $e;
    }

    Logger::audit('tblRoles', $newId, 'create', null, ['siteID' => $siteId, 'roleKey' => $roleKey, 'roleName' => $roleName], $actorId);
    Logger::activity('RoleCreate', 'Created role "' . $roleName . '" (' . $roleKey . ')', $actorId);
    $_SESSION['flash_msg']  = 'Role added.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

if ($action === 'update') {
    $roleId      = (int) ($_POST['roleID'] ?? 0);
    $roleName    = trim((string) ($_POST['roleName'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    // 🎯 Scoped to (roleID, siteID) — a roleID belonging to another
    //    organisation costs one lookup that finds nothing, exactly like a
    //    genuinely wrong number, never a distinguishable "not yours" answer.
    $lookup = $mysqli->prepare('SELECT isStandard FROM tblRoles WHERE roleID = ? AND siteID = ? LIMIT 1');
    $found  = null;
    if ($lookup !== false) {
        $lookup->bind_param('ii', $roleId, $siteId);
        $lookup->execute();
        $found = $lookup->get_result()->fetch_assoc();
        $lookup->close();
    }
    if ($found === null) {
        $_SESSION['flash_msg']  = 'That role could not be found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    if ($roleName === '') {
        $_SESSION['flash_msg']  = 'A label is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }

    // ⚠️ roleKey is NEVER read from this POST — see the file header for why.
    $descriptionVal = $description !== '' ? $description : null;
    $stmt = $mysqli->prepare('UPDATE tblRoles SET roleName = ?, description = ? WHERE roleID = ? AND siteID = ?');
    if ($stmt === false) {
        $_SESSION['flash_msg']  = t('error.database');
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    $stmt->bind_param('ssii', $roleName, $descriptionVal, $roleId, $siteId);
    $stmt->execute();
    $stmt->close();

    Logger::audit('tblRoles', $roleId, 'update', null, ['roleName' => $roleName, 'description' => $description], $actorId);
    Logger::activity('RoleUpdate', 'Updated role #' . $roleId . ' (' . $roleName . ')', $actorId);
    $_SESSION['flash_msg']  = 'Role updated.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

if ($action === 'delete') {
    $roleId = (int) ($_POST['roleID'] ?? 0);

    $lookup = $mysqli->prepare('SELECT roleName, isStandard FROM tblRoles WHERE roleID = ? AND siteID = ? LIMIT 1');
    $found  = null;
    if ($lookup !== false) {
        $lookup->bind_param('ii', $roleId, $siteId);
        $lookup->execute();
        $found = $lookup->get_result()->fetch_assoc();
        $lookup->close();
    }
    if ($found === null) {
        $_SESSION['flash_msg']  = 'That role could not be found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }
    // #497: this flag arrives as the whole number 1, not the text '1'.
    $isStandard = $found['isStandard'] === 1 || $found['isStandard'] === '1';
    if ($isStandard === true) {
        $_SESSION['flash_msg']  = 'Standard roles cannot be deleted. Rename it instead.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }

    $holders = Roles::countHolders($mysqli, $roleId, $siteId);
    if ($holders > 0) {
        $_SESSION['flash_msg']  = $holders . ' ' . ($holders === 1 ? 'person holds' : 'people hold')
            . ' this role here. Remove it from them first.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/roles'), true, 302);
        exit();
    }

    $stmt = $mysqli->prepare('DELETE FROM tblRoles WHERE roleID = ? AND siteID = ? AND isStandard = 0');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $roleId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    Logger::audit('tblRoles', $roleId, 'delete', ['roleName' => (string) $found['roleName']], null, $actorId);
    Logger::activity('RoleDelete', 'Deleted role #' . $roleId . ' (' . $found['roleName'] . ')', $actorId);
    $_SESSION['flash_msg']  = 'Role deleted.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('admin/roles'), true, 302);
    exit();
}

$_SESSION['flash_msg']  = 'Unknown action.';
$_SESSION['flash_type'] = 'danger';
header('Location: ' . Site::url('admin/roles'), true, 302);
exit();
