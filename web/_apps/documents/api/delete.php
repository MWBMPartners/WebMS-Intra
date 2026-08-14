<?php
// Path: _apps/documents/api/delete.php
/**
 * -----------------------------------------------------------------------------
 * Documents API — Delete (soft) 📄
 * -----------------------------------------------------------------------------
 * Dual-mode (bearer API key OR admin session) endpoint that soft-deletes a
 * document (sets isDeleted = 1), matching the existing
 * `_apps/documents/delete.php` app behaviour. The stored file is left on
 * disk (no hard delete in v1).
 *
 *   DELETE /api/v1/documents/{id}
 *   (or POST /api/documents/delete?id=N — legacy alias, {"documentID": N} in body)
 *
 * @package   Portal\API\Documents
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/323
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Site;

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('documents:write', sessionNeedsAdmin: true);

$documentId = (int) ($_GET['id'] ?? $body['documentID'] ?? 0);
if ($documentId <= 0) {
    ApiResponse::error('documentID is required', 400);
}

$db     = App::db();
$siteId = Site::id();

// 🔍 Fetch the row first so the audit trail retains full oldData
$fetch = $db->prepare(
    'SELECT documentID, siteID, categoryID, eventID, title, description, fileName, filePath, '
    . 'fileSize, mimeType, isPublished FROM tblDocuments '
    . 'WHERE documentID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
);
if ($fetch === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_DOC_DELETE_FETCH_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$fetch->bind_param('ii', $documentId, $siteId);
$fetch->execute();
$old = $fetch->get_result()->fetch_assoc();
$fetch->close();
if ($old === null) {
    ApiResponse::error('Document not found', 404);
}

$stmt = $db->prepare(
    'UPDATE tblDocuments SET isDeleted = 1 WHERE documentID = ? AND siteID = ? AND isDeleted = 0'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_DOC_DELETE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('ii', $documentId, $siteId);
$ok       = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_DOC_DELETE_FAIL', $db->error, '');
    ApiResponse::error('Failed to delete document', 500);
}
if ($affected === 0) {
    ApiResponse::error('Document not found or already deleted', 404);
}

Logger::audit('tblDocuments', $documentId, 'delete', $old, null);
Logger::activity('ApiDocumentDelete', 'API: soft-deleted document #' . $documentId);

ApiResponse::success(['documentID' => $documentId, 'deleted' => true], 200);
