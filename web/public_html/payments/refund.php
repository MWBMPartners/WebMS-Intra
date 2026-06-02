<?php
// Path: public_html/payments/refund.php
/**
 * Payments — admin-triggered refund.
 *
 * @package   Portal\Payments
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Payments;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$paymentId = (int) ($_POST['paymentID'] ?? 0);

if ($paymentId > 0 && Payments::refund($paymentId, $siteId) === true) {
    Logger::activity('PaymentRefunded', 'Refunded payment #' . $paymentId, $userId);
    $_SESSION['flash_msg']  = 'Refund issued.';
    $_SESSION['flash_type'] = 'success';
} else {
    $_SESSION['flash_msg']  = 'Refund failed — check provider logs.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /payments');
exit();
