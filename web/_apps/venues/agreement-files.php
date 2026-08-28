<?php
// Path: _apps/venues/agreement-files.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Upload / Delete an Agreement File 📎
 * -----------------------------------------------------------------------------
 * POST-only handler behind `agreements.php`'s upload sub-form. Security
 * item 3 pipeline (02 §12), identical in shape to `_apps/assets/resource-
 * save.php`'s own upload block:
 *   1. Never trusts the client Content-Type or filename.
 *   2. Hard size cap from `venues.maxFileSize` (10 MB fallback when the
 *      setting is missing/zero/non-positive — a misconfiguration can never
 *      disable the cap).
 *   3. finfo-SNIFFS the real MIME from the uploaded bytes and rejects
 *      anything not a key of `Venues::AGREEMENT_FILE_MIME_EXT` (pdf/png/
 *      jpg/webp/docx — no SVG, ever).
 *   4. Hands the sniffed mime + tmp path to `Venues::attachAgreementFile()`,
 *      which derives the stored extension from the SNIFFED mime (never the
 *      client filename) and writes the random `bin2hex(random_bytes(16))`
 *      name under `_uploads/venues/agreements/` — this handler never
 *      builds that path itself.
 *
 * `delete` re-fetches the file row site-scoped inside
 * `Venues::deleteAgreementFile()` before touching disk or the row.
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate.
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /venues/agreements');
    exit();
}

// 🔐 CSRF FIRST — before any side-effect.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /venues/agreements');
    exit();
}

$siteId      = Site::id();
$userId      = (int) ($_SESSION['user_id'] ?? 0);
$action      = (string) ($_POST['action'] ?? 'upload');
$agreementId = (int) ($_POST['agreementID'] ?? 0);

$fail = static function (string $msg) use ($agreementId): never {
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = 'danger';
    header('Location: /venues/agreements?edit=' . $agreementId);
    exit();
};

// -----------------------------------------------------------------------------
// 🗑️ Delete.
// -----------------------------------------------------------------------------
if ($action === 'delete') {
    $fileId = (int) ($_POST['fileID'] ?? 0);
    if ($fileId > 0 && Venues::deleteAgreementFile($fileId, $siteId, $userId) === true) {
        $_SESSION['flash_msg']  = 'File removed.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_msg']  = 'File not found.';
        $_SESSION['flash_type'] = 'danger';
    }
    header('Location: /venues/agreements?edit=' . $agreementId);
    exit();
}

// -----------------------------------------------------------------------------
// ⬆️ Upload.
// -----------------------------------------------------------------------------
if (isset($_FILES['file']) === false || (int) $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    $fail('No file was selected.');
}
$file = $_FILES['file'];
if ((int) $file['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE  => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
        UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
    ];
    $fail($errorMessages[$file['error']] ?? 'File upload failed.');
}
if (is_uploaded_file($file['tmp_name']) === false) {
    $fail('Invalid upload.');
}

// 🛡️ Hard size cap — a missing/zero setting can never disable the cap.
$maxSize = (int) App::settings('venues.maxFileSize');
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

// 🔍 finfo-SNIFF the real MIME from the bytes — never trust the client.
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    Logger::errorPlatform('PHP', 'Error', 'VENUES_AGREEMENT_FINFO', 'finfo_open failed', '');
    $fail('Unable to inspect uploaded file.');
}
$sniffedMime = finfo_buffer($finfo, $binary);
finfo_close($finfo);
if ($sniffedMime === false || array_key_exists($sniffedMime, Venues::AGREEMENT_FILE_MIME_EXT) === false) {
    $fail('Unsupported file type (pdf, png, jpg, webp or docx only)' . ($sniffedMime !== false ? ': ' . $sniffedMime : '') . '.');
}

$title = trim((string) ($_POST['title'] ?? ''));

$result = Venues::attachAgreementFile(
    $siteId,
    $agreementId,
    [
        'tmp_name' => (string) $file['tmp_name'],
        'name'     => (string) $file['name'],
        'size'     => (int) $file['size'],
        'mime'     => $sniffedMime,
    ],
    $title,
    $userId
);

if ($result['fileID'] <= 0) {
    $fail($result['error'] ?? 'Could not save the uploaded file.');
}

$_SESSION['flash_msg']  = 'File uploaded.';
$_SESSION['flash_type'] = 'success';
header('Location: /venues/agreements?edit=' . $agreementId);
exit();
