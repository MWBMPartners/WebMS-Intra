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
 * A DOCUMENT THAT WAS NOT WRITTEN IN FULL IS NEVER RECORDED (#720). The base64
 * branch used to test `file_put_contents(...) !== false`, which accepts a SHORT
 * count, so a part-written document got a `tblDocuments` row and was offered
 * for download. The reasoning, and why this file does NOT need the
 * temporary-name-and-rename shape that `Pdf::writeOrWhyNot()` does, is beside
 * the write itself. Guards: `tools/audit-checks/check_document_write_verified.py`
 * and `tools/document-write-check-selftest.py`.
 *
 * @package   Portal\API\Documents
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.2.0
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
// ⚠️ No `finfo_close()` (#1003). It has done nothing since PHP 8.1 — the
//    finfo object is freed when it goes out of scope — and PHP 8.5 deprecates
//    it, so every upload here wrote a deprecation notice into the portal's
//    error log. Removing it changes nothing about the type found above.
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
    // Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
    $evCheck = $db->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL LIMIT 1');
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

// -----------------------------------------------------------------------------
// 🪤 THE TWO BRANCHES ANSWER THE SAME QUESTION IN TWO DIFFERENT WAYS (#720)
// -----------------------------------------------------------------------------
// They used to share one `$written` boolean, and that is how the fault hid:
// the multipart test was right, the base64 test was wrong, and they looked
// alike. The two answers are different KINDS of thing, so they are judged
// separately now and both feed one reason.
//
//   multipart — `move_uploaded_file()` answers a BOOLEAN. There is no byte
//   count to compare, so `=== false` is the right and only test. This branch
//   was already correct and is deliberately unchanged. What was NOT
//   established here, so that nobody reads more into it: whether PHP's own
//   copy fallback inside `move_uploaded_file()` can ever report success after
//   writing only part of the file. The clean-up below covers the file it
//   leaves behind on a reported FAILURE either way.
//
//   base64 — `file_put_contents()` answers THE NUMBER OF BYTES IT WROTE.
//   `!== false` therefore accepted a SHORT count: on a full disk or over a
//   hosting quota part of the document was written, this test said "written",
//   and the INSERT below recorded it as stored and offered it for download,
//   with nothing anywhere reporting a problem. That is #711's fault in this
//   file, on a separate code path. Compare the count.
//
// `strlen($binary)` rather than `$fileSize`, although `$fileSize` is set to
// exactly `strlen($binary)` above and nothing in between touches `$binary`:
// written this way the comparison stands on its own and cannot be loosened by
// an edit far up the page. `$fileSize` is what goes in the row.
//
// -----------------------------------------------------------------------------
// WHY THIS WRITES IN PLACE, where Pdf::writeOrWhyNot() writes under a
// temporary name and renames (#711) — checked rather than assumed
// -----------------------------------------------------------------------------
// That shape exists to stop a failed write destroying a document that is
// already there, because the PDF paths use STABLE names (`claim_<id>.pdf`,
// `statement-<donor>-<period>.pdf`) recorded in the database. Here the
// destination CANNOT name an existing document:
//
//   • `$storedFilename` above is `bin2hex(random_bytes(16))` plus an extension
//     taken from the sniffed-MIME allowlist — 32 hex characters from 16
//     cryptographically random bytes, made fresh on every request, never read
//     from the client or from the database.
//   • The only other writer of `_uploads/documents/` is the legacy form
//     handler `_apps/documents/upload.php`, whose names are
//     `date('Ymd_His') . '_' . bin2hex(random_bytes(4))`. That form always
//     contains underscores, so it can never equal a 32-character pure-hex
//     name.
//   • `_apps/documents/download.php` serves whatever `tblDocuments.filePath`
//     names and invents no filename of its own.
//   • Those are the only two writers and the only reader of THIS folder.
//     Established with the narrow command, which is the one that answers the
//     question:
//         grep -rn "_uploads' . DIRECTORY_SEPARATOR . 'documents'" web/ --include='*.php'
//
//     ⚠️ AN EARLIER VERSION OF THIS NOTE LISTED THE OTHER UPLOAD FOLDERS AS
//        "`assets`, `noticeboard` and `calendar`" and an independent check
//        found that wrong: `grep -rn _uploads web --include="*.php"` also
//        lists `pdfs`, `giving`, `expenses`, `mail-queue`, `photos`,
//        `recordings`, `venues` and `documents`. The CONCLUSION was right and
//        the enumeration offered as its evidence was not — which is the shape
//        this repository keeps being bitten by, so the wrong list is deleted
//        rather than corrected: nothing here needs a list of every folder.
//
// So a failure here has nothing to destroy, which is also what makes the
// clean-up below safe — the file it removes is certainly the one this request
// just part-wrote, never somebody's existing document. Writing in place keeps
// the cost of the other shape away too: a `.part-…` file left by a process
// killed between write and rename is swept by nothing and cannot be reached by
// an erasure (#721). DO NOT "IMPROVE" THIS INTO A TEMPORARY-AND-RENAME, and do
// not take the `@unlink($destPath)` calls after the INSERT below for the same
// fault either: all three are safe for the one reason above, and only for it.
// If stored filenames ever stop being freshly generated, this reasoning goes
// with them.
$whyNotWritten = '';
if ($isMultipart === true) {
    if (move_uploaded_file($_FILES['file']['tmp_name'], $destPath) === false) {
        $whyNotWritten = 'the uploaded file could not be moved into place';
    }
} else {
    $written = file_put_contents($destPath, $binary, LOCK_EX);
    if ($written !== strlen($binary)) {
        $whyNotWritten = 'it was not written in full (' . strlen($binary) . ' bytes to write, '
            . ($written === false ? 'the write failed outright' : $written . ' written') . ')';
    }
}
if ($whyNotWritten !== '') {
    // 🧹 Nothing part-written may be left on disk. Same reasoning as
    //    `Pdf::tidyAway()` (#711), including the honesty of it: say the file
    //    was removed ONLY when there was one to remove, because telling
    //    whoever reads `tblErrors` that a stub was tidied away when the write
    //    never created one sends them looking in the wrong place. `is_file()`
    //    here decides whether there is anything to delete — it is NOT the
    //    success test, which is the reason string above; judging success by
    //    `is_file()` is #711's original fault, because it is true for a file
    //    that exists and holds nothing.
    //    `clearstatcache()` first: PHP remembers the answers to file tests
    //    within one request, and the call that just failed may have asked
    //    about this very path.
    clearstatcache(true, $destPath);
    if (is_file($destPath) === true) {
        $whyNotWritten .= unlink($destPath) === true
            ? ', and the part-written file was removed'
            : ', and the part-written file COULD NOT BE REMOVED and is still on disk'
                . ' (it is safe to delete)';
    }
    // The stored filename is server-generated random hex and the folder is
    // ours, so neither names a person; the client's own filename is NOT logged.
    Logger::errorPlatform(
        'PHP',
        'Error',
        'API_DOC_CREATE_WRITE',
        'An uploaded document could not be stored in full, so it was not recorded',
        'Intended file: ' . $destPath . ' — ' . $whyNotWritten
    );
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
