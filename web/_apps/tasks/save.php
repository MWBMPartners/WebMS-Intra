<?php
// Path: public_html/tasks/save.php
/**
 * -----------------------------------------------------------------------------
 * Tasks — Save Handler
 * -----------------------------------------------------------------------------
 * Creates or updates a task (POST only).
 *
 * @package   Portal\Tasks
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.3
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/96
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /tasks');
    exit();
}

Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /tasks');
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$taskId            = (int) ($_POST['taskID'] ?? 0);
$title             = trim($_POST['title'] ?? '');
$description       = trim($_POST['description'] ?? '');
$priority          = $_POST['priority'] ?? 'normal';
$dueDate           = trim($_POST['dueDate'] ?? '') !== '' ? $_POST['dueDate'] : null;
$assignedToId      = ($_POST['assignedToID'] ?? '') !== '' ? (int) $_POST['assignedToID'] : $userId;
$reminderDate      = trim($_POST['reminderDate'] ?? '') !== '' ? $_POST['reminderDate'] : null;
$isRecurring       = isset($_POST['isRecurring']) === true ? 1 : 0;
$recurrenceType    = trim($_POST['recurrenceType'] ?? '') !== '' ? $_POST['recurrenceType'] : null;
$recurrenceInterval = max(1, (int) ($_POST['recurrenceInterval'] ?? 1));
$recurrenceEndDate = trim($_POST['recurrenceEndDate'] ?? '') !== '' ? $_POST['recurrenceEndDate'] : null;

if ($title === '') {
    $_SESSION['flash_msg']  = 'Title is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /tasks?edit=' . $taskId);
    exit();
}

// 🔁 Reset reminderSent when the incoming reminderDate is in the future so
//    an edited reminder re-fires (gap #439 write-path fix — previously an
//    edited reminderDate never re-fired once reminderSent was already 1).
//    NULL leaves the existing reminderSent value untouched via COALESCE.
$reminderSentReset = null;
if ($reminderDate !== null && strtotime($reminderDate) > time()) {
    $reminderSentReset = 0;
}

$validPriorities = ['low', 'normal', 'high', 'urgent'];
if (in_array($priority, $validPriorities, true) === false) {
    $priority = 'normal';
}

if ($taskId > 0) {
    // 🛡️ IDOR guard — only the assignee, the creator, or an admin may edit
    //     (mirrors tasks/api/complete.php:49-53). Scoping by siteID alone let
    //     any logged-in member update any other member's task in-site.
    $authTask = null;
    $authStmt = $mysqli->prepare('SELECT assignedToID, createdByID FROM tblTasks WHERE taskID = ? AND siteID = ? LIMIT 1');
    if ($authStmt !== false) {
        $authStmt->bind_param('ii', $taskId, $siteId);
        $authStmt->execute();
        $authTask = $authStmt->get_result()->fetch_assoc();
        $authStmt->close();
    }
    if ($authTask === null
        || ((int) $authTask['assignedToID'] !== $userId
            && (int) $authTask['createdByID'] !== $userId
            && App::isAdmin() !== true)
    ) {
        $_SESSION['flash_msg']  = 'You do not have permission to edit this task.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /tasks');
        exit();
    }

    // 📋 Update
    $stmt = $mysqli->prepare(
        'UPDATE tblTasks SET title = ?, description = ?, priority = ?, dueDate = ?, '
        . 'assignedToID = ?, reminderDate = ?, isRecurring = ?, recurrenceType = ?, '
        . 'recurrenceInterval = ?, recurrenceEndDate = ?, reminderSent = COALESCE(?, reminderSent) '
        . 'WHERE taskID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param(
            'ssssisisisiii',
            $title, $description, $priority, $dueDate,
            $assignedToId, $reminderDate, $isRecurring, $recurrenceType,
            $recurrenceInterval, $recurrenceEndDate, $reminderSentReset, $taskId, $siteId
        );
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('TaskUpdated', 'Updated task: ' . $title, $userId);
    $_SESSION['flash_msg']  = 'Task updated.';
} else {
    // 📋 Create
    $stmt = $mysqli->prepare(
        'INSERT INTO tblTasks (siteID, title, description, priority, dueDate, assignedToID, '
        . 'createdByID, reminderDate, isRecurring, recurrenceType, recurrenceInterval, recurrenceEndDate) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param(
            'issssiisisis',
            $siteId, $title, $description, $priority, $dueDate,
            $assignedToId, $userId, $reminderDate, $isRecurring,
            $recurrenceType, $recurrenceInterval, $recurrenceEndDate
        );
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('TaskCreated', 'Created task: ' . $title, $userId);
    $_SESSION['flash_msg']  = 'Task created.';
}

$_SESSION['flash_type'] = 'success';
header('Location: /tasks');
exit();
