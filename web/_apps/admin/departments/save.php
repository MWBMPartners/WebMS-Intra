<?php
// Path: _apps/admin/departments/save.php
/**
 * -----------------------------------------------------------------------------
 * Create, rename, retire, reinstate or delete a department 🏢 (#517)
 * -----------------------------------------------------------------------------
 * The write half of `admin/departments/index.php` — see that file's header.
 *
 * WHO MAY DO THIS: any administrator of the organisation currently open
 * (`App::isAdmin()`). No person's account changes here, so `AccountGuard`
 * is not involved; people are added and flagged in
 * `admin/departments/members-save.php`, which does use it.
 *
 * Every lookup is by (deptID, siteID): another organisation's department
 * number and a made-up one both get "That department could not be found."
 *
 * DELETE RULES (the owner's answer Q2, 21 September 2026): only while the
 * department has no members, owns no assets and has no expense claims.
 * Otherwise the answer lists what is in the way and suggests retiring it.
 * An expense claim also makes the DATABASE refuse (error 1451); the class
 * catches that, so a claim that appears between the count and the delete
 * still gets a calm answer, never a crashed page.
 *
 * RETIRING (the owner's answer Q3): stops new claims; claims already
 * submitted are finished by the department's own approvers. A department
 * whose on/off value is empty (NULL, from a hand edit) counts as retired
 * and can be reinstated from here — `Departments::setActive()` explains why
 * that needed care.
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
use Portal\Core\Departments;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$backUrl = Site::url('admin/departments');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $backUrl, true, 302);
    exit();
}

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
$deptId   = (int) ($_POST['deptID'] ?? 0);
$notFound = 'That department could not be found.';

// ✍️ Checked here for a calm message; Departments checks again as a belt.
$readNameAndCode = static function () use ($finish): array {
    $name = trim((string) ($_POST['deptName'] ?? ''));
    $code = trim((string) ($_POST['deptCode'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > Departments::NAME_MAX) {
        $finish('A department name is required, and may be at most ' . Departments::NAME_MAX . ' characters.', 'danger');
    }
    if (mb_strlen($code, 'UTF-8') > Departments::CODE_MAX) {
        $finish('A short code may be at most ' . Departments::CODE_MAX . ' characters.', 'danger');
    }
    return [$name, $code !== '' ? $code : null];
};

try {
    if ($action === 'create') {
        [$name, $code] = $readNameAndCode();
        Departments::create($mysqli, $siteId, $name, $code, $actorId);
        $finish('Department added.', 'success');
    }

    if ($action === 'update') {
        [$name, $code] = $readNameAndCode();
        $result = Departments::update($mysqli, $deptId, $siteId, $name, $code, $actorId);
        if ($result === 'not_found') {
            $finish($notFound, 'danger');
        }
        $finish('Department updated.', 'success');
    }

    if ($action === 'retire' || $action === 'reinstate') {
        $result = Departments::setActive($mysqli, $deptId, $siteId, $action === 'reinstate', $actorId);
        if ($result === 'not_found') {
            $finish($notFound, 'danger');
        }
        if ($result === 'unchanged') {
            $finish('Nothing was changed.', 'info');
        }
        $finish($action === 'retire' ? 'Department retired.' : 'Department reinstated.', 'success');
    }

    if ($action === 'delete') {
        $result = Departments::delete($mysqli, $deptId, $siteId, $actorId);
        if ($result === 'not_found') {
            $finish($notFound, 'danger');
        }
        if ($result === 'in_use') {
            $blockers = Departments::deleteBlockers($mysqli, $deptId, $siteId);
            $parts    = [];
            if ($blockers['members'] > 0) {
                $parts[] = 'has ' . $blockers['members'] . ' member' . ($blockers['members'] === 1 ? '' : 's');
            }
            if ($blockers['assetOwners'] > 0) {
                $parts[] = 'owns ' . $blockers['assetOwners'] . ' asset' . ($blockers['assetOwners'] === 1 ? '' : 's');
            }
            if ($blockers['expenseClaims'] > 0) {
                $parts[] = 'has ' . $blockers['expenseClaims'] . ' expense claim' . ($blockers['expenseClaims'] === 1 ? '' : 's');
            }
            if (count($parts) === 0) {
                // Only reachable if a claim appeared between the count and
                // the delete (the database's own refusal, error 1451).
                $finish('This department is still in use here. Retire it instead.', 'danger');
            }
            // "has 1 member, owns 1 asset and is named by 1 workflow step"
            $last = array_pop($parts);
            $list = count($parts) > 0 ? implode(', ', $parts) . ' and ' . $last : $last;
            $finish('This department cannot be deleted yet: it ' . $list . ' here. Remove those first, or retire the department instead.', 'danger');
        }
        $finish('Department deleted.', 'success');
    }
} catch (\InvalidArgumentException $e) {
    $finish($e->getMessage(), 'danger');
} catch (\mysqli_sql_exception $e) {
    Logger::errorPlatform('Departments', 'Error', 'DEPT_SAVE_FAILED', 'Failed to ' . $action . ' department #' . $deptId, $e->getMessage());
    $finish(t('error.database'), 'danger');
}

$finish('Unknown action.', 'danger');
