<?php
// Path: _apps/account/merge-save.php
/**
 * -----------------------------------------------------------------------------
 * The person agrees to, or cancels, a merge of their accounts (#1064, D-190)
 * -----------------------------------------------------------------------------
 * Reads the form and the browser token cookie, asks `AccountMerge::agree()` or
 * `cancel()`, and sets or clears the cookie. The proof that the person has just
 * signed in is the #731 one (`SelfServicePassword`), passed in as a fact and
 * SPENT after it is used, so one sign-in agrees once.
 *
 * @package   Portal\Account
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
use Portal\Core\Router;
use Portal\Core\SelfServicePassword;
use Portal\Core\Site;

Auth::requireLogin();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Router::renderError(400);
    return;
}
$me = (int) ((App::user() ?? [])['userID'] ?? 0);

$digits = static function (string $key): int {
    $raw = $_POST[$key] ?? '';

    return (is_string($raw) === true && preg_match('/^[0-9]{1,10}$/', $raw) === 1) ? (int) $raw : 0;
};
$requestId = $digits('requestID');
$action    = is_string($_POST['action'] ?? null) === true ? $_POST['action'] : '';
$https     = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
$cookieKey = AccountMerge::cookieName($https);
$token     = is_string($_COOKIE[$cookieKey] ?? null) === true ? $_COOKIE[$cookieKey] : '';
$back      = Site::url('account/merge') . '?request=' . $requestId;

// 🍪 On https the `__Host-` name (AccountMerge::cookieName): Secure, Path=/ and
//    no Domain, so a neighbouring sub-domain cannot plant a token. Strict, so
//    no other site's link carries it.
$cookie = static function (string $value, int $expires) use ($cookieKey, $https): void {
    setcookie($cookieKey, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $https,
    ]);
};

if ($action === 'cancel') {
    $outcome = AccountMerge::cancel($mysqli, $requestId, $me, false, 0);
    if ($outcome === 'not_found') {
        Router::renderError(404);
        return;
    }
    $_SESSION['flash_msg']  = $outcome === 'cancelled' ? 'The request was cancelled. Nothing was merged.' : 'This request is no longer open.';
    $_SESSION['flash_type'] = 'info';
    header('Location: ' . $back);
    exit();
}
if ($action !== 'agree') {
    Router::renderError(400);
    return;
}

$result = AccountMerge::agree(
    $mysqli,
    $requestId,
    $me,
    SelfServicePassword::recentlyProvedThemselves($me),
    $token,
    $digits('typedDuplicate'),
    ($_POST['moveSignIns'] ?? '') === '1'
);

switch ($result['outcome']) {
    case AccountMerge::R_NOT_FOUND:
        Router::renderError(404);
        return;
    case 'agreed_first':
        SelfServicePassword::spendProof();
        $cookie($result['token'], time() + AccountMerge::AGREE_WINDOW_SECONDS);
        $_SESSION['flash_msg']  = 'You have agreed for this account. Now sign out, sign in to your other account in this browser, and agree there within 30 minutes.';
        $_SESSION['flash_type'] = 'success';
        break;
    case 'completed':
        SelfServicePassword::spendProof();
        $cookie('', time() - 3600);
        $_SESSION['flash_msg']  = 'Your two accounts have been merged. A message has been sent to both e-mail addresses.';
        $_SESSION['flash_type'] = 'success';
        break;
    case 'done_already':
        $_SESSION['flash_msg']  = 'These accounts have already been merged.';
        $_SESSION['flash_type'] = 'info';
        break;
    case 'needs_proof':
        $_SESSION['flash_msg']  = 'Please sign out and sign in to this account again first, then agree within five minutes.';
        $_SESSION['flash_type'] = 'warning';
        break;
    case 'typed_wrong':
        $_SESSION['flash_msg']  = 'The number you typed is not the number of the account that will be closed. Nothing was changed.';
        $_SESSION['flash_type'] = 'warning';
        break;
    case 'cancelled':
    case 'expired':
        $_SESSION['flash_msg']  = 'This request is no longer open.';
        $_SESSION['flash_type'] = 'info';
        break;
    default:
        // The PERSON's words, never the administrator's sentence about permissions.
        $_SESSION['flash_msg']  = AccountMerge::personRefusalWords($result['outcome']);
        $_SESSION['flash_type'] = 'warning';
}
header('Location: ' . $back);
exit();
