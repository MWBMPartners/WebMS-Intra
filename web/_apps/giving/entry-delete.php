<?php
// Path: public_html/giving/entry-delete.php
/**
 * Giving — delete an entry (treasurer only).
 *
 * @package   Portal\Giving
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/266
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$id     = (int) ($_POST['entryID'] ?? 0);

if ($id > 0) {
    $stmt = $db->prepare('DELETE FROM tblGivingEntry WHERE entryID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $id, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('GivingEntryDeleted', 'Deleted entry #' . $id, $userId);
    $_SESSION['flash_msg']  = 'Entry deleted.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: /giving/manage');
exit();
