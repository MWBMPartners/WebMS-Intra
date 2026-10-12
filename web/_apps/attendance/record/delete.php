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

use Portal\Core\App;
use Portal\Core\AttendanceAccess;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Auth check
if (Auth::check() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Only this organisation's own people may record or delete a head count
//    (12 October 2026). The sign-in test above used to be the only test, and
//    the rows written here are tied to the organisation in the ADDRESS. So a
//    member of another organisation, signed in and on this organisation's
//    address, could add, change and delete its head counts.
//
//    Now: an administrator, or an active member of this organisation.
//    AttendanceAccess::viewerIsMember() is the same membership test the
//    attendance report page uses; do not write a second copy of it here.
//    WHICH members may record is not narrowed: any active member still may
//    (the owner's decision). The same test is in record.php, record/save.php
//    and record/delete.php; change all three together.
if (App::isAdmin() === false
    && AttendanceAccess::viewerIsMember($mysqli, (int) ($_SESSION['user_id'] ?? 0), Site::id()) === false) {
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
