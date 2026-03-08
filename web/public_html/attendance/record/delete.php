<?php
// Path: public_html/attendance/record/delete.php
/**
 * -----------------------------------------------------------------------------
 * Attendance Tracker — Delete (Soft) Handler 🗑️
 * -----------------------------------------------------------------------------
 * Soft-deletes an attendance session by setting isDeleted = 1.
 * Only accepts POST with valid CSRF token.
 *
 * @package   Portal\Attendance
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Auth check
if (Auth::check() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /attendance');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /attendance');
    exit();
}

$sessionID = (int) ($_POST['sessionID'] ?? 0);
$userId    = $_SESSION['user_id'] ?? null;

// 🌐 Multi-site scope
$siteId = Site::id();

if ($sessionID <= 0) {
    $_SESSION['flash_msg']  = 'Invalid session ID.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /attendance');
    exit();
}

// 🗑️ Soft delete
$stmt = $mysqli->prepare('UPDATE tblAttendanceSessions SET isDeleted = 1, updatedByID = ? WHERE sessionID = ? AND siteID = ?');
if ($stmt !== false) {
    $stmt->bind_param('iii', $userId, $sessionID, $siteId);
    $stmt->execute();
    $stmt->close();
}

Logger::activity('AttendanceDeleted', 'Soft-deleted attendance session #' . $sessionID, $userId);

$_SESSION['flash_msg']  = 'Attendance record deleted.';
$_SESSION['flash_type'] = 'success';
header('Location: /attendance');
exit();
