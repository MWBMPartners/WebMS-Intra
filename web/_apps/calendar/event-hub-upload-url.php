<?php
// Path: _apps/calendar/event-hub-upload-url.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Team Hub direct-upload mint ☁️⬆️ (#386 Phase 1.5)
 * -----------------------------------------------------------------------------
 * POST /calendar/event/hub/upload-url (JSON in/out, page route — NOT api/*).
 *
 * Mints a one-time Cloudflare Stream direct-upload URL for a coordinator to
 * PUT/POST a video file straight to Cloudflare from the browser — the file
 * never touches this server. Admin OR per-event coordinator only, CSRF
 * required, cross-site guarded, rate-limited per user per hour.
 *
 * Request (form-encoded): csrf_token, eventID, title, requiresSignedUrl
 * (optional '1'/'0', defaults to `cfstream.defaultRequireSignedUrls`),
 * allowedOrigins (optional comma-separated hostnames, defaults to
 * `cfstream.allowedOrigins`).
 *
 * Response: {ok:true, uploadURL, videoID, uid, maxBytes, csrfToken} or
 * {ok:false, error, csrfToken?}. `csrfToken` is the ROTATED session token
 * (Auth::verifyCsrf() rotates on every successful check) — the calling JS
 * MUST adopt it for the next request (mint is one-shot, but the status-poll
 * loop this response kicks off makes several more CSRF-protected POSTs
 * without a full page reload, so the client-side token has to track the
 * server's rotation or the second poll fails).
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/386
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\CloudflareStream;
use Portal\Core\Logger;
use Portal\Core\Settings;
use Portal\Core\Site;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf((string) ($_POST['csrf_token'] ?? '')) === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit();
}

$eventId = (int) ($_POST['eventID'] ?? 0);
$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit();
}

// 🛡️ Cross-site guard — mirrors event-hub-save.php.
// Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$eventOk = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($eventOk === false) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'event', 'csrfToken' => Auth::csrfToken()]);
    exit();
}

if (CloudflareStream::isConfigured() === false) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_configured', 'csrfToken' => Auth::csrfToken()]);
    exit();
}

// 🚦 Rate limit — count this user's successful mints in the last hour.
//    Logger::activity('CfStreamUploadMinted', …) below IS the counter; no
//    separate rate-limit table needed.
$maxPerHour = (int) Settings::get('cfstream.uploadMintPerHour', 20);
if ($maxPerHour <= 0) {
    $maxPerHour = 20;
}
$stmt = $mysqli->prepare(
    "SELECT COUNT(*) AS c FROM tblActivityLogs "
    . "WHERE userID = ? AND activityType = 'CfStreamUploadMinted' "
    . "AND timestamp >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$mintedThisHour = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
if ($mintedThisHour >= $maxPerHour) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'csrfToken' => Auth::csrfToken()]);
    exit();
}

$title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255);
if ($title === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'title', 'csrfToken' => Auth::csrfToken()]);
    exit();
}

$requiresSigned = isset($_POST['requiresSignedUrl']) === true
    ? ((string) $_POST['requiresSignedUrl'] === '1')
    : ((string) Settings::get('cfstream.defaultRequireSignedUrls', 'true') === 'true');

// 🛡️ Allowed origins — bare hostnames, comma-separated, capped at 10; falls
//    back to the admin-configured site default when the form leaves it
//    blank. The WHOLE request is rejected (nothing minted) on a bad entry.
$allowedOriginsRaw = trim((string) ($_POST['allowedOrigins'] ?? ''));
if ($allowedOriginsRaw === '') {
    $allowedOriginsRaw = (string) Settings::get('cfstream.allowedOrigins', '');
}
$originParts = [];
if ($allowedOriginsRaw !== '') {
    $candidates = array_filter(array_map('trim', explode(',', $allowedOriginsRaw)));
    $candidates = array_slice(array_values($candidates), 0, 10);
    foreach ($candidates as $candidate) {
        if (preg_match('/^[a-z0-9.-]+$/i', $candidate) !== 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'origins', 'csrfToken' => Auth::csrfToken()]);
            exit();
        }
        $originParts[] = strtolower($candidate);
    }
}
$allowedOriginsClean = implode(',', $originParts);
$allowedOriginsArg   = $allowedOriginsClean !== '' ? $allowedOriginsClean : null;

$maxDuration = (int) Settings::get('cfstream.maxUploadDurationSeconds', 3600);

$minted = CloudflareStream::createDirectUpload($maxDuration, $requiresSigned, $allowedOriginsArg);
if ($minted === null) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'cloudflare', 'csrfToken' => Auth::csrfToken()]);
    exit();
}

// 💾 INSERT the pending row — sourceUrl NULL marks it portal-uploaded (as
//    opposed to a pasted external reference), videoRef = CF's minted uid.
$nextSort = 1;
$stmt = $mysqli->prepare('SELECT COALESCE(MAX(sortOrder), 0) + 1 AS nextSort FROM tblEventHubVideos WHERE eventID = ?');
$stmt->bind_param('i', $eventId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$nextSort = (int) ($row['nextSort'] ?? 1);
$stmt->close();

$videoRef          = $minted['uid'];
$requiresSignedInt = $requiresSigned === true ? 1 : 0;

$stmt = $mysqli->prepare(
    'INSERT INTO tblEventHubVideos '
    . '(eventID, provider, videoRef, sourceUrl, title, requiresSignedUrl, allowedOrigins, uploadStatus, sortOrder, createdByID) '
    . "VALUES (?, 'cloudflare', ?, NULL, ?, ?, ?, 'pending', ?, ?)"
);
$stmt->bind_param('issisii', $eventId, $videoRef, $title, $requiresSignedInt, $allowedOriginsArg, $nextSort, $userId);
$stmt->execute();
$newVideoId = (int) $stmt->insert_id;
$stmt->close();

Logger::activity(
    'CfStreamUploadMinted',
    'Event #' . $eventId . ' video #' . $newVideoId . ' uid ' . $videoRef,
    $userId
);

echo json_encode([
    'ok'        => true,
    'uploadURL' => $minted['uploadURL'],
    'videoID'   => $newVideoId,
    'uid'       => $videoRef,
    'maxBytes'  => 209715200, // 200MB — basic direct-upload ceiling (tus resumable upload deferred)
    'csrfToken' => Auth::csrfToken(),
]);
