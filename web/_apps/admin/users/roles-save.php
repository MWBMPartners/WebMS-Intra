<?php
// Path: _apps/admin/users/roles-save.php
/**
 * -----------------------------------------------------------------------------
 * Grant or remove an account's roles, IN THIS ORGANISATION 🏷️ (#516)
 * -----------------------------------------------------------------------------
 * The write half of the members page's Roles modal
 * (`web/_apps/admin/users/index.php`). Before this file existed, NOTHING
 * anywhere could ever put a row into `tblUserRoles` — the members page
 * showed a person's roles, the help page described where they live, but
 * no button, form or script could give one.
 *
 * WHO MAY DO THIS: an administrator of the organisation currently open
 * (their own organisation only), or a global administrator anywhere —
 * `Portal\Core\AccountGuard::check()` with `REACH_THIS_ORG` decides this
 * the same way it already decides who may flip a membership row's own
 * `isSiteAdmin` flag. A refusal looks EXACTLY like "that account could not
 * be found" (#503's standing rule) — never "you may not do that", which
 * would confirm the account exists in an organisation the caller cannot
 * see into.
 *
 * WHAT THIS PAGE CANNOT DO: it cannot grant a role belonging to a
 * DIFFERENT organisation — a posted `roleID` that is not in
 * `Roles::forSite()`'s list for the organisation open right now is simply
 * dropped, as if it had never been posted, and the composite foreign key
 * `fk_user_role_role_site` (migration 202) refuses it at the database
 * level too if that check is somehow bypassed. It cannot place a role for
 * somebody who is not an ACTIVE member of the organisation open right now
 * — `Roles::grant()` answers `not_found` for that, exactly as it does for
 * a role that does not exist.
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
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Roles;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ The page-level gate every admin/* page needs first — AccountGuard
//    only ever decides how far a change may REACH once someone is already
//    on an admin page, never whether they may be on one at all (its own
//    docblock is explicit about this).
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('admin/users'), true, 302);
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users'), true, 302);
    exit();
}

$userId = (int) ($_POST['userID'] ?? 0);
$posted = array_map('intval', (array) ($_POST['roles'] ?? []));

// 🛡️ #516: the same reach test as flipping this account's isSiteAdmin
//    flag for this organisation. check() (not verdict()) both decides AND
//    logs a refusal, because this is a real attempt to change something,
//    not merely drawing a page.
$verdict = AccountGuard::check($userId, AccountGuard::REACH_THIS_ORG, 'change roles of account #' . $userId);
if ($verdict !== AccountGuard::ALLOW) {
    $_SESSION['flash_msg']  = AccountGuard::message($verdict, AccountGuard::REACH_THIS_ORG);
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users'), true, 302);
    exit();
}

$siteId = Site::id();

// 🎯 Only roleIDs that genuinely belong to THIS organisation can ever be
//    granted here — a posted number for another organisation's role is
//    simply not in this list, so it is dropped exactly as if it had never
//    been posted at all (the composite foreign key on tblUserRoles would
//    refuse it anyway, but this keeps the ordinary path a clean no-op
//    rather than a caught database exception).
$valid  = array_column(Roles::forSite($mysqli, $siteId), 'roleID');
$wanted = array_values(array_intersect($posted, $valid));
$current = Roles::idsHeldBy($mysqli, $userId, $siteId);

$toGrant  = array_values(array_diff($wanted, $current));
$toRevoke = array_values(array_diff($current, $wanted));

$actorId = ApiAuth::actorUserId();
$given   = 0;
$removed = 0;
$sawNotFound = false;

$mysqli->begin_transaction();
try {
    foreach ($toGrant as $roleId) {
        $result = Roles::grant($mysqli, $userId, $roleId, $siteId, $actorId);
        if ($result === 'ok') {
            $given++;
        } elseif ($result === 'not_found') {
            // 🏁 The membership vanished between the AccountGuard check
            //    above and this write (a second admin removed them a
            //    moment ago) — not a fault, just a race. Reported below.
            $sawNotFound = true;
        }
    }
    foreach ($toRevoke as $roleId) {
        $result = Roles::revoke($mysqli, $userId, $roleId, $siteId, $actorId);
        if ($result === 'ok') {
            $removed++;
        }
    }
    $mysqli->commit();
} catch (\Throwable $e) {
    $mysqli->rollback();
    Logger::errorPlatform('Roles', 'Error', 'ROLES_SAVE_FAILED', 'Failed to update roles for account #' . $userId, $e->getMessage());
    $_SESSION['flash_msg']  = t('error.database');
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users'), true, 302);
    exit();
}

if ($sawNotFound === true && $given === 0 && $removed === 0) {
    $_SESSION['flash_msg']  = AccountGuard::message(AccountGuard::NOT_FOUND, AccountGuard::REACH_THIS_ORG);
    $_SESSION['flash_type'] = 'danger';
} elseif ($given === 0 && $removed === 0) {
    $_SESSION['flash_msg']  = 'Nothing was changed.';
    $_SESSION['flash_type'] = 'info';
} else {
    $_SESSION['flash_msg']  = 'Roles updated: ' . $given . ' given, ' . $removed . ' removed.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: ' . Site::url('admin/users'), true, 302);
exit();
