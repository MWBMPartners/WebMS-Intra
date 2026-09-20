<?php
// Path: _apps/admin/users/unplaced-save.php
/**
 * -----------------------------------------------------------------------------
 * Place an account into an organisation 🧭 (#533)
 * -----------------------------------------------------------------------------
 * The write half of `admin/users/unplaced.php` — see that file's header for
 * the full story of why these accounts exist and why nobody can guess where
 * they belong automatically.
 *
 * GLOBAL ADMINISTRATOR ONLY, and no oracle concern in the refusal wording
 * below: only a global administrator ever reaches this page at all (the
 * gate runs first), so there is nobody else who could learn anything from
 * a precise refusal message here — unlike, say, the "email already in use"
 * wording elsewhere in this package (#521), which a NON-global
 * administrator can trigger and therefore has to stay deliberately vague.
 *
 * WHAT THIS CANNOT DO: it cannot undo a placement once made (use "Remove
 * from site" on `/admin/sites/users` for that), and it cannot place an
 * account into an organisation that is switched off — that is refused on
 * purpose, the same way the rest of the portal treats an inactive
 * organisation as not a real destination for anybody.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/533
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\ApiAuth;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('admin/users/unplaced'), true, 302);
    exit();
}

// 🛡️ Global administrator only — see unplaced.php's own comment on why
//    AccountGuard:: (not a bare App::isRootAdmin() call) is used here.
if (AccountGuard::actorIsGlobal() === false) {
    http_response_code(403);
    echo t('error.umbrella_admin_only');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users/unplaced'), true, 302);
    exit();
}

$userId = (int) ($_POST['userID'] ?? 0);
$siteId = (int) ($_POST['siteID'] ?? 0);

if ($userId <= 0 || $siteId <= 0) {
    $_SESSION['flash_msg']  = 'Choose an account and an organisation. Nothing was changed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users/unplaced'), true, 302);
    exit();
}

// 🏛️ The organisation must exist and be switched on. A switched-off
//    organisation is not a real destination — the same rule the rest of
//    the portal applies when deciding whether an organisation is a place
//    somebody can currently belong to.
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
    header('Location: ' . Site::url('admin/users/unplaced'), true, 302);
    exit();
}

// 👤 The account must exist and still have NO membership row at all — a
//    second browser tab, or a second global administrator, could have
//    already placed it between the list rendering and this submit.
$acctStmt = $mysqli->prepare(
    'SELECT u.userID FROM tblUsers u '
    . 'WHERE u.userID = ? AND NOT EXISTS (SELECT 1 FROM tblUserSites us WHERE us.userID = u.userID) '
    . 'LIMIT 1'
);
$stillUnplaced = false;
if ($acctStmt !== false) {
    $acctStmt->bind_param('i', $userId);
    $acctStmt->execute();
    $stillUnplaced = $acctStmt->get_result()->fetch_assoc() !== null;
    $acctStmt->close();
}
if ($stillUnplaced === false) {
    $_SESSION['flash_msg']  = 'That account already belongs to an organisation. Nothing was changed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users/unplaced'), true, 302);
    exit();
}

// ✅ Both checks passed — write the membership row. INSERT IGNORE is a
//    second belt: if a race slipped past the check above (another request
//    committed a row for this same account in between), the unique key
//    uq_user_site on (userID, siteID) silently refuses the duplicate
//    instead of throwing, and affected_rows tells us which happened.
$insStmt = $mysqli->prepare('INSERT IGNORE INTO tblUserSites (userID, siteID, isActive) VALUES (?, ?, 1)');
if ($insStmt === false) {
    $_SESSION['flash_msg']  = t('error.database');
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users/unplaced'), true, 302);
    exit();
}
$insStmt->bind_param('ii', $userId, $siteId);
$insStmt->execute();
$affected = $insStmt->affected_rows;
$newId    = (int) $insStmt->insert_id;
$insStmt->close();

if ($affected > 0) {
    $actorId = ApiAuth::actorUserId();
    Logger::activity('UserPlaced', 'Placed account #' . $userId . ' into organisation #' . $siteId, $actorId);
    Logger::audit(
        'tblUserSites',
        $newId,
        'create',
        null,
        ['userID' => $userId, 'siteID' => $siteId, 'isActive' => 1],
        $actorId
    );
    $_SESSION['flash_msg']  = 'Added to ' . $orgName . '.';
    $_SESSION['flash_type'] = 'success';
} else {
    // The INSERT IGNORE belt caught a race the SELECT check above missed.
    $_SESSION['flash_msg']  = 'That account already belongs to an organisation. Nothing was changed.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: ' . Site::url('admin/users/unplaced'), true, 302);
exit();
