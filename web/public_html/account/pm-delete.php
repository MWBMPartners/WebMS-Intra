<?php
// Path: public_html/account/pm-delete.php
/**
 * Account — forget a saved payment method.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db       = App::db();
$siteId   = Site::id();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$methodId = (int) ($_POST['methodID'] ?? 0);

if ($methodId > 0) {
    $stmt = $db->prepare('DELETE FROM tblPaymentMethod WHERE methodID = ? AND siteID = ? AND userID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('iii', $methodId, $siteId, $userId);
        $stmt->execute();
        $stmt->close();
    }
    $_SESSION['flash_msg']  = 'Payment method removed.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: /account/payment-methods');
exit();
