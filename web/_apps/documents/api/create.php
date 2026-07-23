<?php
// Path: _apps/documents/api/create.php
/**
 * -----------------------------------------------------------------------------
 * Documents API — Upload 📄
 * -----------------------------------------------------------------------------
 * Dual-mode (bearer API key OR admin session) endpoint that uploads a new
 * document to the library. Accepts EITHER of two body shapes:
 *
 *   (a) multipart/form-data — file field `file` + other fields as normal
 *       POST fields (title, description, categoryID, eventID, isPublished).
 *       Session-mode CSRF for this shape MUST be sent via the X-CSRF-TOKEN
 *       header (the csrf_token body field only exists for JSON requests).
 *
 *   (b) application/json — base64-encoded file content:
 *       POST /api/v1/documents
 *       {
 *         "title":       "Minutes 2026-07",        (required, ≤255)
 *         "description": "…",                       (optional)
 *         "categoryID":  4,                          (optional, FK tblDocCategories)
 *         "eventID":     42,                          (optional, FK tblEvents)
 *         "isPublished": true,                        (optional, default true)
 *         "fileName":    "minutes.pdf",                (optional, original name)
 *         "fileContent": "JVBERi0xLjQK…"               (required, base64)
 *       }
 *
 * SECURITY — stricter than the legacy `_apps/documents/upload.php` handler,
 * which trusts the client-declared `$_FILES[...]['type']` with NO allowlist.
 * This endpoint:
 *   1. Never trusts the client-declared MIME type or file path.
 *   2. finfo-SNIFFS the real MIME type from the file bytes and rejects any
 *      type not in DOC_API_ALLOWED_MIME_EXT (below).
 *   3. Generates a random, server-side stored filename — the extension is
 *      derived from the SNIFFED mime, never from the client filename.
 *   4. Enforces a hard size cap (`documents.api.maxUploadBytes` setting).
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

// 🛡️ MIME allowlist — sniffed (finfo) mime type ⇒ safe stored-file extension.
//    Deliberately narrower than freeform: pdf, common raster images, plain
//    text/csv, and modern + legacy Office document formats.
const DOC_API_ALLOWED_MIME_EXT = [
    'application/pdf'                                                          => 'pdf',
    'image/png'                                                                => 'png',
    'image/jpeg'                                                               => 'jpg',
    'image/gif'                                                                => 'gif',
    'image/webp'                                                               => 'webp',
    'text/plain'                                                               => 'txt',
    'text/csv'                                                                 => 'csv',
    'application/msword'                                                       => 'doc',
    'application/vnd.ms-excel'                                                 => 'xls',
    'application/vnd.ms-powerpoint'                                            => 'ppt',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'  => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'        => 'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
];

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('documents:write', sessionNeedsAdmin: true);

$db      = App::db();
$siteId  = Site::id();
$actorId = ApiAuth::actorUserId();

// -----------------------------------------------------------------------------
// 📥 Resolve body shape: multipart file field, else JSON base64 fileContent
// -----------------------------------------------------------------------------
$isMultipart = isset($_FILES['file']) === true;
$meta        = $isMultipart === true ? $_POST : $body;

if ($isMultipart === true) {
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE  => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was selected.',
        ];
        ApiResponse::error($errorMessages[$file['error']] ?? 'File upload failed.', 400);
    }
    if (is_uploaded_file($file['tmp_name']) === false) {
        ApiResponse::error('Invalid upload', 400);
    }
    $binary   = file_get_contents($file['tmp_name']);
    if ($binary === false) {
        ApiResponse::error('Failed to read uploaded file', 500);
    }
    $origName = basename((string) $file['name']);
} else {
    $fileContentB64 = (string) ($body['fileContent'] ?? '');
    if ($fileContentB64 === '') {
        ApiResponse::error('file (multipart) or fileContent (base64) is required', 400);
    }
    // 🛡️ Reject on the DECLARED base64 length before allocating the decoded
    //    bytes (base64 inflates ~4/3) — prevents a transient memory spike from a
    //    huge blob (review LOW). The post-decode cap below is the exact check.
    $maxDeclared = (int) App::settings('documents.api.maxUploadBytes');
    if ($maxDeclared <= 0) {
        $maxDeclared = 10485760;
    }
    if (strlen($fileContentB64) > (int) ceil($maxDeclared * 4 / 3) + 4) {
        ApiResponse::error('File exceeds maximum size of ' . $maxDeclared . ' bytes', 400);
    }
    $binary = base64_decode($fileContentB64, true);
    if ($binary === false) {
        ApiResponse::error('fileContent is not valid base64', 400);
    }
    $origName = trim((string) ($body['fileName'] ?? ''));
    if ($origName === '') {
        $origName = 'upload';
    }
}

$fileSize = strlen($binary);
if ($fileSize <= 0) {
    ApiResponse::error('Uploaded file is empty', 400);
}

// 🛡️ Hard size cap — default to 10 MB when the setting is unset/non-positive
//    so a misconfiguration can never DISABLE the cap (review INFO).
$maxBytes = (int) App::settings('documents.api.maxUploadBytes');
if ($maxBytes <= 0) {
    $maxBytes = 10485760;
}
if ($fileSize > $maxBytes) {
    ApiResponse::error('File exceeds maximum size of ' . $maxBytes . ' bytes', 400);
}

// -----------------------------------------------------------------------------
// 🔍 finfo-sniff the REAL mime type from the bytes — never trust the client
// -----------------------------------------------------------------------------
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    Logger::errorPlatform('PHP', 'Error', 'API_DOC_CREATE_FINFO', 'finfo_open failed', '');
    ApiResponse::error('Unable to inspect uploaded file', 500);
}
$sniffedMime = finfo_buffer($finfo, $binary);
finfo_close($finfo);
if ($sniffedMime === false || array_key_exists($sniffedMime, DOC_API_ALLOWED_MIME_EXT) === false) {
    ApiResponse::error(
        'Unsupported or unrecognized file type' . ($sniffedMime !== false ? ': ' . $sniffedMime : ''),
        400
    );
}
$ext = DOC_API_ALLOWED_MIME_EXT[$sniffedMime];

// -----------------------------------------------------------------------------
// 📥 Metadata fields
// -----------------------------------------------------------------------------
$title = trim((string) ($meta['title'] ?? ''));
if ($title === '' || mb_strlen($title) > 255) {
    ApiResponse::error('title is required and must be ≤255 characters', 400);
}

$description = isset($meta['description']) === true ? trim((string) $meta['description']) : null;
if ($description === '') {
    $description = null;
}

$categoryId = null;
if (isset($meta['categoryID']) === true && $meta['categoryID'] !== null && $meta['categoryID'] !== '') {
    $categoryId = (int) $meta['categoryID'];
    $catCheck = $db->prepare('SELECT categoryID FROM tblDocCategories WHERE categoryID = ? AND siteID = ? LIMIT 1');
    if ($catCheck === false) {
        Logger::errorPlatform('MySQL', 'Error', 'API_DOC_CREATE_CAT_PREP', $db->error, '');
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

$eventId = null;
if (isset($meta['eventID']) === true && $meta['eventID'] !== null && $meta['eventID'] !== '') {
    $eventId = (int) $meta['eventID'];
    $evCheck = $db->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1');
    if ($evCheck === false) {
        Logger::errorPlatform('MySQL', 'Error', 'API_DOC_CREATE_EVENT_PREP', $db->error, '');
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

// 📌 isPublished — tolerant boolean parse (form fields arrive as strings)
$isPublished = 1;
if (array_key_exists('isPublished', $meta) === true) {
    $rawPub = $meta['isPublished'];
    if (is_bool($rawPub) === true) {
        $isPublished = $rawPub === true ? 1 : 0;
    } else {
        $rawPubStr   = strtolower(trim((string) $rawPub));
        $isPublished = in_array($rawPubStr, ['0', 'false', 'no', ''], true) === true ? 0 : 1;
    }
}

// -----------------------------------------------------------------------------
// 💾 Persist file — server-generated stored filename, never the client path
// -----------------------------------------------------------------------------
$storedFilename = bin2hex(random_bytes(16)) . '.' . $ext;
$uploadDir      = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'documents';
if (is_dir($uploadDir) === false) {
    mkdir($uploadDir, 0755, true);
}
$destPath = $uploadDir . DIRECTORY_SEPARATOR . $storedFilename;

if ($isMultipart === true) {
    $written = move_uploaded_file($_FILES['file']['tmp_name'], $destPath);
} else {
    $written = file_put_contents($destPath, $binary, LOCK_EX) !== false;
}
if ($written === false) {
    Logger::errorPlatform('PHP', 'Error', 'API_DOC_CREATE_WRITE', 'Failed to persist uploaded file', $destPath);
    ApiResponse::error('Failed to save uploaded file', 500);
}

// -----------------------------------------------------------------------------
// 💾 Insert tblDocuments row (mimeType = the SNIFFED type, not client-declared)
// -----------------------------------------------------------------------------
$stmt = $db->prepare(
    'INSERT INTO tblDocuments '
    . '(siteID, categoryID, eventID, title, description, fileName, filePath, fileSize, mimeType, isPublished, uploadedByID) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    @unlink($destPath);
    Logger::errorPlatform('MySQL', 'Error', 'API_DOC_CREATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param(
    'iiissssisii',
    $siteId, $categoryId, $eventId, $title, $description, $origName, $storedFilename,
    $fileSize, $sniffedMime, $isPublished, $actorId
);
$ok = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($ok === false) {
    @unlink($destPath);
    Logger::errorPlatform('MySQL', 'Error', 'API_DOC_CREATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to create document', 500);
}

Logger::audit('tblDocuments', $newId, 'create', null, [
    'siteID'       => $siteId,
    'categoryID'   => $categoryId,
    'eventID'      => $eventId,
    'title'        => $title,
    'description'  => $description,
    'fileName'     => $origName,
    'filePath'     => $storedFilename,
    'fileSize'     => $fileSize,
    'mimeType'     => $sniffedMime,
    'isPublished'  => $isPublished,
    'uploadedByID' => $actorId,
]);
Logger::activity('ApiDocumentCreate', 'API: uploaded document #' . $newId . ' "' . $title . '"');

ApiResponse::success(['documentID' => $newId], 201);
