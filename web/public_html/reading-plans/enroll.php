<?php
// Path: public_html/reading-plans/enroll.php
/**
 * Reading Plans — POST handler to enroll the current user in a plan.
 *
 * @package   Portal\ReadingPlans
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/265
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$planId = (int) ($_POST['planID'] ?? 0);

// Confirm plan is visible in this site.
$ok = false;
$stmt = $db->prepare('SELECT 1 FROM tblReadingPlan WHERE planID = ? AND siteID = ? AND isPublic = 1 LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $planId, $siteId);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
}
if ($ok === false) {
    http_response_code(404);
    exit('Plan not found');
}

try {
    $stmt = $db->prepare(
        'INSERT INTO tblReadingPlanEnrollment (planID, userID) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE startedAt = startedAt'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $planId, $userId);
        $stmt->execute();
        $stmt->close();
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('ReadingPlans', 'Warning', 'ENROLL', $e->getMessage(), '');
}

header('Location: /reading-plans/plan?id=' . $planId);
exit();
