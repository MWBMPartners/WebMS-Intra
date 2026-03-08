<?php
// Path: public_html/announcements/save.php
/**
 * -----------------------------------------------------------------------------
 * Announcements — Save Handler
 * -----------------------------------------------------------------------------
 * Creates or updates an announcement (POST only, admin only).
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

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$announcementId = (int) ($_POST['announcementID'] ?? 0);
$title          = trim($_POST['title'] ?? '');
$body           = trim($_POST['body'] ?? '');
$priority       = $_POST['priority'] ?? 'normal';
$isPinned       = isset($_POST['isPinned']) === true ? 1 : 0;
$isPublished    = isset($_POST['isPublished']) === true ? 1 : 0;
$publishAt      = trim($_POST['publishAt'] ?? '') !== '' ? $_POST['publishAt'] : null;
$expiresAt      = trim($_POST['expiresAt'] ?? '') !== '' ? $_POST['expiresAt'] : null;

// 🔍 Validate
if ($title === '' || $body === '') {
    $_SESSION['flash_msg']  = 'Title and body are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /announcements/manage?edit=' . $announcementId);
    exit();
}

$validPriorities = ['normal', 'important', 'urgent'];
if (in_array($priority, $validPriorities, true) === false) {
    $priority = 'normal';
}

// 📋 Generate slug from title
$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
if (strlen($slug) > 200) {
    $slug = substr($slug, 0, 200);
}

if ($announcementId > 0) {
    // 📋 Update existing
    // 🔍 Ensure slug is unique (excluding self)
    $chkStmt = $mysqli->prepare(
        'SELECT announcementID FROM tblAnnouncements WHERE slug = ? AND siteID = ? AND announcementID != ? AND isDeleted = 0 LIMIT 1'
    );
    if ($chkStmt !== false) {
        $chkStmt->bind_param('sii', $slug, $siteId, $announcementId);
        $chkStmt->execute();
        if ($chkStmt->get_result()->num_rows > 0) {
            $slug .= '-' . $announcementId;
        }
        $chkStmt->close();
    }

    $stmt = $mysqli->prepare(
        'UPDATE tblAnnouncements SET title = ?, slug = ?, body = ?, priority = ?, '
        . 'isPinned = ?, isPublished = ?, publishAt = ?, expiresAt = ?, updatedByID = ? '
        . 'WHERE announcementID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param(
            'ssssiisssii',
            $title, $slug, $body, $priority,
            $isPinned, $isPublished, $publishAt, $expiresAt, $userId,
            $announcementId, $siteId
        );
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('AnnouncementUpdated', 'Updated announcement: ' . $title, $userId);
    $_SESSION['flash_msg']  = 'Announcement updated.';
    $_SESSION['flash_type'] = 'success';
} else {
    // 📋 Create new
    // 🔍 Ensure slug is unique
    $chkStmt = $mysqli->prepare(
        'SELECT announcementID FROM tblAnnouncements WHERE slug = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
    );
    if ($chkStmt !== false) {
        $chkStmt->bind_param('si', $slug, $siteId);
        $chkStmt->execute();
        if ($chkStmt->get_result()->num_rows > 0) {
            $slug .= '-' . time();
        }
        $chkStmt->close();
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO tblAnnouncements (siteID, title, slug, body, priority, isPinned, isPublished, publishAt, expiresAt, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param(
            'issssiissi',
            $siteId, $title, $slug, $body, $priority,
            $isPinned, $isPublished, $publishAt, $expiresAt, $userId
        );
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('AnnouncementCreated', 'Created announcement: ' . $title, $userId);
    $_SESSION['flash_msg']  = 'Announcement created.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: /announcements/manage');
exit();
