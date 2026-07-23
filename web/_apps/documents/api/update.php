<?php
// Path: _apps/documents/api/update.php
/**
 * -----------------------------------------------------------------------------
 * Documents API — Update Metadata 📄
 * -----------------------------------------------------------------------------
 * Dual-mode (bearer API key OR admin session) endpoint that patches document
 * METADATA only — no file replacement in v1 (rotate by create + delete).
 *
 *   PUT/PATCH /api/v1/documents/{id}
 *   (or POST /api/documents/update?id=N — legacy alias, {"documentID": N} in body)
 *
 * Updatable fields: title, description, categoryID, eventID, isPublished.
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

// 🔍 Fetch the existing (non-deleted) row for this site — 404 otherwise
$fetch = $db->prepare(
    'SELECT documentID, siteID, categoryID, eventID, title, description, isPublished '
    . 'FROM tblDocuments WHERE documentID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
);
if ($fetch === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_DOC_UPDATE_FETCH_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$fetch->bind_param('ii', $documentId, $siteId);
$fetch->execute();
$old = $fetch->get_result()->fetch_assoc();
$fetch->close();
if ($old === null) {
    ApiResponse::error('Document not found', 404);
}

$new    = $old;
$set    = [];
$types  = '';
$params = [];

if (array_key_exists('title', $body) === true) {
    $title = trim((string) $body['title']);
    if ($title === '' || mb_strlen($title) > 255) {
        ApiResponse::error('title must be non-empty and ≤255 characters', 400);
    }
    $set[]    = 'title = ?';
    $types   .= 's';
    $params[] = $title;
    $new['title'] = $title;
}

if (array_key_exists('description', $body) === true) {
    $description = $body['description'] === null ? null : trim((string) $body['description']);
    if ($description === '') {
        $description = null;
    }
    $set[]    = 'description = ?';
    $types   .= 's';
    $params[] = $description;
    $new['description'] = $description;
}

if (array_key_exists('categoryID', $body) === true) {
    $categoryId = ($body['categoryID'] === null || $body['categoryID'] === '') ? null : (int) $body['categoryID'];
    if ($categoryId !== null) {
        $catCheck = $db->prepare('SELECT categoryID FROM tblDocCategories WHERE categoryID = ? AND siteID = ? LIMIT 1');
        if ($catCheck === false) {
            ApiResponse::error('Database error', 500);
        }
        $catCheck->bind_param('ii', $categoryId, $siteId);
        $catCheck->execute();
        $catExists = $catCheck->get_result()->fetch_assoc() !== null;
        $catCheck->close();
        if ($catExists === false) {
            ApiResponse::error('categoryID does not exist for this site', 400);
        }
    }
    $set[]    = 'categoryID = ?';
    $types   .= 'i';
    $params[] = $categoryId;
    $new['categoryID'] = $categoryId;
}

if (array_key_exists('eventID', $body) === true) {
    $eventId = ($body['eventID'] === null || $body['eventID'] === '') ? null : (int) $body['eventID'];
    if ($eventId !== null) {
        $evCheck = $db->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1');
        if ($evCheck === false) {
            ApiResponse::error('Database error', 500);
        }
        $evCheck->bind_param('ii', $eventId, $siteId);
        $evCheck->execute();
        $evExists = $evCheck->get_result()->fetch_assoc() !== null;
        $evCheck->close();
        if ($evExists === false) {
            ApiResponse::error('eventID does not exist for this site', 400);
        }
    }
    $set[]    = 'eventID = ?';
    $types   .= 'i';
    $params[] = $eventId;
    $new['eventID'] = $eventId;
}

if (array_key_exists('isPublished', $body) === true) {
    $isPublished = (bool) $body['isPublished'] === true ? 1 : 0;
    $set[]    = 'isPublished = ?';
    $types   .= 'i';
    $params[] = $isPublished;
    $new['isPublished'] = $isPublished;
}

if (count($set) === 0) {
    ApiResponse::error('No updatable fields in request body', 400);
}

$set[]    = 'updatedAt = NOW()';
$types   .= 'ii';
$params[] = $documentId;
$params[] = $siteId;

$sql = 'UPDATE tblDocuments SET ' . implode(', ', $set)
     . ' WHERE documentID = ? AND siteID = ? LIMIT 1';
$stmt = $db->prepare($sql);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_DOC_UPDATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param($types, ...$params);
$ok = $stmt->execute();
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_DOC_UPDATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to update document', 500);
}

Logger::audit('tblDocuments', $documentId, 'update', $old, $new);
Logger::activity('ApiDocumentUpdate', 'API: updated document #' . $documentId);

ApiResponse::success(['documentID' => $documentId], 200);
