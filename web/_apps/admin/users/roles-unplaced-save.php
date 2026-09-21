<?php
// Path: _apps/admin/users/roles-unplaced-save.php
/**
 * -----------------------------------------------------------------------------
 * Place or discard a parked role key 🖊️ (#516)
 * -----------------------------------------------------------------------------
 * The write half of `admin/users/roles-unplaced.php` — see that file's
 * header for the full story of how a row lands here and why nobody can
 * guess where it belongs automatically. Cloned from the proven
 * `admin/users/unplaced-save.php` pattern (#533).
 *
 * GLOBAL ADMINISTRATOR ONLY, and — the same reasoning `unplaced-save.php`
 * already gives — no oracle concern in the refusal wording below: only a
 * global administrator ever reaches this page at all, so there is nobody
 * else who could learn anything from a precise message here.
 *
 * WHAT THIS CANNOT DO: it cannot place a role into an organisation the
 * person is not an ACTIVE member of (Q4 of the #516 plan) — that is
 * refused outright, with an explanation, rather than silently creating the
 * membership as a side effect (which would be a much bigger, unrequested
 * change for a page whose whole job is placing a ROLE, not a membership).
 * It cannot place a role the target organisation does not itself have in
 * its own list — add it at that organisation's Admin -> Roles first.
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

use Portal\Core\AccountGuard;
use Portal\Core\ApiAuth;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Roles;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('admin/users/roles-unplaced'), true, 302);
    exit();
}

// 🛡️ Global administrator only — see roles-unplaced.php's own comment on
//    why AccountGuard:: (not a bare App::isRootAdmin() call) is used here.
if (AccountGuard::actorIsGlobal() === false) {
    http_response_code(403);
    echo t('error.umbrella_admin_only');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users/roles-unplaced'), true, 302);
    exit();
}

$action     = (string) ($_POST['action'] ?? '');
$unplacedId = (int) ($_POST['unplacedID'] ?? 0);
$actorId    = ApiAuth::actorUserId();

if ($unplacedId <= 0 || ($action !== 'place' && $action !== 'discard')) {
    $_SESSION['flash_msg']  = 'Choose a valid entry. Nothing was changed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users/roles-unplaced'), true, 302);
    exit();
}

// 👤 The pen row must still exist — a second global administrator, or a
//    second browser tab, could have already dealt with it.
$penStmt = $mysqli->prepare('SELECT userID, roleKey, roleName FROM tblUserRolesUnplaced WHERE unplacedID = ? LIMIT 1');
$pen     = null;
if ($penStmt !== false) {
    $penStmt->bind_param('i', $unplacedId);
    $penStmt->execute();
    $pen = $penStmt->get_result()->fetch_assoc();
    $penStmt->close();
}
if ($pen === null) {
    $_SESSION['flash_msg']  = 'That entry has already been dealt with.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users/roles-unplaced'), true, 302);
    exit();
}

if ($action === 'discard') {
    $delStmt = $mysqli->prepare('DELETE FROM tblUserRolesUnplaced WHERE unplacedID = ?');
    if ($delStmt !== false) {
        $delStmt->bind_param('i', $unplacedId);
        $delStmt->execute();
        $delStmt->close();
    }
    Logger::activity(
        'UserRoleUnplacedDiscard',
        'Discarded parked role key for account #' . (int) $pen['userID'],
        $actorId
    );
    $_SESSION['flash_msg']  = 'Discarded.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('admin/users/roles-unplaced'), true, 302);
    exit();
}

// action === 'place' from here.
$siteId = (int) ($_POST['siteID'] ?? 0);

// 🏛️ The organisation must exist and be switched on — the same rule
//    unplaced-save.php applies for accounts: a switched-off organisation
//    is not a real destination for anybody.
$orgStmt = $mysqli->prepare('SELECT siteName FROM tblSites WHERE siteID = ? AND isActive = 1 LIMIT 1');
$orgName = null;
if ($orgStmt !== false) {
    $orgStmt->bind_param('i', $siteId);
    $orgStmt->execute();
    $orgRow = $orgStmt->get_result()->fetch_assoc();
    $orgStmt->close();
    $orgName = $orgRow !== null ? (string) $orgRow['siteName'] : null;
}
if ($orgName === null) {
    $_SESSION['flash_msg']  = 'That organisation is switched off or does not exist. Nothing was changed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users/roles-unplaced'), true, 302);
    exit();
}

$userId  = (int) $pen['userID'];
$roleKey = (string) $pen['roleKey'];

// 🎯 The target organisation must already have a role with this exact
//    key in its own list — never created on the fly here, because that
//    would be a much bigger silent side effect than "place this holding".
$roleStmt = $mysqli->prepare('SELECT roleID FROM tblRoles WHERE siteID = ? AND roleKey = ? LIMIT 1');
$roleId   = null;
if ($roleStmt !== false) {
    $roleStmt->bind_param('is', $siteId, $roleKey);
    $roleStmt->execute();
    $roleRow = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();
    $roleId = $roleRow !== null ? (int) $roleRow['roleID'] : null;
}
if ($roleId === null) {
    // 🔐 Not pre-escaped: this page's own render side (roles-unplaced.php)
    //    already runs every flash message through htmlspecialchars() at
    //    output time — escaping here too would double-escape it.
    $_SESSION['flash_msg']  = $orgName . " has no role with the key '" . $roleKey
        . "'. Add it at Admin -> Roles for that organisation first.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users/roles-unplaced'), true, 302);
    exit();
}

$mysqli->begin_transaction();
try {
    $result = Roles::grant($mysqli, $userId, $roleId, $siteId, $actorId);
    if ($result === 'not_found') {
        // 👥 Q4 of the #516 plan: never create the membership as a side
        //    effect — refuse, with an explanation, and let a global
        //    administrator add the person to the organisation on purpose
        //    first if that is really what is needed.
        $mysqli->rollback();
        $_SESSION['flash_msg']  = 'That person is not an active member of ' . $orgName
            . '. Add them first (Admin -> Sites -> Users, or Admin -> Users -> No organisation).';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('admin/users/roles-unplaced'), true, 302);
        exit();
    }

    // 'ok' or 'unchanged' — either way the pen row is dealt with; an
    // 'unchanged' result means the person already held this role in that
    // organisation by some other route, which is not a fault.
    $clearStmt = $mysqli->prepare('DELETE FROM tblUserRolesUnplaced WHERE unplacedID = ?');
    if ($clearStmt !== false) {
        $clearStmt->bind_param('i', $unplacedId);
        $clearStmt->execute();
        $clearStmt->close();
    }
    $mysqli->commit();
} catch (\Throwable $e) {
    $mysqli->rollback();
    Logger::errorPlatform('Roles', 'Error', 'ROLES_UNPLACED_SAVE_FAILED', 'Failed to place parked role #' . $unplacedId, $e->getMessage());
    $_SESSION['flash_msg']  = t('error.database');
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users/roles-unplaced'), true, 302);
    exit();
}

$_SESSION['flash_msg']  = 'Placed: ' . (string) $pen['roleName'] . ' in ' . $orgName . '.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . Site::url('admin/users/roles-unplaced'), true, 302);
exit();
