<?php
// Path: public_html/auth/account/unlink.php
/**
 * -----------------------------------------------------------------------------
 * Account — Unlink External Provider Handler
 * -----------------------------------------------------------------------------
 * Removes a linked external identity provider (MS365, Google) from the current
 * user's account. Safety-checked: will not unlink if it is the user's only
 * login method.
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.5.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;

Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    header('Location: /account?error=unlink_csrf');
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$linkID = (int) ($_POST['linkID'] ?? 0);

if ($linkID === 0) {
    header('Location: /account?error=unlink_fail');
    exit();
}

$result = Auth::unlinkAccount($userId, $linkID, $mysqli);

if ($result['success'] === true) {
    header('Location: /account?unlinked=1');
    exit();
}

header('Location: /account?error=unlink_fail');
exit();
