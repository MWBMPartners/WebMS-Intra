<?php
// Path: public_html/attendance/record/save.php
/**
 * -----------------------------------------------------------------------------
 * Attendance Tracker — Save Handler 💾
 * -----------------------------------------------------------------------------
 * Handles create and update POST actions for attendance sessions.
 * Validates input, saves session to tblAttendanceSessions, and saves
 * headcount breakdown rows to tblAttendanceCounts.
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
    header('Location: /attendance/record');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$action = $_POST['action'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

// 🌐 Multi-site scope
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 📋 Collect form data
// -----------------------------------------------------------------------------
$serviceTypeID = (int) ($_POST['serviceTypeID'] ?? 0);
$sessionDate   = trim($_POST['sessionDate'] ?? '');
$sessionTime   = trim($_POST['sessionTime'] ?? '') !== '' ? trim($_POST['sessionTime']) : null;
$eventID       = ((int) ($_POST['eventID'] ?? 0)) > 0 ? (int) $_POST['eventID'] : null;
$notes         = trim($_POST['notes'] ?? '') !== '' ? trim($_POST['notes']) : null;

// 🔢 Collect count rows
$groups = $_POST['groups'] ?? [];
$counts = $_POST['counts'] ?? [];

// 🔍 Validation
if ($serviceTypeID <= 0 || $sessionDate === '') {
    $_SESSION['admin_flash_msg']  = 'Service type and date are required.';
    $_SESSION['admin_flash_type'] = 'danger';
    header('Location: /attendance/record');
    exit();
}

// 🔍 Validate service type exists
$typeValid = false;
$stmt = $mysqli->prepare('SELECT serviceTypeID FROM tblAttendanceServiceTypes WHERE serviceTypeID = ? AND isActive = 1 AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $serviceTypeID, $siteId);
    $stmt->execute();
    $typeValid = ($stmt->get_result()->fetch_assoc() !== null);
    $stmt->close();
}
if ($typeValid === false) {
    $_SESSION['admin_flash_msg']  = 'Invalid service type selected.';
    $_SESSION['admin_flash_type'] = 'danger';
    header('Location: /attendance/record');
    exit();
}

// 🔍 Validate date format
$dateObj = DateTime::createFromFormat('Y-m-d', $sessionDate);
if ($dateObj === false || $dateObj->format('Y-m-d') !== $sessionDate) {
    $_SESSION['admin_flash_msg']  = 'Invalid date format.';
    $_SESSION['admin_flash_type'] = 'danger';
    header('Location: /attendance/record');
    exit();
}

// 🔍 Validate count rows — at least one group with a valid count
$validGroups = [];
if (is_array($groups) === true && is_array($counts) === true) {
    $groupCount = min(count($groups), count($counts));
    for ($i = 0; $i < $groupCount; $i++) {
        $label = trim((string) ($groups[$i] ?? ''));
        $count = (int) ($counts[$i] ?? 0);
        if ($label !== '' && $count >= 0) {
            $validGroups[] = ['label' => $label, 'count' => $count, 'sort' => $i];
        }
    }
}

if (count($validGroups) === 0) {
    $_SESSION['admin_flash_msg']  = 'At least one headcount group is required.';
    $_SESSION['admin_flash_type'] = 'danger';
    header('Location: /attendance/record');
    exit();
}

// -----------------------------------------------------------------------------
// ➕ Create session
// -----------------------------------------------------------------------------
if ($action === 'create') {
    $mysqli->begin_transaction();

    $stmt = $mysqli->prepare(
        'INSERT INTO tblAttendanceSessions '
        . '(siteID, serviceTypeID, eventID, sessionDate, sessionTime, notes, createdByID, updatedByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        $mysqli->rollback();
        $_SESSION['admin_flash_msg']  = 'Database error creating session.';
        $_SESSION['admin_flash_type'] = 'danger';
        header('Location: /attendance/record');
        exit();
    }

    $stmt->bind_param('iiisssii', $siteId, $serviceTypeID, $eventID, $sessionDate, $sessionTime, $notes, $userId, $userId);
    $stmt->execute();
    $newSessionId = $stmt->insert_id;
    $stmt->close();

    // 🔢 Insert count rows
    $stmtCount = $mysqli->prepare(
        'INSERT INTO tblAttendanceCounts (sessionID, groupLabel, headcount, sortOrder) VALUES (?, ?, ?, ?)'
    );
    if ($stmtCount !== false) {
        foreach ($validGroups as $g) {
            $stmtCount->bind_param('isii', $newSessionId, $g['label'], $g['count'], $g['sort']);
            $stmtCount->execute();
        }
        $stmtCount->close();
    }

    $mysqli->commit();

    $totalCount = array_sum(array_column($validGroups, 'count'));
    Logger::activity(
        'AttendanceRecorded',
        'Recorded attendance session #' . $newSessionId . ' for ' . $sessionDate . ' (total: ' . $totalCount . ')',
        $userId
    );

    $_SESSION['admin_flash_msg']  = 'Attendance recorded successfully (total: ' . number_format($totalCount) . ').';
    $_SESSION['admin_flash_type'] = 'success';
    header('Location: /attendance');
    exit();
}

// -----------------------------------------------------------------------------
// ✏️ Update session
// -----------------------------------------------------------------------------
if ($action === 'update') {
    $sessionID = (int) ($_POST['sessionID'] ?? 0);
    if ($sessionID <= 0) {
        $_SESSION['admin_flash_msg']  = 'Invalid session ID.';
        $_SESSION['admin_flash_type'] = 'danger';
        header('Location: /attendance');
        exit();
    }

    $mysqli->begin_transaction();

    // 📋 Update session record
    $stmt = $mysqli->prepare(
        'UPDATE tblAttendanceSessions SET '
        . 'serviceTypeID = ?, eventID = ?, sessionDate = ?, sessionTime = ?, notes = ?, updatedByID = ? '
        . 'WHERE sessionID = ? AND isDeleted = 0 AND siteID = ?'
    );
    if ($stmt === false) {
        $mysqli->rollback();
        $_SESSION['admin_flash_msg']  = 'Database error updating session.';
        $_SESSION['admin_flash_type'] = 'danger';
        header('Location: /attendance');
        exit();
    }

    $stmt->bind_param('iisssiii', $serviceTypeID, $eventID, $sessionDate, $sessionTime, $notes, $userId, $sessionID, $siteId);
    $stmt->execute();
    $stmt->close();

    // 🗑️ Delete old count rows and re-insert
    $stmtDel = $mysqli->prepare('DELETE FROM tblAttendanceCounts WHERE sessionID = ?');
    if ($stmtDel !== false) {
        $stmtDel->bind_param('i', $sessionID);
        $stmtDel->execute();
        $stmtDel->close();
    }

    // 🔢 Insert new count rows
    $stmtCount = $mysqli->prepare(
        'INSERT INTO tblAttendanceCounts (sessionID, groupLabel, headcount, sortOrder) VALUES (?, ?, ?, ?)'
    );
    if ($stmtCount !== false) {
        foreach ($validGroups as $g) {
            $stmtCount->bind_param('isii', $sessionID, $g['label'], $g['count'], $g['sort']);
            $stmtCount->execute();
        }
        $stmtCount->close();
    }

    $mysqli->commit();

    $totalCount = array_sum(array_column($validGroups, 'count'));
    Logger::activity(
        'AttendanceUpdated',
        'Updated attendance session #' . $sessionID . ' for ' . $sessionDate . ' (total: ' . $totalCount . ')',
        $userId
    );

    $_SESSION['admin_flash_msg']  = 'Attendance updated successfully (total: ' . number_format($totalCount) . ').';
    $_SESSION['admin_flash_type'] = 'success';
    header('Location: /attendance');
    exit();
}

// 🚫 Unknown action
$_SESSION['admin_flash_msg']  = 'Unknown action.';
$_SESSION['admin_flash_type'] = 'warning';
header('Location: /attendance/record');
exit();
