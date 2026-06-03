<?php
// Path: public_html/admin/photos/moderate.php
/**
 * Admin — moderation POST handler: approve or reject a pending photo.
 * Approval moves the file from queue/ into album-N/. Rejection emails
 * the uploader (best-effort) and leaves the file in queue/ for cleanup.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/236
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\Photos;
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
$photoId   = (int) ($_POST['photoID'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');
$albumId   = (int) ($_POST['albumID'] ?? 0);
$visibility = (string) ($_POST['visibility'] ?? 'inherit');
$reason    = trim((string) ($_POST['rejectionReason'] ?? ''));

if (in_array($visibility, ['public','volunteers','staff','admin_only','inherit'], true) === false) {
    $visibility = 'inherit';
}

$photo = null;
$stmt = $db->prepare('SELECT * FROM tblPhoto WHERE photoID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $photoId, $siteId);
    $stmt->execute();
    $photo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($photo === null) {
    $_SESSION['flash_msg']  = 'Photo not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/photos/queue');
    exit();
}

if ($action === 'approve') {
    $albumOrNull = $albumId > 0 ? $albumId : null;

    // Move file out of queue/ if an album was chosen.
    $newRel = (string) $photo['filePath'];
    if ($albumOrNull !== null) {
        $newRel = Photos::moveToAlbum((string) $photo['filePath'], $albumOrNull);
    }

    $u = $db->prepare(
        'UPDATE tblPhoto SET status = "approved", albumID = ?, visibility = ?, filePath = ?, '
        . 'moderatedByID = ?, moderatedAt = NOW() WHERE photoID = ?'
    );
    if ($u !== false) {
        $u->bind_param('issii', $albumOrNull, $visibility, $newRel, $adminId, $photoId);
        $u->execute();
        $u->close();
    }
    Logger::activity('PhotoApproved', 'Approved photo #' . $photoId, $adminId);
    $_SESSION['flash_msg']  = 'Photo approved.';
    $_SESSION['flash_type'] = 'success';
} elseif ($action === 'reject') {
    $u = $db->prepare(
        'UPDATE tblPhoto SET status = "rejected", rejectionReason = ?, moderatedByID = ?, moderatedAt = NOW() WHERE photoID = ?'
    );
    if ($u !== false) {
        $u->bind_param('sii', $reason, $adminId, $photoId);
        $u->execute();
        $u->close();
    }

    // Email the uploader.
    if ($photo['uploadedByUserID'] !== null) {
        $email = null;
        $name  = null;
        $eStmt = $db->prepare('SELECT emailAddress, fullName FROM tblUsers WHERE userID = ? LIMIT 1');
        if ($eStmt !== false) {
            $uid = (int) $photo['uploadedByUserID'];
            $eStmt->bind_param('i', $uid);
            $eStmt->execute();
            $row = $eStmt->get_result()->fetch_assoc();
            $eStmt->close();
            $email = $row['emailAddress'] ?? null;
            $name  = $row['fullName']     ?? null;
        }
        if ($email !== null && $email !== '') {
            $body = '<p>Hi ' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>Your recent photo upload was not approved for publication.</p>'
                . ($reason !== '' ? '<p><strong>Reason:</strong> ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</p>' : '')
                . '<p>You can re-upload an updated version anytime.</p>';
            try {
                Mailer::send((string) $email, 'Photo rejected: please review', $body);
            } catch (\Throwable $ignored) {
                // Best effort.
            }
        }
    }
    Logger::activity('PhotoRejected', 'Rejected photo #' . $photoId . ' (' . $reason . ')', $adminId);
    $_SESSION['flash_msg']  = 'Photo rejected and uploader notified.';
    $_SESSION['flash_type'] = 'info';
}

header('Location: /admin/photos/queue');
exit();
