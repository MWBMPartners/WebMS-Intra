<?php
// Path: _apps/service-plans/live-toggle.php
/**
 * -----------------------------------------------------------------------------
 * Service Plans — Start / Close Live Runtime ⏱️ (#300)
 * -----------------------------------------------------------------------------
 * POST endpoint. Sets `startedAt` (start) or `closedAt` (close) on a plan.
 * Admin-only, CSRF-checked, idempotent.
 *
 * @package   Portal\ServicePlans
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/300
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('ServicePlanLiveToggleRejected', 'Invalid CSRF on live-toggle');
    http_response_code(400);
    exit('Bad request');
}

$planId = (int) ($_POST['planID'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$siteId = Site::id();

if (in_array($action, ['start', 'close'], true) === false) {
    http_response_code(400);
    exit('Invalid action');
}

if ($action === 'start') {
    $stmt = $mysqli->prepare(
        'UPDATE tblServicePlan SET startedAt = NOW() '
        . 'WHERE planID = ? AND siteID = ? AND startedAt IS NULL'
    );
} else {
    $stmt = $mysqli->prepare(
        'UPDATE tblServicePlan SET closedAt = NOW() '
        . 'WHERE planID = ? AND siteID = ? AND startedAt IS NOT NULL AND closedAt IS NULL'
    );
}

if ($stmt !== false) {
    $stmt->bind_param('ii', $planId, $siteId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('ServicePlanLive' . ucfirst($action), 'Plan #' . $planId);
}

header('Location: /service-plans/live?id=' . $planId, true, 303);
exit();
