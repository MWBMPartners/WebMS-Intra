<?php
// Path: public_html/calendar/manage/delete.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Soft Delete Handler 🗑️
 * -----------------------------------------------------------------------------
 * Soft-deletes an event by setting isDeleted=1 in tblEvents.
 *
 * @package   Portal\Calendar
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

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /calendar/manage');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /calendar/manage');
    exit();
}

$eventID = (int) ($_POST['eventID'] ?? 0);

// 🌐 Multi-site scope
$siteId = Site::id();

if ($eventID > 0) {
    $stmt = $mysqli->prepare('UPDATE tblEvents SET isDeleted = 1 WHERE eventID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $eventID, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('EventDeleted', 'Soft-deleted event #' . $eventID, $_SESSION['user_id'] ?? null);

    $_SESSION['flash_msg']  = 'Event deleted.';
    $_SESSION['flash_type'] = 'success';
} else {
    $_SESSION['flash_msg']  = 'Invalid event ID.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /calendar/manage');
exit();
