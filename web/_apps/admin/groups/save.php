<?php
// Path: _apps/admin/groups/save.php
/**
 * -----------------------------------------------------------------------------
 * Create, rename, retire, reinstate or delete a user group 👥 (#517)
 * -----------------------------------------------------------------------------
 * The write half of `admin/groups/index.php` — see that file's header for
 * what a user group is for.
 *
 * WHO MAY DO THIS: any administrator of the organisation currently open
 * (`App::isAdmin()`), the same gate as the page. No person's account is
 * changed here, so `AccountGuard` is not involved (the #516
 * `admin/roles/save.php` reasoning); adding and removing PEOPLE happens in
 * `admin/groups/members-save.php`, which does use it.
 *
 * WHY EVERY LOOKUP IS BY (groupID, siteID): a group number that belongs to
 * another organisation costs one lookup that finds nothing and gets exactly
 * the same words — "That group could not be found." — as a number that does
 * not exist at all, so this page never confirms that another organisation
 * has a group with that number.
 *
 * DELETE RULES (the owner's answer Q2, 21 September 2026): a group may be
 * deleted only while it has no members, owns no assets and is named by no
 * workflow step of this organisation. Otherwise the answer lists what is in
 * the way and suggests retiring it instead — retiring keeps its history and
 * is always allowed.
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

use Portal\Core\ApiAuth;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\UserGroups;

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$backUrl = Site::url('admin/groups');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $backUrl, true, 302);
    exit();
}

// 💬 One place that sets the message and goes back to the list, so every
//    answer below is a single line and they all behave the same way.
$finish = static function (string $message, string $type) use ($backUrl): void {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $backUrl, true, 302);
    exit();
};

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $finish('Invalid or expired form token. Please try again.', 'danger');
}

$siteId   = Site::id();
$actorId  = ApiAuth::actorUserId();
$action   = (string) ($_POST['action'] ?? '');
$groupId  = (int) ($_POST['groupID'] ?? 0);
$notFound = 'That group could not be found.';

// ✍️ The name and description, checked here so the person gets a calm
//    message; UserGroups checks the same limits again as a belt. The
//    description limit is in BYTES because the column is a MySQL TEXT.
$readNameAndDescription = static function () use ($finish): array {
    $name        = trim((string) ($_POST['groupName'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > UserGroups::NAME_MAX) {
        $finish('A group name is required, and may be at most ' . UserGroups::NAME_MAX . ' characters.', 'danger');
    }
    if (strlen($description) > UserGroups::DESCRIPTION_MAX_BYTES) {
        $finish('That description is too long.', 'danger');
    }
    return [$name, $description !== '' ? $description : null];
};

try {
    if ($action === 'create') {
        [$name, $description] = $readNameAndDescription();
        UserGroups::create($mysqli, $siteId, $name, $description, $actorId);
        $finish('Group added.', 'success');
    }

    if ($action === 'update') {
        [$name, $description] = $readNameAndDescription();
        $result = UserGroups::update($mysqli, $groupId, $siteId, $name, $description, $actorId);
        if ($result === 'not_found') {
            $finish($notFound, 'danger');
        }
        $finish('Group updated.', 'success');
    }

    if ($action === 'retire' || $action === 'reinstate') {
        $result = UserGroups::setActive($mysqli, $groupId, $siteId, $action === 'reinstate', $actorId);
        if ($result === 'not_found') {
            $finish($notFound, 'danger');
        }
        if ($result === 'unchanged') {
            $finish('Nothing was changed.', 'info');
        }
        $finish($action === 'retire' ? 'Group retired.' : 'Group reinstated.', 'success');
    }

    if ($action === 'delete') {
        $result = UserGroups::delete($mysqli, $groupId, $siteId, $actorId);
        if ($result === 'not_found') {
            $finish($notFound, 'danger');
        }
        if ($result === 'in_use') {
            // 🧾 Say exactly what is in the way (only the parts that apply).
            //    The class answered 'in_use', so the group exists here and
            //    counting again costs nothing another organisation could
            //    learn from.
            $blockers = UserGroups::deleteBlockers($mysqli, $groupId, $siteId);
            $parts    = [];
            if ($blockers['members'] > 0) {
                $parts[] = 'has ' . $blockers['members'] . ' member' . ($blockers['members'] === 1 ? '' : 's');
            }
            if ($blockers['assetOwners'] > 0) {
                $parts[] = 'owns ' . $blockers['assetOwners'] . ' asset' . ($blockers['assetOwners'] === 1 ? '' : 's');
            }
            if ($blockers['workflowSteps'] > 0) {
                $parts[] = 'is named by ' . $blockers['workflowSteps'] . ' workflow step' . ($blockers['workflowSteps'] === 1 ? '' : 's');
            }
            if (count($parts) === 0) {
                // Only reachable if something started pointing at the group
                // between the count and the delete (the caught error 1451).
                $finish('This group is still in use here. Retire it instead.', 'danger');
            }
            // "has 1 member, owns 1 asset and is named by 1 workflow step"
            $last = array_pop($parts);
            $list = count($parts) > 0 ? implode(', ', $parts) . ' and ' . $last : $last;
            $finish('This group cannot be deleted yet: it ' . $list . ' here. Remove those first, or retire the group instead.', 'danger');
        }
        $finish('Group deleted.', 'success');
    }
} catch (\InvalidArgumentException $e) {
    // The page checks the same limits first, so this is only reached if the
    // two ever disagree; the class's own sentence is safe to show.
    $finish($e->getMessage(), 'danger');
} catch (\mysqli_sql_exception $e) {
    Logger::errorPlatform('UserGroups', 'Error', 'GROUP_SAVE_FAILED', 'Failed to ' . $action . ' group #' . $groupId, $e->getMessage());
    $finish(t('error.database'), 'danger');
}

$finish('Unknown action.', 'danger');
