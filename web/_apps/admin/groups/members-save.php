<?php
// Path: _apps/admin/groups/members-save.php
/**
 * -----------------------------------------------------------------------------
 * Add a person to, or remove a person from, a user group 👥 (#517)
 * -----------------------------------------------------------------------------
 * The write half of `admin/groups/members.php`.
 *
 * WHO MAY DO THIS: an administrator of the organisation currently open, for
 * its own groups, or a global administrator anywhere (owner, 21 September
 * 2026). Two gates, in this order:
 *   1. `App::isAdmin()` — may this person use an admin page here at all?
 *   2. `AccountGuard::check(…, REACH_THIS_ORG, …)` — may they change THIS
 *      account's standing in this organisation? The same reach test as
 *      giving someone a role (`admin/users/roles-save.php`). A refusal reads
 *      exactly "That account could not be found." — identical for another
 *      organisation's account and a number that does not exist (#503), and
 *      logged by `check()` either way. A site administrator therefore cannot
 *      add a global administrator's account here, exactly as for roles.
 *
 * The group itself is looked up first, by (groupID, siteID), because where
 * to send the person back to depends on it: another organisation's group
 * and a made-up number both get "That group could not be found." and go
 * back to the list, having revealed nothing about any account.
 *
 * WHAT THIS CANNOT DO: add somebody who is not an ACTIVE member of this
 * organisation. On a single-organisation portal `AccountGuard` skips its own
 * membership test; `UserGroups::addMember()` and the database's composite
 * key refuse such a person instead, with the same "could not be found"
 * words. It cannot add anybody to a retired group.
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
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\UserGroups;

// 🛡️ Gate 1 — the page-level gate every admin/* page needs first.
//    AccountGuard only ever decides how far a change may REACH once someone
//    is already on an admin page, never whether they may be on one at all.
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$listUrl = Site::url('admin/groups');

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

$siteId  = Site::id();
$action  = (string) ($_POST['action'] ?? '');
$groupId = (int) ($_POST['groupID'] ?? 0);
$userId  = (int) ($_POST['userID'] ?? 0);

$group = UserGroups::get($mysqli, $groupId, $siteId);
if ($group === null) {
    $finish($listUrl, 'That group could not be found.', 'danger');
}
$rosterUrl = Site::url('admin/groups/members') . '?id=' . $groupId;

if ($action !== 'add' && $action !== 'remove') {
    $finish($rosterUrl, 'Unknown action.', 'danger');
}

// 🛡️ Gate 2 — may this administrator change THIS account here? check()
//    (not verdict()) both decides AND logs a refusal, because this is a real
//    attempt to change something, not merely drawing a page.
$verdict = AccountGuard::check($userId, AccountGuard::REACH_THIS_ORG, 'change group memberships of account #' . $userId);
if ($verdict !== AccountGuard::ALLOW) {
    $finish($rosterUrl, AccountGuard::message($verdict, AccountGuard::REACH_THIS_ORG), 'danger');
}

$actorId = ApiAuth::actorUserId();

$mysqli->begin_transaction();
try {
    $result = $action === 'add'
        ? UserGroups::addMember($mysqli, $userId, $groupId, $siteId, $actorId)
        : UserGroups::removeMember($mysqli, $userId, $groupId, $siteId, $actorId);
    $mysqli->commit();
} catch (\Throwable $e) {
    $mysqli->rollback();
    Logger::errorPlatform('UserGroups', 'Error', 'GROUP_MEMBER_SAVE_FAILED', 'Failed to ' . $action . ' account #' . $userId . ' in group #' . $groupId, $e->getMessage());
    $finish($rosterUrl, t('error.database'), 'danger');
}

if ($result === 'not_found') {
    // 🏁 Not an ACTIVE member here (possible on a single-organisation
    //    portal, where AccountGuard skips its own membership test), or the
    //    membership vanished between the check and the write. The same
    //    words as AccountGuard's own refusal, on purpose.
    $finish($rosterUrl, AccountGuard::message(AccountGuard::NOT_FOUND, AccountGuard::REACH_THIS_ORG), 'danger');
}
if ($result === 'inactive') {
    $finish($rosterUrl, 'This group is retired. Reinstate it before adding people.', 'danger');
}
if ($result === 'unchanged') {
    $finish($rosterUrl, 'Nothing was changed.', 'info');
}
$finish($rosterUrl, $action === 'add' ? 'Added to the group.' : 'Removed from the group.', 'success');
