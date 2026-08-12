<?php
// Path: _apps/calendar/event-hub-save.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Team Hub POST handler ✍️ (#386 Phase 1)
 * -----------------------------------------------------------------------------
 * action=addResource    : INSERT a resource (link or note)
 * action=editResource   : UPDATE a resource
 * action=removeResource : DELETE a resource
 * action=addVideo       : VideoEmbed::parse() the pasted input, INSERT the
 *                          resulting provider+ref as uploadStatus='external'
 *                          (Phase 1 is external references only)
 * action=removeVideo    : DELETE a video row. For a portal-uploaded
 *                          Cloudflare video (provider='cloudflare' AND
 *                          uploadStatus != 'external') with Cloudflare
 *                          configured, best-effort deletes it from
 *                          Cloudflare Stream FIRST via
 *                          CloudflareStream::deleteVideo() (#386 Phase
 *                          1.5) — a CF failure is logged but never blocks
 *                          the local delete. Pasted external references
 *                          (uploadStatus='external') are NEVER deleted
 *                          from Cloudflare — the portal didn't create them.
 * action=reorder         : swap sortOrder with the adjacent resource/video
 *                          (Phase 1 has no drag-and-drop JS — up/down
 *                          buttons, mirroring the forms-only v1 pattern
 *                          used by event-crews.php)
 *
 * Admin OR per-event coordinator (Auth::isCoordinatorOf — the existing
 * "manage" idiom; broader team-member view access does NOT grant write
 * rights here).
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
use Portal\Core\VideoEmbed;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /calendar', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$eventId = (int) ($_POST['eventID'] ?? 0);
$action  = (string) ($_POST['action'] ?? '');
$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ Cross-site guard — mirrors event-crews-save.php.
$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) {
    http_response_code(404);
    exit('Event not found');
}

$redirect = '/calendar/event/hub?eventID=' . $eventId;

/**
 * 🚩 Flash an error and redirect back to the hub, without mutating anything.
 */
$flashAndRedirect = static function (string $message) use ($redirect): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect, true, 302);
    exit();
};

if ($action === 'addResource' || $action === 'editResource') {
    $resourceId   = $action === 'editResource' ? (int) ($_POST['resourceID'] ?? 0) : 0;
    $section      = mb_substr(trim((string) ($_POST['section'] ?? '')), 0, 80);
    $resourceType = ((string) ($_POST['resourceType'] ?? 'link')) === 'note' ? 'note' : 'link';
    $title        = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255);
    $url          = mb_substr(trim((string) ($_POST['url'] ?? '')), 0, 2048);
    $body         = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 10000);

    if ($section === '') {
        $section = 'General';
    }
    if ($title === '') {
        $flashAndRedirect('A resource needs a title.');
    }
    if ($resourceType === 'link') {
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            $flashAndRedirect('A link resource needs a valid http(s) URL.');
        }
        $body = '';
    } else {
        if ($body === '') {
            $flashAndRedirect('A note resource needs some text.');
        }
        $url = '';
    }
    $urlArg  = $url !== '' ? $url : null;
    $bodyArg = $body !== '' ? $body : null;

    if ($action === 'addResource') {
        $nextSort = 1;
        $stmt = $mysqli->prepare(
            'SELECT COALESCE(MAX(sortOrder), 0) + 1 AS nextSort '
            . 'FROM tblEventHubResources WHERE eventID = ? AND section = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('is', $eventId, $section);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $nextSort = (int) ($row['nextSort'] ?? 1);
            $stmt->close();
        }

        $stmt = $mysqli->prepare(
            'INSERT INTO tblEventHubResources '
            . '(eventID, section, resourceType, title, url, body, sortOrder, createdByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssssii', $eventId, $section, $resourceType, $title, $urlArg, $bodyArg, $nextSort, $userId);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        Logger::activity('EventHubResourceAdded', 'Event #' . $eventId . ' resource "' . $title . '" (#' . $newId . ')');
    } else {
        if ($resourceId <= 0) {
            $flashAndRedirect('Invalid resource.');
        }
        // 🛡️ Confirm the resource belongs to this event before mutating it.
        $stmt = $mysqli->prepare('SELECT resourceID FROM tblEventHubResources WHERE resourceID = ? AND eventID = ?');
        $stmt->bind_param('ii', $resourceId, $eventId);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($found === false) {
            http_response_code(404);
            exit('Resource not found');
        }

        $stmt = $mysqli->prepare(
            'UPDATE tblEventHubResources SET section = ?, resourceType = ?, title = ?, url = ?, body = ? '
            . 'WHERE resourceID = ? AND eventID = ?'
        );
        $stmt->bind_param('sssssii', $section, $resourceType, $title, $urlArg, $bodyArg, $resourceId, $eventId);
        $stmt->execute();
        $stmt->close();
        Logger::activity('EventHubResourceEdited', 'Event #' . $eventId . ' resource #' . $resourceId . ' updated');
    }
} elseif ($action === 'removeResource') {
    $resourceId = (int) ($_POST['resourceID'] ?? 0);
    if ($resourceId > 0) {
        $stmt = $mysqli->prepare('DELETE FROM tblEventHubResources WHERE resourceID = ? AND eventID = ?');
        $stmt->bind_param('ii', $resourceId, $eventId);
        $stmt->execute();
        $stmt->close();
        Logger::activity('EventHubResourceRemoved', 'Event #' . $eventId . ' resource #' . $resourceId);
    }
} elseif ($action === 'addVideo') {
    $sourceInput      = mb_substr(trim((string) ($_POST['sourceInput'] ?? '')), 0, 1024);
    $title            = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255);
    $requiresSignedIn = isset($_POST['requiresSignedUrl']) === true;

    if ($title === '') {
        $flashAndRedirect('A video needs a title.');
    }

    // 🛡️ Allowlist parse — unparseable input is flashed and NOTHING is
    //    stored. No raw pasted URL is ever placed directly into an iframe.
    $parsed = VideoEmbed::parse($sourceInput);
    if ($parsed === null) {
        $flashAndRedirect('Could not recognise that as a YouTube, Vimeo, or Cloudflare Stream link or ID.');
    }

    $provider = $parsed['provider'];
    $ref      = $parsed['ref'];
    // "Requires signed URL" only means anything for Cloudflare videos.
    $requiresSigned = ($requiresSignedIn === true && $provider === 'cloudflare') ? 1 : 0;

    $nextSort = 1;
    $stmt = $mysqli->prepare('SELECT COALESCE(MAX(sortOrder), 0) + 1 AS nextSort FROM tblEventHubVideos WHERE eventID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $nextSort = (int) ($row['nextSort'] ?? 1);
        $stmt->close();
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventHubVideos '
        . '(eventID, provider, videoRef, sourceUrl, title, requiresSignedUrl, uploadStatus, sortOrder, createdByID) '
        . "VALUES (?, ?, ?, ?, ?, ?, 'external', ?, ?)"
    );
    $stmt->bind_param('issssiii', $eventId, $provider, $ref, $sourceInput, $title, $requiresSigned, $nextSort, $userId);
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();
    Logger::activity('EventHubVideoAdded', 'Event #' . $eventId . ' video "' . $title . '" (' . $provider . ', #' . $newId . ')');
} elseif ($action === 'removeVideo') {
    $videoId = (int) ($_POST['videoID'] ?? 0);
    if ($videoId > 0) {
        // ☁️ Best-effort Cloudflare delete for PORTAL-UPLOADED videos ONLY
        //    (provider='cloudflare' AND uploadStatus != 'external') — a
        //    pasted external reference was never created by the portal, so
        //    it is NEVER deleted from Cloudflare here (#386 Phase 1.5).
        $stmt = $mysqli->prepare('SELECT provider, videoRef, uploadStatus FROM tblEventHubVideos WHERE videoID = ? AND eventID = ?');
        $stmt->bind_param('ii', $videoId, $eventId);
        $stmt->execute();
        $existingVideo = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (
            $existingVideo !== null
            && (string) $existingVideo['provider'] === 'cloudflare'
            && (string) $existingVideo['uploadStatus'] !== 'external'
            && CloudflareStream::isConfigured() === true
        ) {
            $cfDeleted = CloudflareStream::deleteVideo((string) $existingVideo['videoRef']);
            if ($cfDeleted === false) {
                // ⚠️ Best-effort — fall through to the local delete anyway
                //    (a coordinator who asked to remove a video must not
                //    get stuck with it still showing because Cloudflare
                //    hiccuped); the resulting CF-side orphan is logged for
                //    manual/administrative cleanup, never silently lost.
                Logger::errorPlatform(
                    'CloudflareStream',
                    'Warning',
                    'CFSTREAM_DELETE_FAIL',
                    'Cloudflare Stream video delete failed — local row removed anyway',
                    'eventID=' . $eventId . ' videoID=' . $videoId . ' uid=' . (string) $existingVideo['videoRef']
                );
            }
        }

        $stmt = $mysqli->prepare('DELETE FROM tblEventHubVideos WHERE videoID = ? AND eventID = ?');
        $stmt->bind_param('ii', $videoId, $eventId);
        $stmt->execute();
        $stmt->close();
        Logger::activity('EventHubVideoRemoved', 'Event #' . $eventId . ' video #' . $videoId);
    }
} elseif ($action === 'reorder') {
    $type      = (string) ($_POST['type'] ?? '');
    $id        = (int) ($_POST['id'] ?? 0);
    $direction = (string) ($_POST['direction'] ?? '');

    if ($id > 0 && in_array($direction, ['up', 'down'], true) === true) {
        $cmp   = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';

        if ($type === 'resource') {
            $stmt = $mysqli->prepare('SELECT section, sortOrder FROM tblEventHubResources WHERE resourceID = ? AND eventID = ?');
            $stmt->bind_param('ii', $id, $eventId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if ($current !== null) {
                $section = (string) $current['section'];
                $curSort = (int) $current['sortOrder'];
                $stmt = $mysqli->prepare(
                    "SELECT resourceID, sortOrder FROM tblEventHubResources "
                    . "WHERE eventID = ? AND section = ? AND sortOrder {$cmp} ? "
                    . "ORDER BY sortOrder {$order} LIMIT 1"
                );
                $stmt->bind_param('isi', $eventId, $section, $curSort);
                $stmt->execute();
                $neighbor = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();

                if ($neighbor !== null) {
                    $neighborId   = (int) $neighbor['resourceID'];
                    $neighborSort = (int) $neighbor['sortOrder'];
                    $upd = $mysqli->prepare('UPDATE tblEventHubResources SET sortOrder = ? WHERE resourceID = ? AND eventID = ?');
                    $upd->bind_param('iii', $neighborSort, $id, $eventId);
                    $upd->execute();
                    $upd->bind_param('iii', $curSort, $neighborId, $eventId);
                    $upd->execute();
                    $upd->close();
                }
            }
        } elseif ($type === 'video') {
            $stmt = $mysqli->prepare('SELECT sortOrder FROM tblEventHubVideos WHERE videoID = ? AND eventID = ?');
            $stmt->bind_param('ii', $id, $eventId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if ($current !== null) {
                $curSort = (int) $current['sortOrder'];
                $stmt = $mysqli->prepare(
                    "SELECT videoID, sortOrder FROM tblEventHubVideos "
                    . "WHERE eventID = ? AND sortOrder {$cmp} ? "
                    . "ORDER BY sortOrder {$order} LIMIT 1"
                );
                $stmt->bind_param('ii', $eventId, $curSort);
                $stmt->execute();
                $neighbor = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();

                if ($neighbor !== null) {
                    $neighborId   = (int) $neighbor['videoID'];
                    $neighborSort = (int) $neighbor['sortOrder'];
                    $upd = $mysqli->prepare('UPDATE tblEventHubVideos SET sortOrder = ? WHERE videoID = ? AND eventID = ?');
                    $upd->bind_param('iii', $neighborSort, $id, $eventId);
                    $upd->execute();
                    $upd->bind_param('iii', $curSort, $neighborId, $eventId);
                    $upd->execute();
                    $upd->close();
                }
            }
        }
        Logger::activity('EventHubReordered', 'Event #' . $eventId . ' ' . $type . ' #' . $id . ' moved ' . $direction);
    }
}

header('Location: ' . $redirect, true, 302);
exit();
