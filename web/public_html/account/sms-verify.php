<?php
// Path: public_html/account/sms-verify.php
/**
 * Account — SMS verification code submit handler.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/272
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Site;
use Portal\Core\Sms;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$code   = preg_replace('/[^0-9]/', '', (string) ($_POST['code'] ?? ''));

if ($code !== null && $code !== '' && Sms::completeVerification($siteId, $userId, $code) === true) {
    $_SESSION['flash_msg']  = 'Phone number verified.';
    $_SESSION['flash_type'] = 'success';
} else {
    $_SESSION['flash_msg']  = 'Code invalid or expired — request a new one.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /account/sms');
exit();
