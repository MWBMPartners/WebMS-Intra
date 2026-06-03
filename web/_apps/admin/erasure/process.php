<?php
// Path: public_html/admin/erasure/process.php
/**
 * Admin — execute or cancel an erasure request.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GdprEraser;
use Portal\Core\Logger;
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

$db        = App::db();
$siteId    = Site::id();
$adminId   = (int) ($_SESSION['user_id'] ?? 0);
$requestId = (int) ($_POST['requestID'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');

$row = null;
$stmt = $db->prepare('SELECT requestID, userID, status, subjectEmail FROM tblErasureRequest WHERE requestID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $requestId, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($row === null) {
    $_SESSION['flash_msg']  = 'Erasure request not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/erasure-requests');
    exit();
}

if ($action === 'cancel') {
    $u = $db->prepare('UPDATE tblErasureRequest SET status = "cancelled", processedAt = NOW(), processedByID = ? WHERE requestID = ?');
    if ($u !== false) {
        $u->bind_param('ii', $adminId, $requestId);
        $u->execute();
        $u->close();
    }
    Logger::activity('GdprErasureCancelled', 'Cancelled erasure request #' . $requestId, $adminId);
    $_SESSION['flash_msg']  = 'Erasure request cancelled.';
    $_SESSION['flash_type'] = 'info';
    header('Location: /admin/erasure-requests');
    exit();
}

if ($action !== 'execute' || (string) $row['status'] !== 'pending_review') {
    $_SESSION['flash_msg']  = 'Request is not in a reviewable state.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/erasure-requests');
    exit();
}

// Flip to processing first so a concurrent click can't double-run.
$lock = $db->prepare('UPDATE tblErasureRequest SET status = "processing" WHERE requestID = ? AND status = "pending_review"');
$claimed = false;
if ($lock !== false) {
    $lock->bind_param('i', $requestId);
    $lock->execute();
    $claimed = $lock->affected_rows > 0;
    $lock->close();
}
if ($claimed === false) {
    $_SESSION['flash_msg']  = 'Another admin is processing this request.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/erasure-requests');
    exit();
}

$userId = (int) $row['userID'];
if ($userId === 0) {
    $u = $db->prepare('UPDATE tblErasureRequest SET status = "failed" WHERE requestID = ?');
    if ($u !== false) {
        $u->bind_param('i', $requestId);
        $u->execute();
        $u->close();
    }
    $_SESSION['flash_msg']  = 'No user attached to this request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/erasure-requests');
    exit();
}

GdprEraser::execute($requestId, $userId, $adminId);
Logger::activity('GdprErasureExecuted', 'Executed erasure request #' . $requestId . ' for user #' . $userId, $adminId);

$_SESSION['flash_msg']  = 'Erasure completed. Audit chain verifiable on the report page.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/erasure-requests/report?id=' . $requestId);
exit();
