<?php
// Path: public_html/resources/action.php
/**
 * Resource Booking — POST handler for approve / decline / cancel.
 *
 * @package   Portal\Resources
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/263
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

$db        = App::db();
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$bookingId = (int) ($_POST['bookingID'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');

if ($bookingId <= 0) {
    header('Location: /resources');
    exit();
}

// Load booking + confirm site scope.
$b = null;
$stmt = $db->prepare(
    'SELECT b.bookingID, b.bookedByID, b.status, r.siteID '
    . 'FROM tblResourceBooking b JOIN tblResource r ON r.resourceID = b.resourceID '
    . 'WHERE b.bookingID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($b === null || (int) $b['siteID'] !== $siteId) {
    http_response_code(404);
    exit('Booking not found');
}

try {
    if ($action === 'cancel') {
        // Owner OR admin can cancel.
        if ((int) $b['bookedByID'] !== $userId && App::isAdmin() === false) {
            http_response_code(403);
            exit('Not your booking');
        }
        $stmt = $db->prepare("UPDATE tblResourceBooking SET status = 'cancelled' WHERE bookingID = ?");
        if ($stmt !== false) {
            $stmt->bind_param('i', $bookingId);
            $stmt->execute();
            $stmt->close();
        }
        $back = '/resources/my-bookings';
    } elseif ($action === 'approve' || $action === 'decline') {
        if (App::isAdmin() === false) {
            http_response_code(403);
            exit('Admin only');
        }
        if ($b['status'] !== 'pending') {
            header('Location: /resources/approvals');
            exit();
        }
        $newStatus = $action === 'approve' ? 'approved' : 'declined';
        $stmt = $db->prepare(
            'UPDATE tblResourceBooking SET status = ?, approvedByID = ?, approvedAt = NOW() WHERE bookingID = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('sii', $newStatus, $userId, $bookingId);
            $stmt->execute();
            $stmt->close();
        }
        $back = '/resources/approvals';
    } else {
        $back = '/resources';
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Resources', 'Warning', 'ACTION', $e->getMessage(), '');
    $back = '/resources';
}

header('Location: ' . $back);
exit();
