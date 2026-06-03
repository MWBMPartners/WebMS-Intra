<?php
// Path: public_html/reading-plans/check.php
/**
 * Reading Plans — POST handler to mark a day complete + advance the user.
 *
 * Atomic in a transaction:
 *   1. INSERT progress row (idempotent — ON DUPLICATE KEY UPDATE no-op).
 *   2. UPDATE enrollment.currentDay = currentDay + 1 (capped at totalDays).
 *   3. UPDATE enrollment.completedAt when currentDay reaches totalDays + 1.
 *
 * @package   Portal\ReadingPlans
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/265
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db           = App::db();
$userId       = (int) ($_SESSION['user_id'] ?? 0);
$enrollmentId = (int) ($_POST['enrollmentID'] ?? 0);
$dayNumber    = (int) ($_POST['dayNumber'] ?? 0);

if ($enrollmentId <= 0 || $dayNumber <= 0) {
    http_response_code(400);
    exit('Missing parameters');
}

// 🛡️ Confirm ownership + read totalDays so we know when to mark completed.
$ownerOk = false;
$totalDays = 0;
$planId = 0;
$stmt = $db->prepare(
    'SELECT e.userID, p.totalDays, e.planID FROM tblReadingPlanEnrollment e '
    . 'JOIN tblReadingPlan p ON p.planID = e.planID '
    . 'WHERE e.enrollmentID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row !== null && (int) $row['userID'] === $userId) {
        $ownerOk = true;
        $totalDays = (int) $row['totalDays'];
        $planId = (int) $row['planID'];
    }
}
if ($ownerOk === false) {
    http_response_code(403);
    exit('Not your enrollment');
}

try {
    $db->begin_transaction();
    // 1. Record the day's completion (idempotent).
    $stmt = $db->prepare(
        'INSERT INTO tblReadingPlanProgress (enrollmentID, dayNumber) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE completedAt = completedAt'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $enrollmentId, $dayNumber);
        $stmt->execute();
        $stmt->close();
    }
    // 2. Advance currentDay (capped at totalDays + 1, which signals completion).
    $stmt = $db->prepare(
        'UPDATE tblReadingPlanEnrollment SET currentDay = LEAST(currentDay + 1, ?) '
        . 'WHERE enrollmentID = ?'
    );
    if ($stmt !== false) {
        $cap = $totalDays + 1;
        $stmt->bind_param('ii', $cap, $enrollmentId);
        $stmt->execute();
        $stmt->close();
    }
    // 3. Mark completedAt if we've passed the last day.
    $stmt = $db->prepare(
        'UPDATE tblReadingPlanEnrollment SET completedAt = NOW() '
        . 'WHERE enrollmentID = ? AND completedAt IS NULL AND currentDay > ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $enrollmentId, $totalDays);
        $stmt->execute();
        $stmt->close();
    }
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    \Portal\Core\Logger::errorPlatform('ReadingPlans', 'Warning', 'CHECK', $e->getMessage(), '');
}

header('Location: /reading-plans/plan?id=' . $planId);
exit();
