<?php
// Path: public_html/tasks/complete.php
/**
 * -----------------------------------------------------------------------------
 * Tasks — Complete Handler
 * -----------------------------------------------------------------------------
 * Marks a task as completed (POST only). For recurring tasks, spawns the next
 * occurrence.
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
use Portal\Core\I18n;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('tasks'));
    exit();
}

Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('tasks'));
    exit();
}

$taskId = (int) ($_POST['taskID'] ?? 0);
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($taskId <= 0) {
    $_SESSION['flash_msg']  = 'Invalid task.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('tasks'));
    exit();
}

// 📋 Fetch current task
$tStmt = $mysqli->prepare(
    'SELECT * FROM tblTasks WHERE taskID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
);
if ($tStmt === false) {
    $_SESSION['flash_msg']  = t('error.database');
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('tasks'));
    exit();
}
$tStmt->bind_param('ii', $taskId, $siteId);
$tStmt->execute();
$task = $tStmt->get_result()->fetch_assoc();
$tStmt->close();

if ($task === null) {
    $_SESSION['flash_msg']  = 'Task not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('tasks'));
    exit();
}

// 🛡️ IDOR guard — only the assignee or an admin may complete this task
//     (mirrors tasks/api/complete.php:49-53).
if ((int) $task['assignedToID'] !== $userId && App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = 'Only the assignee or an admin can complete this task.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('tasks'));
    exit();
}

// 📋 Mark as completed
$cStmt = $mysqli->prepare(
    'UPDATE tblTasks SET status = \'completed\', completedAt = NOW() WHERE taskID = ?'
);
if ($cStmt !== false) {
    $cStmt->bind_param('i', $taskId);
    $cStmt->execute();
    $cStmt->close();
}

Logger::activity('TaskCompleted', 'Completed task: ' . $task['title'], $userId);

// 🔄 Spawn next occurrence for recurring tasks
if ((int) $task['isRecurring'] === 1 && $task['recurrenceType'] !== null) {
    $nextDue = null;
    $interval = max(1, (int) $task['recurrenceInterval']);

    $nextReminder = null;
    if ($task['dueDate'] !== null) {
        $dt = new DateTime($task['dueDate']);
        switch ($task['recurrenceType']) {
            case 'daily':
                $dt->modify('+' . $interval . ' days');
                break;
            case 'weekly':
                $dt->modify('+' . $interval . ' weeks');
                break;
            case 'monthly':
                $dt->modify('+' . $interval . ' months');
                break;
            case 'yearly':
                $dt->modify('+' . $interval . ' years');
                break;
        }
        $nextDue = $dt->format('Y-m-d');

        // 🔔 Carry the reminder forward by the same interval (gap #439
        //    write-path fix) — previously dropped entirely on recurrence
        //    spawn, so a recurring task only ever got ONE reminder ever.
        //
        //    The reminder is a reading on the organisation's clock (#1041), so
        //    it is moved on as a READING, by the same unit and count as the
        //    due date, through I18n::wallClockMovedOn(), which moves a stored
        //    reading on to its next occurrence in UTC, where there are no
        //    clock changes. It used to be
        //    `new DateTime()` in PHP's default zone, which on the morning the
        //    clocks go forward moved a 01:30 reminder to 02:30 for every
        //    later occurrence (#777). A stored value that is not a reading
        //    carries nothing forward rather than guessing.
        $reminderUnits = ['daily' => 'days', 'weekly' => 'weeks', 'monthly' => 'months', 'yearly' => 'years'];
        if ($task['reminderDate'] !== null && isset($reminderUnits[$task['recurrenceType']]) === true) {
            $nextReminder = I18n::wallClockMovedOn((string) $task['reminderDate'], $reminderUnits[$task['recurrenceType']], $interval);
        }

        // 🔍 Check if past recurrence end date
        if ($task['recurrenceEndDate'] !== null && $nextDue > $task['recurrenceEndDate']) {
            $nextDue = null; // Don't create next occurrence
            $nextReminder = null;
        }
    }

    if ($nextDue !== null) {
        $nStmt = $mysqli->prepare(
            'INSERT INTO tblTasks (siteID, title, description, assignedToID, createdByID, priority, '
            . 'dueDate, isRecurring, recurrenceType, recurrenceInterval, recurrenceEndDate, parentTaskID, reminderDate) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
        );
        if ($nStmt !== false) {
            $nStmt->bind_param(
                'issiisssisis',
                $siteId, $task['title'], $task['description'],
                $task['assignedToID'], $userId, $task['priority'],
                $nextDue, $task['recurrenceType'], $interval,
                $task['recurrenceEndDate'], $taskId, $nextReminder
            );
            $nStmt->execute();
            $nStmt->close();
        }
        Logger::activity('TaskRecurrenceSpawned', 'Spawned next occurrence of: ' . $task['title'] . ' due ' . $nextDue, $userId);
    }
}

$_SESSION['flash_msg']  = 'Task completed.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . Site::url('tasks'));
exit();
