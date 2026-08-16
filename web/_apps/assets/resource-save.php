<?php
// Path: _apps/assets/resource-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Save / Delete Asset Resource 📎
 * -----------------------------------------------------------------------------
 * POST handler for the item.php Resources panel — attaches a manual/guide/
 * photo/receipt/etc to an asset (`action=add`, the default) OR removes one
 * (`action=delete`). Both actions share this one route/file, mirroring the
 * house convention for small CRUD screens in this app (see
 * `_apps/assets/categories.php`'s header for the same pattern) — no
 * separate "assets/resource-delete" route is registered in migration
 * 159_asset_tracker.sql, so folding delete into this handler avoids adding
 * one.
 *
 * Gate: admin OR asset_manager role OR a responsible owner-party for the
 * asset (`AssetRegister::isResponsibleFor()`) — WIDER than save.php/
 * delete.php's manager-only gate, because attaching a receipt/manual to an
 * asset you're the custodian of is a much lower-stakes action than editing
 * the asset record itself.
 *
 * UPLOAD SECURITY (mirrors `_apps/documents/api/create.php` /
 * `_apps/noticeboard/api/upload.php`):
 *   1. Never trusts the client-declared Content-Type or filename.
 *   2. finfo-SNIFFS the real MIME from the bytes and rejects anything not
 *      in ASSETS_RESOURCE_ALLOWED_MIME_EXT below (pdf/png/jpg/jpeg/gif/
 *      webp/mp4/webm/txt/docx/xlsx — the sub-issue's exact allow-list).
 *   3. Generates a random, server-side stored filename — the extension is
 *      derived from the SNIFFED mime, never the client filename.
 *   4. Enforces a hard size cap (`assets.maxFileSize` setting, seeded
 *      10 MB by migration 159) — a missing/zero setting can never disable
 *      the cap (falls back to 10 MB, never "unlimited").
 *   5. Files land OUTSIDE the webroot, under
 *      PORTAL_ROOT/_uploads/assets/ — served back out only via the
 *      confidential-gated `resource-download.php` stream, never a direct
 *      URL.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

// 🛡️ Upload allow-list — sniffed (finfo) MIME ⇒ safe stored extension.
// Defined unconditionally at the top of the file (PHP requires top-level
// `const` to execute before any early-return branch reaches it) — matches
// where `_apps/noticeboard/api/upload.php` places its equivalent constant.
const ASSETS_RESOURCE_ALLOWED_MIME_EXT = [
    'application/pdf'                                                          => 'pdf',
    'image/png'                                                                => 'png',
    'image/jpeg'                                                               => 'jpg',
    'image/gif'                                                                => 'gif',
    'image/webp'                                                               => 'webp',
    'video/mp4'                                                                => 'mp4',
    'video/webm'                                                               => 'webm',
    'text/plain'                                                               => 'txt',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'  => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'        => 'xlsx',
];

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

$db      = App::db();
$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$assetId = (int) ($_POST['assetID'] ?? 0);
$action  = (string) ($_POST['action'] ?? 'add');

$asset = $assetId > 0 ? AssetRegister::get($assetId) : null;
if ($asset === null || (int) $asset['siteID'] !== $siteId) {
    $_SESSION['flash_msg']  = 'Asset not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

// 🛡️ Manager OR responsible owner-party — matches item.php's own
// visibility gate for the Resources panel this form lives on.
$canManage = App::isAdmin() === true || App::hasRole('asset_manager') === true;
if ($canManage === false && AssetRegister::isResponsibleFor($assetId) === false) {
    http_response_code(403);
    exit('Forbidden');
}

// -----------------------------------------------------------------------------
// 🗑️ Delete
// -----------------------------------------------------------------------------
if ($action === 'delete') {
    $resourceId = (int) ($_POST['resourceID'] ?? 0);
    if ($resourceId > 0) {
        // 🔒 Confirm the resource actually belongs to THIS asset before
        // deleting — a resourceID alone isn't enough to trust (an owner-
        // party for asset A must not be able to delete a resource
        // belonging to asset B just by guessing/tampering with the id).
        $chk = $db->prepare('SELECT 1 FROM tblAssetResources WHERE resourceID = ? AND assetID = ? LIMIT 1');
        $belongs = false;
        if ($chk !== false) {
            $chk->bind_param('ii', $resourceId, $assetId);
            $chk->execute();
            $belongs = $chk->get_result()->fetch_assoc() !== null;
            $chk->close();
        }
        if ($belongs === true) {
            AssetRegister::deleteResource($resourceId, $userId);
            $_SESSION['flash_msg']  = 'Resource removed.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_msg']  = 'Resource not found for this asset.';
            $_SESSION['flash_type'] = 'danger';
        }
    }
    header('Location: /assets/item?id=' . $assetId);
    exit();
}

// -----------------------------------------------------------------------------
// ➕ Add — EITHER an external link OR an uploaded file, never both/neither.
// -----------------------------------------------------------------------------
$fail = static function (string $msg) use ($assetId): never {
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets/item?id=' . $assetId);
    exit();
};

$resourceType = (string) ($_POST['resourceType'] ?? 'other');
if (in_array($resourceType, AssetRegister::RESOURCE_TYPES, true) === false) {
    $resourceType = 'other';
}
$title = trim((string) ($_POST['title'] ?? ''));
if ($title === '' || mb_strlen($title) > 255) {
    $fail('A resource title (max 255 characters) is required.');
}

$linkUrlRaw = trim((string) ($_POST['linkUrl'] ?? ''));
$hasFile    = isset($_FILES['file']) === true && (int) $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;

// 🔀 Exactly one of linkUrl / file — never both, never neither.
if (($linkUrlRaw !== '') === $hasFile) {
    $fail('Provide EITHER a link OR a file upload for this resource — not both, not neither.');
}

$resourceData = [
    'resourceType' => $resourceType,
    'title'        => $title,
    'linkUrl'      => null,
    'fileName'     => null,
    'filePath'     => null,
    'fileSize'     => null,
    'mimeType'     => null,
    'isPublic'     => isset($_POST['isPublic']) === true ? 1 : 0,
];

if ($linkUrlRaw !== '') {
    // 🌐 http(s) only — blocks javascript:/data:/file: schemes.
    $scheme = parse_url($linkUrlRaw, PHP_URL_SCHEME);
    if (in_array(strtolower((string) $scheme), ['http', 'https'], true) === false) {
        $fail('Link must start with http:// or https://.');
    }
    if (mb_strlen($linkUrlRaw) > 500) {
        $fail('Link is too long (max 500 characters).');
    }
    $resourceData['linkUrl'] = $linkUrlRaw;
} else {
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE  => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was selected.',
        ];
        $fail($errorMessages[$file['error']] ?? 'File upload failed.');
    }
    if (is_uploaded_file($file['tmp_name']) === false) {
        $fail('Invalid upload.');
    }

    // 🛡️ Hard size cap — default to 10 MB when the setting is unset/
    // non-positive so a misconfiguration can never DISABLE the cap.
    $maxSize = (int) App::settings('assets.maxFileSize');
    if ($maxSize <= 0) {
        $maxSize = 10485760;
    }
    if ((int) $file['size'] > $maxSize) {
        $fail('File exceeds maximum size of ' . round($maxSize / 1048576, 1) . ' MB.');
    }

    $binary = file_get_contents($file['tmp_name']);
    if ($binary === false || strlen($binary) === 0) {
        $fail('Uploaded file is empty or unreadable.');
    }

    // 🔍 finfo-SNIFF the real MIME from the bytes — never trust the
    // client-declared Content-Type or the filename's extension.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        Logger::errorPlatform('PHP', 'Error', 'ASSETS_RESOURCE_FINFO', 'finfo_open failed', '');
        $fail('Unable to inspect uploaded file.');
    }
    $sniffedMime = finfo_buffer($finfo, $binary);
    finfo_close($finfo);
    if ($sniffedMime === false || array_key_exists($sniffedMime, ASSETS_RESOURCE_ALLOWED_MIME_EXT) === false) {
        $fail('Unsupported file type for asset resources (pdf/png/jpg/gif/webp/mp4/webm/txt/docx/xlsx only)'
            . ($sniffedMime !== false ? ': ' . $sniffedMime : '') . '.');
    }
    $ext = ASSETS_RESOURCE_ALLOWED_MIME_EXT[$sniffedMime];

    $uploadDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'assets';
    if (is_dir($uploadDir) === false) {
        mkdir($uploadDir, 0755, true);
    }
    // 🎲 Server-generated safe filename — house convention (see
    // _apps/documents/upload.php) — NEVER the client-supplied name, and
    // the extension comes from the SNIFFED mime, not the original filename.
    $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

    if (move_uploaded_file($file['tmp_name'], $destPath) === false) {
        $fail('Failed to save uploaded file.');
    }

    $resourceData['fileName'] = basename((string) $file['name']);
    $resourceData['filePath'] = $safeName;
    $resourceData['fileSize'] = (int) $file['size'];
    $resourceData['mimeType'] = $sniffedMime;
}

$newId = AssetRegister::addResource($assetId, $resourceData, $userId);
if ($newId > 0) {
    $_SESSION['flash_msg']  = 'Resource added.';
    $_SESSION['flash_type'] = 'success';
} else {
    // 🧹 Clean up an orphaned upload if the DB insert failed after the file
    // was already written to disk.
    if (($resourceData['filePath'] ?? null) !== null) {
        @unlink(PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $resourceData['filePath']);
    }
    $_SESSION['flash_msg']  = 'Could not save the resource.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /assets/item?id=' . $assetId);
exit();
