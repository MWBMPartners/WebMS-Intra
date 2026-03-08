<?php
// Path: public_html/announcements/delete.php
/**
 * -----------------------------------------------------------------------------
 * Announcements — Delete Handler (Soft Delete)
 * -----------------------------------------------------------------------------
 * Marks an announcement as deleted (POST only, admin only).
 *
 * @package   Portal\Announcements
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/89
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /announcements/manage');
    exit();
}

Auth::requireLogin();

if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = 'You do not have permission to manage announcements.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /announcements');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /announcements/manage');
    exit();
}

$announcementId = (int) ($_POST['announcementID'] ?? 0);
$siteId         = Site::id();
$userId         = (int) ($_SESSION['user_id'] ?? 0);

if ($announcementId <= 0) {
    $_SESSION['flash_msg']  = 'Invalid announcement.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /announcements/manage');
    exit();
}

// 📋 Soft delete
$stmt = $mysqli->prepare(
    'UPDATE tblAnnouncements SET isDeleted = 1, updatedByID = ? WHERE announcementID = ? AND siteID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $userId, $announcementId, $siteId);
    $stmt->execute();
    $stmt->close();
}

Logger::activity('AnnouncementDeleted', 'Deleted announcement ID: ' . $announcementId, $userId);

$_SESSION['flash_msg']  = 'Announcement deleted.';
$_SESSION['flash_type'] = 'info';
header('Location: /announcements/manage');
exit();
