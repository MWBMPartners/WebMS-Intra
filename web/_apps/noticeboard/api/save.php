<?php
// Path: _apps/noticeboard/api/save.php
/**
 * POST /api/noticeboard/save   { posters: [ … ] }
 * Replaces the current site's poster set with the supplied array. Site admins
 * only. CSRF-protected. The client sends the full array (add/edit/delete are
 * all expressed as the new desired state), so we upsert + soft-delete missing.
 *
 * @package   Portal\Apps\Noticeboard
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\ApiResponse;
use Portal\Core\Site;

Auth::ensureSession();
ApiResponse::requireAuth();
ApiResponse::requireEnabled('api.noticeboard.save.enabled');

if (App::isSiteAdmin() === false) {
    ApiResponse::error('Admin access required', 403);
}

// CSRF — header sent by the bridge.
$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (Auth::verifyCsrf($csrf) === false) {
    ApiResponse::error('Invalid CSRF token', 400);
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (is_array($body) === false || isset($body['posters']) === false || is_array($body['posters']) === false) {
    ApiResponse::error('Expected { posters: [...] }', 422);
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

App::beginTransaction();
try {
    $keepIds = [];

    $up = $db->prepare(
        'INSERT INTO tblNoticeboardPosters '
        . '(posterID, siteID, title, kicker, category, scheduleType, eventDate, weekday, eventTime, '
        . 'location, link, mediaType, mediaUrl, canvaUrl, thumbUrl, colorIndex, aspect, useSerif, '
        . 'sortOrder, createdByID, updatedByID) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE title=VALUES(title), kicker=VALUES(kicker), category=VALUES(category), '
        . 'scheduleType=VALUES(scheduleType), eventDate=VALUES(eventDate), weekday=VALUES(weekday), '
        . 'eventTime=VALUES(eventTime), location=VALUES(location), link=VALUES(link), mediaType=VALUES(mediaType), '
        . 'mediaUrl=VALUES(mediaUrl), canvaUrl=VALUES(canvaUrl), thumbUrl=VALUES(thumbUrl), '
        . 'colorIndex=VALUES(colorIndex), aspect=VALUES(aspect), useSerif=VALUES(useSerif), '
        . 'sortOrder=VALUES(sortOrder), updatedByID=VALUES(updatedByID), isDeleted=0'
    );

    $order = 0;
    foreach ($body['posters'] as $p) {
        // Client ids look like "p123" (existing) or "p<timestamp>" (new). Only a
        // pure numeric DB id maps to an existing row; anything else inserts fresh.
        $cid      = (string) ($p['id'] ?? '');
        $dbId     = (preg_match('/^p(\d{1,9})$/', $cid, $m) === 1) ? (int) $m[1] : null;

        $title    = trim((string) ($p['title'] ?? ''));
        if ($title === '') { continue; }

        $kicker   = (string) ($p['kicker'] ?? '');
        $category = (string) ($p['category'] ?? 'Other');
        $schedule = ($p['schedule'] ?? 'once') === 'weekly' ? 'weekly' : 'once';
        $date     = ($schedule === 'once' && !empty($p['date'])) ? (string) $p['date'] : null;
        $weekday  = ($schedule === 'weekly' && isset($p['weekday'])) ? (int) $p['weekday'] : null;
        $time     = !empty($p['time']) ? ((string) $p['time']) . ':00' : null;
        $location = (string) ($p['location'] ?? '');
        $link     = (string) ($p['link'] ?? '');

        $mt       = (string) ($p['mediaType'] ?? 'image');
        $canva    = (string) ($p['canva'] ?? '');
        $mediaUrl = (string) ($p['image'] ?? '');
        if ($canva !== '')              { $mt = 'canva'; }
        elseif ($mediaUrl === '')       { $mt = 'text'; }
        elseif ($mt !== 'video')        { $mt = 'image'; }
        $thumb    = (string) ($p['thumb'] ?? '');

        $colorIndex = (int) ($p['colorIndex'] ?? 0);
        $aspect     = (string) ($p['aspect'] ?? '4/5');
        $useSerif   = !empty($p['serif']) ? 1 : 0;
        $order++;

        // NOTE: reject base64 data: URIs server-side — push real uploads through
        // your media pipeline and store the resulting URL instead.
        if (str_starts_with($mediaUrl, 'data:') === true) {
            ApiResponse::error('Upload files via the media library; data: URIs are not accepted here', 422);
        }

        $up->bind_param(
            'iissssssssssssssisii',
            $dbId, $siteId, $title, $kicker, $category, $schedule, $date, $weekday, $time,
            $location, $link, $mt, $mediaUrl, $canva, $thumb, $colorIndex, $aspect, $useSerif,
            $order, $userId, $userId
        );
        $up->execute();
        $keepIds[] = $dbId !== null ? $dbId : (int) $db->insert_id;
    }
    $up->close();

    // Soft-delete rows no longer present.
    if (count($keepIds) > 0) {
        $in    = implode(',', array_fill(0, count($keepIds), '?'));
        $types = 'i' . str_repeat('i', count($keepIds));
        $del   = $db->prepare(
            "UPDATE tblNoticeboardPosters SET isDeleted = 1 WHERE siteID = ? AND posterID NOT IN ($in)"
        );
        $params = array_merge([$siteId], $keepIds);
        $del->bind_param($types, ...$params);
        $del->execute();
        $del->close();
    } else {
        $del = $db->prepare('UPDATE tblNoticeboardPosters SET isDeleted = 1 WHERE siteID = ?');
        $del->bind_param('i', $siteId);
        $del->execute();
        $del->close();
    }

    App::commit();
} catch (\Throwable $e) {
    App::rollback();
    ApiResponse::error('Could not save noticeboard', 500, $e->getMessage());
}

ApiResponse::success(['saved' => true]);
