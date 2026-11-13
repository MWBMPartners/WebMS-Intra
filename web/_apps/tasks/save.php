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
    header('Location: ' . Site::url('tasks') . '?edit=' . $taskId);
    exit();
}

// 🕰️ THE REMINDER IS A READING ON THE ORGANISATION'S CLOCK (#1041), typed
//    on a `datetime-local` box, and it is stored as that reading. So it is
//    checked as TEXT by `I18n::wallClockFromInput()` -- shape AND range, an
//    impossible date such as 31 February refused -- and stored as the
//    `Y-m-d H:i:s` reading it returns. No moment is built from it: on the
//    morning the clocks go forward the hour it names does not exist, and
//    PHP would move it (#777). It used to be stored exactly as posted, so
//    a value that was not a real date and time reached the database, which
//    refuses it under the portal's strict mode -- an error page rather than
//    a message.
if ($reminderDate !== null) {
    $reminderReading = I18n::wallClockFromInput((string) $reminderDate);
    if ($reminderReading === null) {
        $_SESSION['flash_msg']  = 'The reminder must be a real date and time.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('tasks') . '?edit=' . $taskId);
        exit();
    }
    $reminderDate = $reminderReading;
}

// 🔁 Reset reminderSent when the incoming reminderDate is in the future so
//    an edited reminder re-fires (gap #439 write-path fix — previously an
//    edited reminderDate never re-fired once reminderSent was already 1).
//    NULL leaves the existing reminderSent value untouched via COALESCE.
//
//    "In the future" compares two readings on the ORGANISATION's clock as
//    text (#1041): the reminder, and what that clock reads now. The old
//    test, `strtotime($reminderDate) > time()`, read the reminder in PHP's
//    default zone -- which start-up sets to this organisation's, so it
//    agreed on every ordinary day -- and went wrong only inside the hour
//    the clocks skip, where `strtotime()` moves the reading an hour on.
$reminderSentReset = null;
if ($reminderDate !== null
    && strcmp($reminderDate, I18n::wallClockNow(new \DateTimeZone(Site::timezone($siteId)))) > 0
) {
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
        header('Location: ' . Site::url('tasks'));
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
header('Location: ' . Site::url('tasks'));
exit();
