<?php
// Path: public_html/admin/integrations/zoom/disconnect.php
/**
 * Admin — Zoom disconnect (drops the tblZoomAccount row; cascade nukes
 * meetings).
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/274
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db        = App::db();
$siteId    = Site::id();
$accountId = (int) ($_POST['accountID'] ?? 0);
$userId    = (int) ($_SESSION['user_id'] ?? 0);

// Admins can drop any account on their site; users can only drop their own.
if (App::isAdmin() === true) {
    $stmt = $db->prepare('DELETE FROM tblZoomAccount WHERE accountID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $accountId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    $returnTo = '/admin/integrations/zoom';
} else {
    $stmt = $db->prepare('DELETE FROM tblZoomAccount WHERE accountID = ? AND siteID = ? AND userID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('iii', $accountId, $siteId, $userId);
        $stmt->execute();
        $stmt->close();
    }
    $returnTo = '/account/integrations/zoom';
}

$_SESSION['flash_msg']  = 'Zoom account disconnected.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . $returnTo);
exit();
