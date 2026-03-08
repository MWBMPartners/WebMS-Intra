<?php
// Path: public_html/leadership/delete.php
/**
 * -----------------------------------------------------------------------------
 * Leadership — Delete Assignment Handler 🗑️
 * -----------------------------------------------------------------------------
 * Soft-deletes a leadership role assignment. Admin-only.
 * Sets isActive = 0 rather than removing the row, preserving history.
 *
 * @package   Portal\Leadership
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/38
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

// 🛡️ Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /leadership');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$assignmentID = (int) ($_POST['assignmentID'] ?? 0);
$userId       = $_SESSION['user_id'] ?? null;
$siteId       = Site::id();

if ($assignmentID <= 0) {
    $_SESSION['admin_flash_msg']  = 'Invalid assignment ID.';
    $_SESSION['admin_flash_type'] = 'danger';
    header('Location: /leadership');
    exit();
}

// 🗑️ Soft delete — set isActive = 0
$stmt = $mysqli->prepare(
    'UPDATE tblLeadershipAssignments SET isActive = 0, updatedByID = ? WHERE assignmentID = ? AND siteID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $userId, $assignmentID, $siteId);
    $stmt->execute();
    $stmt->close();
}

Logger::activity('LeadershipDeleted', 'Soft-deleted assignment #' . $assignmentID, $userId);

$_SESSION['admin_flash_msg']  = 'Assignment removed.';
$_SESSION['admin_flash_type'] = 'success';

// 📌 Return to history page if referrer is history, otherwise main page
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (strpos($referer, '/leadership/history') !== false) {
    header('Location: /leadership/history');
} else {
    header('Location: /leadership');
}
exit();
