<?php
// Path: _apps/admin/users/merge-start.php
/**
 * -----------------------------------------------------------------------------
 * Ask the person to agree to a merge (#1064, D-190)
 * -----------------------------------------------------------------------------
 * Records a request and e-mails both accounts. It does NOT merge: the person
 * completes it by signing in to both accounts. `AccountMerge::start()` re-asks
 * every refusal under the two account locks; this file only reads the form.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1064
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\AccountMerge;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\Router;
use Portal\Core\Site;

if (App::isAdmin() === false || App::can(Capabilities::ACCOUNTS_MERGE) === false) {
    Router::renderError(403);
    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Router::renderError(400);
    return;
}

$number = static function (string $key): int {
    $raw = $_POST[$key] ?? '';

    return (is_string($raw) === true && preg_match('/^[0-9]{1,10}$/', $raw) === 1) ? (int) $raw : 0;
};
$keptId      = $number('keep');
$duplicateId = $number('duplicate');

$actor  = App::user() ?? [];
$result = AccountMerge::start(
    $mysqli,
    (int) ($actor['userID'] ?? 0),
    AccountGuard::actorIsGlobal(),
    App::isRootAdmin(),
    Site::id(),
    AccountGuard::isSingleOrganisation(),
    $keptId,
    $duplicateId
);

if ($result['outcome'] === 'started') {
    $_SESSION['flash_msg']  = 'The person has been asked to agree, at both accounts\' e-mail addresses. Nothing changes until they sign in to both accounts and confirm.';
    $_SESSION['flash_type'] = 'success';
} elseif ($result['outcome'] === 'already_waiting') {
    $_SESSION['flash_msg']  = 'The person has already been asked about these two accounts, and has not answered yet.';
    $_SESSION['flash_type'] = 'info';
} else {
    $_SESSION['flash_msg']  = AccountMerge::refusalWords($result['outcome']);
    $_SESSION['flash_type'] = 'warning';
}

header('Location: ' . Site::url('admin/users/merge') . '?keep=' . $keptId . '&duplicate=' . $duplicateId);
exit();
