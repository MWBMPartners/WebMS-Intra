<?php
// Path: _apps/admin/departments/members-save.php
/**
 * -----------------------------------------------------------------------------
 * Add a person to a department, change their flags, or remove them 🏢 (#517)
 * -----------------------------------------------------------------------------
 * The write half of `admin/departments/members.php`. Three actions: `add`
 * (with starting flags), `flags` (change the five flags) and `remove`.
 *
 * WHO MAY DO THIS — the same two gates, in the same order, as
 * `admin/groups/members-save.php` (see its header for the full reasoning):
 * `App::isAdmin()`, then `AccountGuard::check(…, REACH_THIS_ORG, …)` for the
 * particular account, whose refusal reads exactly "That account could not
 * be found." for another organisation's account and a made-up number alike.
 * Changing somebody's flags changes who may approve money, so it goes
 * through the same guard as adding them.
 *
 * Only the five keys of `Departments::FLAGS` are ever read from the posted
 * `flags[…]` checkboxes; anything else posted is ignored, and a missing box
 * means "off" (`Departments::normaliseFlags()`).
 *
 * WHAT THIS CANNOT DO: add somebody who is not an ACTIVE member here (the
 * class and the database's composite key refuse it, with the same "could
 * not be found" words), or add anybody to a retired department. Flags can
 * still be changed on a retired department, on purpose (see members.php).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/517
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\ApiAuth;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Departments;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Gate 1 — the page-level gate.
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$listUrl = Site::url('admin/departments');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $listUrl, true, 302);
    exit();
}

$finish = static function (string $url, string $message, string $type): void {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url, true, 302);
    exit();
};

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $finish($listUrl, 'Invalid or expired form token. Please try again.', 'danger');
}

$siteId = Site::id();
$action = (string) ($_POST['action'] ?? '');
$deptId = (int) ($_POST['deptID'] ?? 0);
$userId = (int) ($_POST['userID'] ?? 0);
$posted = $_POST['flags'] ?? [];
$flags  = is_array($posted) === true ? $posted : [];

$dept = Departments::get($mysqli, $deptId, $siteId);
if ($dept === null) {
    $finish($listUrl, 'That department could not be found.', 'danger');
}
$rosterUrl = Site::url('admin/departments/members') . '?id=' . $deptId;

if ($action !== 'add' && $action !== 'remove' && $action !== 'flags') {
    $finish($rosterUrl, 'Unknown action.', 'danger');
}

// 🛡️ Gate 2 — may this administrator change THIS account here? check()
//    both decides and logs a refusal.
$verdict = AccountGuard::check($userId, AccountGuard::REACH_THIS_ORG, 'change department memberships of account #' . $userId);
if ($verdict !== AccountGuard::ALLOW) {
    $finish($rosterUrl, AccountGuard::message($verdict, AccountGuard::REACH_THIS_ORG), 'danger');
}

$actorId = ApiAuth::actorUserId();

$mysqli->begin_transaction();
try {
    if ($action === 'add') {
        $result = Departments::addMember($mysqli, $userId, $deptId, $siteId, $actorId, $flags);
    } elseif ($action === 'flags') {
        $result = Departments::setFlags($mysqli, $userId, $deptId, $siteId, $flags, $actorId);
    } else {
        $result = Departments::removeMember($mysqli, $userId, $deptId, $siteId, $actorId);
    }
    $mysqli->commit();
} catch (\Throwable $e) {
    $mysqli->rollback();
    Logger::errorPlatform('Departments', 'Error', 'DEPT_MEMBER_SAVE_FAILED', 'Failed to ' . $action . ' account #' . $userId . ' in department #' . $deptId, $e->getMessage());
    $finish($rosterUrl, t('error.database'), 'danger');
}

if ($result === 'not_found') {
    // 🏁 Not an active member here, or (for "flags") not in this department
    //    at all — the same words as AccountGuard's own refusal, on purpose.
    $finish($rosterUrl, AccountGuard::message(AccountGuard::NOT_FOUND, AccountGuard::REACH_THIS_ORG), 'danger');
}
if ($result === 'inactive') {
    $finish($rosterUrl, 'This department is retired. Reinstate it before adding people.', 'danger');
}
if ($result === 'unchanged') {
    $finish($rosterUrl, 'Nothing was changed.', 'info');
}
$done = ['add' => 'Added to the department.', 'flags' => 'Flags saved.', 'remove' => 'Removed from the department.'];
$finish($rosterUrl, $done[$action], 'success');
