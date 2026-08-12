<?php
// Path: _apps/calendar/event-hub-video-settings.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Team Hub Stream settings save ☁️⚙️ (#386 Phase 1.5)
 * -----------------------------------------------------------------------------
 * POST /calendar/event/hub/video-settings — a normal form POST (redirect +
 * flash, NOT AJAX/JSON) so a Cloudflare rejection gets clean, unmissable
 * UX, matching the house idiom used by every other Team Hub form. Admin OR
 * per-event coordinator only, CSRF required, cross-site guarded. Only
 * applies to `provider = 'cloudflare'` rows.
 *
 * Request (form-encoded): csrf_token, eventID, videoID, requiresSignedUrl
 * (checkbox), allowedOrigins (comma-separated hostnames, blank = any).
 *
 * Cloudflare is called FIRST — the local row is updated ONLY on a
 * confirmed Cloudflare success. On a Cloudflare failure NOTHING local
 * changes and the error is flashed; Cloudflare stays the source of truth,
 * and the mirrors self-heal on the next status poll regardless
 * (event-hub-video-status.php reconciles them from every live GET).
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /calendar', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

$eventId  = (int) ($_POST['eventID'] ?? 0);
$redirect = '/calendar/event/hub?eventID=' . $eventId;

if (Auth::verifyCsrf((string) ($_POST['csrf_token'] ?? '')) === false) {
    http_response_code(400);
    exit('Bad request');
}

$siteId = Site::id();

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ Cross-site guard — mirrors event-hub-save.php.
$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$eventOk = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($eventOk === false) {
    http_response_code(404);
    exit('Event not found');
}

/**
 * 🚩 Flash a message and redirect back to the hub, without mutating
 *    anything (mirrors event-hub-save.php's $flashAndRedirect).
 */
$flashAndRedirect = static function (string $message, string $type = 'danger') use ($redirect): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $redirect, true, 302);
    exit();
};

$videoId = (int) ($_POST['videoID'] ?? 0);
if ($videoId <= 0) {
    $flashAndRedirect('Invalid video.');
}

// 🛡️ Confirm the video belongs to this event AND is a Cloudflare row
//    before doing anything else.
$stmt = $mysqli->prepare('SELECT videoID, provider, videoRef FROM tblEventHubVideos WHERE videoID = ? AND eventID = ?');
$stmt->bind_param('ii', $videoId, $eventId);
$stmt->execute();
$video = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($video === null) {
    http_response_code(404);
    exit('Video not found');
}
if ((string) $video['provider'] !== 'cloudflare') {
    $flashAndRedirect('Stream settings only apply to Cloudflare Stream videos.');
}

$requiresSignedUrl = isset($_POST['requiresSignedUrl']) === true;

// 🛡️ Allowed origins — bare hostnames, comma-separated, capped at 10. The
//    WHOLE save is rejected (nothing sent to Cloudflare, nothing changed
//    locally) on a malformed entry.
$allowedOriginsRaw = trim((string) ($_POST['allowedOrigins'] ?? ''));
$originParts = [];
if ($allowedOriginsRaw !== '') {
    $candidates = array_filter(array_map('trim', explode(',', $allowedOriginsRaw)));
    $candidates = array_slice(array_values($candidates), 0, 10);
    foreach ($candidates as $candidate) {
        if (preg_match('/^[a-z0-9.-]+$/i', $candidate) !== 1) {
            $flashAndRedirect('Allowed origins must be bare hostnames (no scheme, no path).');
        }
        $originParts[] = strtolower($candidate);
    }
}
$allowedOriginsClean = implode(',', $originParts);
$allowedOriginsArg   = $allowedOriginsClean !== '' ? $allowedOriginsClean : null;

if (CloudflareStream::isConfigured() === false) {
    $flashAndRedirect('Cloudflare Stream is not configured.');
}

$uid = (string) $video['videoRef'];

// ☁️ CLOUDFLARE FIRST — the local row is touched ONLY on confirmed success.
$cfOk = CloudflareStream::updateVideo($uid, $requiresSignedUrl, $allowedOriginsArg);
if ($cfOk === false) {
    Logger::activity('CfStreamSettingsRejected', 'Event #' . $eventId . ' video #' . $videoId . ' uid ' . $uid);
    $flashAndRedirect('Cloudflare rejected the change — settings unchanged.');
}

$requiresSignedInt = $requiresSignedUrl === true ? 1 : 0;

$stmt = $mysqli->prepare('UPDATE tblEventHubVideos SET requiresSignedUrl = ?, allowedOrigins = ? WHERE videoID = ? AND eventID = ?');
$stmt->bind_param('isii', $requiresSignedInt, $allowedOriginsArg, $videoId, $eventId);
$stmt->execute();
$stmt->close();

Logger::activity('CfStreamSettingsSaved', 'Event #' . $eventId . ' video #' . $videoId . ' uid ' . $uid);

$_SESSION['flash_msg']  = 'Stream settings updated.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . $redirect, true, 302);
exit();
