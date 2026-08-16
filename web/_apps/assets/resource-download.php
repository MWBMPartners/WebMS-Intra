<?php
// Path: _apps/assets/resource-download.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Download Asset Resource 📎
 * -----------------------------------------------------------------------------
 * Streams an uploaded asset resource file from PORTAL_ROOT/_uploads/assets/
 * (files outside the webroot, only ever reachable through this handler —
 * mirrors `_apps/documents/download.php`).
 *
 * PATH SAFETY: `basename()` is applied to the DB-stored `filePath` before it
 * ever touches the filesystem, so even if that column were somehow
 * corrupted with `../` segments, the resolved path can never escape
 * `_uploads/assets/`. `filePath` is always a server-generated name (see
 * resource-save.php) — this is defence-in-depth, not the primary control.
 *
 * CONFIDENTIAL-ASSET GATE: IDENTICAL rule to item.php — admin, asset_manager,
 * or a responsible owner-party may download a resource belonging to a
 * confidential asset; everyone else gets the SAME 404 as "resource doesn't
 * exist" (no oracle that would let a logged-in-but-unprivileged user learn
 * an asset is confidential just by trying its resource ids).
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
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$resourceId = (int) ($_GET['id'] ?? 0);
$siteId     = Site::id();

if ($resourceId <= 0) {
    Router::renderError(404);
    return;
}

$db = App::db();
$stmt = $db->prepare('SELECT * FROM tblAssetResources WHERE resourceID = ? AND siteID = ? LIMIT 1');
if ($stmt === false) {
    Router::renderError(500);
    return;
}
$stmt->bind_param('ii', $resourceId, $siteId);
$stmt->execute();
$resource = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($resource === null || $resource === false) {
    Router::renderError(404);
    return;
}
if (($resource['filePath'] ?? null) === null || (string) $resource['filePath'] === '') {
    // 🔗 This resource is a link, not an uploaded file — nothing to stream.
    Router::renderError(404);
    return;
}

$assetId = (int) $resource['assetID'];
$asset   = AssetRegister::get($assetId);
if ($asset === null) {
    Router::renderError(404);
    return;
}

// 🔒 Confidential-asset gate — see file header.
if ((int) $asset['isConfidential'] === 1) {
    $privileged = App::isAdmin() === true
        || App::hasRole('asset_manager') === true
        || AssetRegister::isResponsibleFor($assetId) === true;
    if ($privileged === false) {
        Router::renderError(404);
        return;
    }
}

// 📁 basename() the stored path before touching the filesystem — see file
// header's PATH SAFETY note.
$diskPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'assets'
    . DIRECTORY_SEPARATOR . basename((string) $resource['filePath']);

if (is_readable($diskPath) === false) {
    Router::renderError(404);
    return;
}

$mimeType = (string) ($resource['mimeType'] ?? 'application/octet-stream');
$fileName = (string) ($resource['fileName'] ?? basename($diskPath));

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . addcslashes($fileName, '"') . '"');
header('Content-Length: ' . (string) filesize($diskPath));
header('Cache-Control: no-cache, must-revalidate');
readfile($diskPath);
exit();
