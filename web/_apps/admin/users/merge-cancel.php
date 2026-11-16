<?php
// Path: _apps/admin/users/merge-cancel.php
/**
 * -----------------------------------------------------------------------------
 * Cancel a merge request that is still waiting for the person (#1064)
 * -----------------------------------------------------------------------------
 * An administrator holding the permission may cancel a request started in THIS
 * organisation. A request number from elsewhere gets the same answer as one
 * that does not exist (`AccountMerge::cancel()`).
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

$raw       = $_POST['requestID'] ?? '';
$requestId = (is_string($raw) === true && preg_match('/^[0-9]{1,10}$/', $raw) === 1) ? (int) $raw : 0;
$actor     = App::user() ?? [];
$outcome   = AccountMerge::cancel($mysqli, $requestId, (int) ($actor['userID'] ?? 0), true, Site::id());

$_SESSION['flash_msg']  = $outcome === 'cancelled'
    ? 'The request was cancelled. Nothing was merged.'
    : 'That request was not found here, or is no longer waiting.';
$_SESSION['flash_type'] = $outcome === 'cancelled' ? 'success' : 'warning';

header('Location: ' . Site::url('admin/users/merge'));
exit();
