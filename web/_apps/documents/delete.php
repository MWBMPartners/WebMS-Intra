<?php
// Path: public_html/documents/delete.php
/**
 * -----------------------------------------------------------------------------
 * Documents — Delete Handler (Soft Delete)
 * -----------------------------------------------------------------------------
 * Marks a document as deleted (POST only, admin only).
 *
 * @package   Portal\Documents
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/90
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /documents');
    exit();
}

Auth::requireLogin();

if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = 'You do not have permission to delete documents.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /documents');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /documents');
    exit();
}

$documentId = (int) ($_POST['documentID'] ?? 0);
$siteId     = Site::id();
$userId     = (int) ($_SESSION['user_id'] ?? 0);

if ($documentId <= 0) {
    $_SESSION['flash_msg']  = 'Invalid document.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /documents');
    exit();
}

// 📋 Soft delete
$stmt = $mysqli->prepare(
    'UPDATE tblDocuments SET isDeleted = 1 WHERE documentID = ? AND siteID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $documentId, $siteId);
    $stmt->execute();
    $stmt->close();
}

Logger::activity('DocumentDeleted', 'Deleted document ID: ' . $documentId, $userId);

$_SESSION['flash_msg']  = 'Document deleted.';
$_SESSION['flash_type'] = 'info';
header('Location: /documents');
exit();
