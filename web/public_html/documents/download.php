<?php
// Path: public_html/documents/download.php
/**
 * -----------------------------------------------------------------------------
 * Documents — Download Handler
 * -----------------------------------------------------------------------------
 * Serves a document file for download and increments the download counter.
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

use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

$documentId = (int) ($_GET['id'] ?? 0);
$siteId     = Site::id();

if ($documentId <= 0) {
    Router::renderError(404);
    return;
}

// 📋 Fetch document record
$stmt = $mysqli->prepare(
    'SELECT * FROM tblDocuments WHERE documentID = ? AND siteID = ? AND isPublished = 1 AND isDeleted = 0 LIMIT 1'
);
if ($stmt === false) {
    Router::renderError(500);
    return;
}
$stmt->bind_param('ii', $documentId, $siteId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($doc === null) {
    Router::renderError(404);
    return;
}

// 📁 Resolve file path
$filePath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR . $doc['filePath'];

if (is_readable($filePath) === false) {
    Router::renderError(404);
    return;
}

// 📊 Increment download counter
$updStmt = $mysqli->prepare('UPDATE tblDocuments SET downloadCount = downloadCount + 1 WHERE documentID = ?');
if ($updStmt !== false) {
    $updStmt->bind_param('i', $documentId);
    $updStmt->execute();
    $updStmt->close();
}

// 📤 Serve file
$mimeType = $doc['mimeType'] ?? 'application/octet-stream';
$fileName = $doc['fileName'];

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . addcslashes($fileName, '"') . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
readfile($filePath);
exit();
