<?php
// Path: public_html/giving/gad-save.php
/**
 * Giving — Gift Aid declaration accept / withdraw handler.
 *
 * @package   Portal\Giving
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/266
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

if ($action === 'accept') {
    $address  = trim((string) ($_POST['address'] ?? ''));
    $postcode = trim((string) ($_POST['postcode'] ?? ''));
    if ($address === '' || $postcode === '') {
        $_SESSION['flash_msg']  = 'Address and postcode are required for Gift Aid.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /giving/gift-aid');
        exit();
    }
    $today = date('Y-m-d');
    $ip    = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $stmt = $db->prepare(
        'INSERT INTO tblGiftAidDeclaration (siteID, donorID, status, validFrom, address, postcode, acceptedAt, acceptedIP) '
        . 'VALUES (?, ?, "active", ?, ?, ?, NOW(), ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iissss', $siteId, $userId, $today, $address, $postcode, $ip);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('GiftAidAccepted', 'Gift Aid declaration accepted', $userId);
    $_SESSION['flash_msg']  = 'Thank you — your Gift Aid declaration has been recorded.';
    $_SESSION['flash_type'] = 'success';
} elseif ($action === 'withdraw' && Giving::canManage() === true) {
    $id = (int) ($_POST['declarationID'] ?? 0);
    $today = date('Y-m-d');
    $stmt = $db->prepare('UPDATE tblGiftAidDeclaration SET status = "withdrawn", validTo = ? WHERE declarationID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('sii', $today, $id, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('GiftAidWithdrawn', 'Withdrew declaration #' . $id, $userId);
    $_SESSION['flash_msg']  = 'Declaration withdrawn.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: /giving/gift-aid');
exit();
