<?php
// Path: _apps/noticeboard/api/upload.php
/**
 * -----------------------------------------------------------------------------
 * Noticeboard — Media Upload 📤 (#363)
 * -----------------------------------------------------------------------------
 * POST /api/noticeboard/upload   multipart/form-data { file }
 *
 * Replaces the `data:` URI path the board's admin editor previously fell back
 * to (client-side FileReader.readAsDataURL) — that path is explicitly REJECTED
 * server-side by save.php's pre-transaction validation pass (see that file's
 * `str_starts_with($mediaUrl, 'data:')` check). This endpoint is the real
 * upload the client now calls instead (see noticeboard.noeval.js's
 * `host.upload(file)` call + index.php's NoticeboardHost.upload bridge).
 *
 * SECURITY — mirrors documents/api/create.php (#323 Phase 2) exactly:
 *   1. Never trusts the client-declared MIME type or filename.
 *   2. finfo-SNIFFS the real MIME from the bytes and rejects anything not in
 *      NOTICEBOARD_UPLOAD_ALLOWED_MIME_EXT below (POSTER media only — a
 *      narrower allowlist than documents': image/png|jpeg|gif|webp,
 *      video/mp4|webm — nothing the board's poster card can't already render).
 *   3. Generates a random, server-side stored filename — the extension is
 *      derived from the SNIFFED mime, never the client filename.
 *   4. Enforces a hard size cap (`noticeboard.upload.maxBytes` setting,
 *      default 15 MB — can never be disabled by a bad/missing setting value).
 *
 * STORAGE + SERVING DESIGN (A — chosen over a web-served uploads dir):
 *   Files land under PORTAL_ROOT/_uploads/noticeboard/, OUTSIDE the webroot —
 *   identical precedent to documents/api/create.php's _uploads/documents/.
 *   The sibling PUBLIC route GET /noticeboard/media?f=<storedName> (see
 *   media.php) streams the bytes back out with no login gate — posters are
 *   shareable via QR, so the media itself must render for an anonymous
 *   scanner even though the board's editor page (/noticeboard) stays
 *   login-gated. Funnelling every read through media.php (rather than a
 *   web-served dir) lets that ONE handler enforce the strict
 *   `[a-f0-9]{32}\.ext` token allowlist + a DB-row existence check, instead of
 *   relying on webroot/Apache listing hygiene to keep arbitrary files out of
 *   a public directory.
 *
 * Auth/CSRF/gate: IDENTICAL prelude to noticeboard/api/save.php — site admins
 * only (App::isSiteAdmin(), session-mode only — this is the session-only
 * admin-UI upload path, not a bearer-key integration surface). CSRF for this
 * multipart request MUST arrive via the X-CSRF-TOKEN header — requireWrite()'s
 * body()->csrf_token fallback only exists for JSON requests (php://input isn't
 * JSON here), exactly as documented in documents/api/create.php's docblock.
 *
 * @package   Portal\Apps\Noticeboard
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/363
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\App;
use Portal\Core\ApiResponse;
use Portal\Core\Logger;
use Portal\Core\Site;

// 🛡️ POSTER media allowlist — sniffed (finfo) MIME ⇒ safe stored-file
//    extension. Deliberately narrower than documents/api/create.php's: only
//    the media types the board's poster card can render (image tile or
//    inline <video>).
const NOTICEBOARD_UPLOAD_ALLOWED_MIME_EXT = [
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'video/mp4'  => 'mp4',
    'video/webm' => 'webm',
];

ApiAuth::requireMethod('POST');
// api.noticeboard.upload.enabled is already gated per-site by
// ApiRouter::resolveEnabledFlag before this handler runs (#323 Phase 2); a
// second handler-level check via App::settings() would read the frozen
// host-site snapshot and could wrongly 403 a valid bearer request.
ApiAuth::requireWrite('noticeboard:write', sessionNeedsAdmin: false);

// 🛡️ Site-admin gate — kept verbatim from save.php (see that file's comment
// for why this stays a distinct, finer-grained check rather than folding
// into ApiAuth's sessionNeedsAdmin: App::isSiteAdmin() reads App::user()
// (session-only), so it fails closed for bearer keys too).
if (ApiAuth::source() === 'session' && App::isSiteAdmin() === false) {
    ApiResponse::error('Admin access required', 403);
}

$db     = App::db();
$siteId = Site::id();
$userId = ApiAuth::actorUserId();

// -----------------------------------------------------------------------------
// 📥 multipart file field
// -----------------------------------------------------------------------------
if (isset($_FILES['file']) === false) {
    ApiResponse::error('file is required (multipart/form-data)', 400);
}
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

$fileSize = (int) $file['size'];
if ($fileSize <= 0) {
    ApiResponse::error('Uploaded file is empty', 400);
}

// 🛡️ Hard size cap — default to 15 MB when the setting is unset/non-positive
//    so a misconfiguration can never DISABLE the cap.
$maxBytes = (int) App::settings('noticeboard.upload.maxBytes');
if ($maxBytes <= 0) {
    $maxBytes = 15728640;
}
if ($fileSize > $maxBytes) {
    ApiResponse::error('File exceeds maximum size of ' . $maxBytes . ' bytes', 400);
}

$binary = file_get_contents($file['tmp_name']);
if ($binary === false) {
    ApiResponse::error('Failed to read uploaded file', 500);
}

// -----------------------------------------------------------------------------
// 🔍 finfo-sniff the REAL mime type from the bytes — never trust the client
// -----------------------------------------------------------------------------
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    Logger::errorPlatform('PHP', 'Error', 'NOTICEBOARD_UPLOAD_FINFO', 'finfo_open failed', '');
    ApiResponse::error('Unable to inspect uploaded file', 500);
}
$sniffedMime = finfo_buffer($finfo, $binary);
finfo_close($finfo);
if ($sniffedMime === false || array_key_exists($sniffedMime, NOTICEBOARD_UPLOAD_ALLOWED_MIME_EXT) === false) {
    ApiResponse::error(
        'Unsupported file type for posters (png/jpeg/gif/webp/mp4/webm only)'
            . ($sniffedMime !== false ? ': ' . $sniffedMime : ''),
        400
    );
}
$ext       = NOTICEBOARD_UPLOAD_ALLOWED_MIME_EXT[$sniffedMime];
$mediaType = str_starts_with($sniffedMime, 'video/') === true ? 'video' : 'image';

// -----------------------------------------------------------------------------
// 💾 Persist file — server-generated stored filename, never the client path
// -----------------------------------------------------------------------------
$storedName = bin2hex(random_bytes(16)) . '.' . $ext;
$uploadDir  = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'noticeboard';
if (is_dir($uploadDir) === false) {
    mkdir($uploadDir, 0755, true);
}
$destPath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;

if (move_uploaded_file($file['tmp_name'], $destPath) === false) {
    Logger::errorPlatform('PHP', 'Error', 'NOTICEBOARD_UPLOAD_WRITE', 'Failed to persist uploaded file', $destPath);
    ApiResponse::error('Failed to save uploaded file', 500);
}

// -----------------------------------------------------------------------------
// 💾 Track the upload — lets media.php resolve the token + lets the save.php
//    soft-delete step (via Portal\Core\NoticeboardMedia) find + purge orphans.
// -----------------------------------------------------------------------------
$stmt = $db->prepare(
    'INSERT INTO tblNoticeboardUploads (siteID, storedName, mimeType, fileSize, createdByID) '
    . 'VALUES (?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    @unlink($destPath);
    Logger::errorPlatform('MySQL', 'Error', 'NOTICEBOARD_UPLOAD_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('issii', $siteId, $storedName, $sniffedMime, $fileSize, $userId);
$ok    = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($ok === false) {
    @unlink($destPath);
    Logger::errorPlatform('MySQL', 'Error', 'NOTICEBOARD_UPLOAD_FAIL', $db->error, '');
    ApiResponse::error('Failed to record upload', 500);
}

Logger::activity('NoticeboardUpload', 'Uploaded noticeboard media #' . $newId . ' (' . $storedName . ')');

ApiResponse::success([
    'url'       => '/noticeboard/media?f=' . rawurlencode($storedName),
    'mediaType' => $mediaType,
], 201);
