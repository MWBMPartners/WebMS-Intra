<?php
// Path: _apps/calendar/event-hub-video-status.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Team Hub upload status poll ☁️🔄 (#386 Phase 1.5)
 * -----------------------------------------------------------------------------
 * POST /calendar/event/hub/video-status (JSON in/out, page route — NOT
 * api/*). Polled by the hub's upload-progress JS every ~4s while a
 * Cloudflare Stream video is `pending`/`processing`. Admin OR per-event
 * coordinator only, CSRF required, cross-site guarded.
 *
 * Request (form-encoded): csrf_token, eventID, videoID.
 *
 * `external` / `ready` / `error` rows are terminal states — returned
 * straight from the local row with NO Cloudflare call. `pending` /
 * `processing` rows are throttled to at most one Cloudflare GET per ~5
 * seconds (protects the API quota against a coordinator polling from
 * several tabs) and reconcile `requiresSignedUrl` / `allowedOrigins` from
 * Cloudflare's response on every live check, so those mirrors self-heal
 * even if a `videoSettings` save is interrupted mid-request.
 *
 * Response: {ok:true, status, errorDetail, csrfToken} or
 * {ok:false, error, csrfToken?}. See event-hub-upload-url.php's docblock
 * for why `csrfToken` (the rotated session token) travels in every
 * response — this endpoint is polled repeatedly without a page reload.
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
$videoId = (int) ($_POST['videoID'] ?? 0);
$siteId  = Site::id();

if ($eventId <= 0 || $videoId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
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

$stmt = $mysqli->prepare(
    'SELECT videoID, provider, videoRef, uploadStatus, errorDetail, lastCheckedAt '
    . 'FROM tblEventHubVideos WHERE videoID = ? AND eventID = ?'
);
$stmt->bind_param('ii', $videoId, $eventId);
$stmt->execute();
$video = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($video === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'video', 'csrfToken' => Auth::csrfToken()]);
    exit();
}

$status      = (string) $video['uploadStatus'];
$errorDetail = $video['errorDetail'] !== null ? (string) $video['errorDetail'] : null;

// 🚦 Terminal states (and non-Cloudflare rows, which never have a
//    lifecycle) return straight from the local row — no Cloudflare call.
if ((string) $video['provider'] !== 'cloudflare' || in_array($status, ['external', 'ready', 'error'], true) === true) {
    echo json_encode(['ok' => true, 'status' => $status, 'errorDetail' => $errorDetail, 'csrfToken' => Auth::csrfToken()]);
    exit();
}

// 🚦 Throttle — at most one Cloudflare call per ~5s per video.
$lastCheckedTs = $video['lastCheckedAt'] !== null ? strtotime((string) $video['lastCheckedAt']) : false;
if ($lastCheckedTs !== false && $lastCheckedTs > 0 && (time() - $lastCheckedTs) < 5) {
    echo json_encode(['ok' => true, 'status' => $status, 'errorDetail' => $errorDetail, 'csrfToken' => Auth::csrfToken()]);
    exit();
}

$uid    = (string) $video['videoRef'];
$detail = CloudflareStream::getVideo($uid);

if ($detail === null) {
    // ⚠️ Transient Cloudflare/transport failure — stamp lastCheckedAt so we
    //    don't hammer the API on the next poll, but leave the STORED status
    //    untouched (a hiccup must never flip a video to 'error').
    $stmt = $mysqli->prepare('UPDATE tblEventHubVideos SET lastCheckedAt = NOW() WHERE videoID = ? AND eventID = ?');
    $stmt->bind_param('ii', $videoId, $eventId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true, 'status' => $status, 'errorDetail' => $errorDetail, 'csrfToken' => Auth::csrfToken()]);
    exit();
}

$readyToStream = (bool) ($detail['readyToStream'] ?? false);
$state         = (string) ($detail['status']['state'] ?? '');

if ($readyToStream === true) {
    $newStatus = 'ready';
} elseif ($state === 'error') {
    $newStatus = 'error';
} else {
    $newStatus = 'processing';
}

$newErrorDetail = null;
if ($newStatus === 'error') {
    $newErrorDetail = mb_substr(
        (string) ($detail['status']['errorReasonText'] ?? 'Cloudflare reported an error.'),
        0,
        255
    );
}

// 🪞 Reconcile the local mirrors from Cloudflare's response — self-heals
//    even if a videoSettings save was interrupted mid-request (§ see
//    event-hub-video-settings.php).
$requiresSignedMirror = ((bool) ($detail['requireSignedURLs'] ?? false)) === true ? 1 : 0;
$allowedOriginsMirror = null;
if (is_array($detail['allowedOrigins'] ?? null) === true && count($detail['allowedOrigins']) > 0) {
    $allowedOriginsMirror = implode(',', array_map('strval', $detail['allowedOrigins']));
}

// (This branch only runs for rows that were pending/processing above, so
// the prior status was never already 'ready' — no need to compare.)
if ($newStatus === 'ready') {
    $stmt = $mysqli->prepare(
        'UPDATE tblEventHubVideos '
        . 'SET uploadStatus = ?, errorDetail = ?, requiresSignedUrl = ?, allowedOrigins = ?, uploadedAt = NOW(), lastCheckedAt = NOW() '
        . 'WHERE videoID = ? AND eventID = ?'
    );
} else {
    $stmt = $mysqli->prepare(
        'UPDATE tblEventHubVideos '
        . 'SET uploadStatus = ?, errorDetail = ?, requiresSignedUrl = ?, allowedOrigins = ?, lastCheckedAt = NOW() '
        . 'WHERE videoID = ? AND eventID = ?'
    );
}
$stmt->bind_param('ssisii', $newStatus, $newErrorDetail, $requiresSignedMirror, $allowedOriginsMirror, $videoId, $eventId);
$stmt->execute();
$stmt->close();

if ($newStatus === 'error') {
    Logger::activity('CfStreamUploadFailed', 'Event #' . $eventId . ' video #' . $videoId . ' uid ' . $uid);
} elseif ($newStatus === 'ready') {
    Logger::activity('CfStreamUploadReady', 'Event #' . $eventId . ' video #' . $videoId . ' uid ' . $uid);
}

echo json_encode(['ok' => true, 'status' => $newStatus, 'errorDetail' => $newErrorDetail, 'csrfToken' => Auth::csrfToken()]);
