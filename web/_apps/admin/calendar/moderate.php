<?php
// Path: _apps/admin/calendar/moderate.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Calendar Submission Moderation Action Handler 🛡️ (#326)
 * -----------------------------------------------------------------------------
 * POST endpoint. Approve = flip status='published', isPublic=1,
 * submissionStatus='approved'. Reject = status='archived',
 * submissionStatus='rejected'. Stamps moderatedBy/At.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/326
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/calendar/moderation', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('EventModerateRejected', 'Invalid CSRF on /admin/calendar/moderate');
    header('Location: /admin/calendar/moderation', true, 302);
    exit();
}

$eventId = (int) ($_POST['eventID'] ?? 0);
$action  = (string) ($_POST['action'] ?? '');
$modId   = (int) ($_SESSION['user_id'] ?? 0);
$siteId  = Site::id();

if ($eventId <= 0 || in_array($action, ['approve', 'reject'], true) === false) {
    header('Location: /admin/calendar/moderation', true, 302);
    exit();
}

if ($action === 'approve') {
    $stmt = $mysqli->prepare(
        'UPDATE tblEvents SET status = "published", isPublic = 1, '
        . '    submissionStatus = "approved", moderatedByID = ?, moderatedAt = NOW() '
        . 'WHERE eventID = ? AND siteID = ? AND submissionStatus = "pending"'
    );
} else {
    $stmt = $mysqli->prepare(
        'UPDATE tblEvents SET status = "draft", isPublic = 0, '
        . '    submissionStatus = "rejected", moderatedByID = ?, moderatedAt = NOW() '
        . 'WHERE eventID = ? AND siteID = ? AND submissionStatus = "pending"'
    );
}

if ($stmt !== false) {
    $stmt->bind_param('iii', $modId, $eventId, $siteId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        Logger::activity('EventModerated', 'Event #' . $eventId . ' action=' . $action);

        // 🪝 Webhook on moderation (#324).
        if (class_exists(\Portal\Core\WebhookDispatcher::class) === true) {
            \Portal\Core\WebhookDispatcher::emit(
                'calendar.event.' . ($action === 'approve' ? 'approved' : 'rejected'),
                ['eventID' => $eventId]
            );
        }
    }
}

header('Location: /admin/calendar/moderation?status=pending', true, 302);
exit();
