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

use Portal\Core\ApiAuth;
use Portal\Core\App;
use Portal\Core\ApiResponse;
use Portal\Core\Site;

ApiAuth::requireMethod('POST');
// api.noticeboard.save.enabled is already gated per-site by
// ApiRouter::resolveEnabledFlag before this handler runs (#323 Phase 2); a
// second handler-level check via App::settings() would read the frozen
// host-site snapshot and could wrongly 403 a valid bearer request.
$body = ApiAuth::requireWrite('noticeboard:write', sessionNeedsAdmin: false);

// 🛡️ Site-admin gate — kept verbatim. This is a distinct, finer-grained check
// than ApiAuth's sessionNeedsAdmin (isSiteAdmin(), not isAdmin()), so it stays
// here rather than folding into the ApiAuth call. Because App::isSiteAdmin()
// reads App::user() (session-only), it also fails closed for bearer keys —
// see #323 Phase 2 B3 report for the follow-up needed to let a scoped bearer
// key through (e.g. a site-pinned key implicitly counted as site-admin here).
if (ApiAuth::source() === 'session' && App::isSiteAdmin() === false) {
    ApiResponse::error('Admin access required', 403);
}

if (isset($body['posters']) === false || is_array($body['posters']) === false) {
    ApiResponse::error('Expected { posters: [...] }', 422);
}

$db     = App::db();
$siteId = Site::id();
$userId = ApiAuth::actorUserId() ?? 0;

// 🛡️ Scheme allowlist — http(s) absolute or root-relative only. Matches the
//    LivePrompt ctaUrl precedent (PR #358) — prevents javascript:… hrefs
//    (board opens `link` on second tap) and off-scheme media URLs.
$safeUrl = static function (string $u): string {
    $u = trim($u);
    if ($u === '') {
        return '';
    }
    return preg_match('#^(https?://|/)#i', $u) === 1 ? $u : '';
};

// 🛡️ Pre-transaction validation pass — abort BEFORE opening the transaction
//    so a single offending poster doesn't leave half a write half-committed.
foreach ($body['posters'] as $p) {
    $mediaUrl = (string) ($p['image'] ?? '');
    if (str_starts_with($mediaUrl, 'data:') === true) {
        ApiResponse::error(
            'Upload files via the media library; data: URIs are not accepted here',
            422
        );
    }
}

App::beginTransaction();
try {
    // 🛡️ Cross-site write guard — preload posterIDs that ALREADY belong to
    //    this site. A crafted `posterID` from another tenant's board would
    //    otherwise overwrite that row's content silently (siteID is NOT in
    //    the UPDATE list, so ownership doesn't even transfer — it's a pure
    //    cross-tenant deface). Any foreign claim is nulled → INSERT as new.
    $validIds = [];
    $vs = $db->prepare('SELECT posterID FROM tblNoticeboardPosters WHERE siteID = ?');
    if ($vs !== false) {
        $vs->bind_param('i', $siteId);
        $vs->execute();
        $vr = $vs->get_result();
        while ($v = $vr->fetch_assoc()) {
            $validIds[(int) $v['posterID']] = true;
        }
        $vs->close();
    }

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
        $cid  = (string) ($p['id'] ?? '');
        $dbId = (preg_match('/^p(\d{1,9})$/', $cid, $m) === 1) ? (int) $m[1] : null;
        // 🛡️ Cross-site guard: foreign posterID → insert as fresh row.
        if ($dbId !== null && isset($validIds[$dbId]) === false) {
            $dbId = null;
        }

        $title = trim((string) ($p['title'] ?? ''));
        if ($title === '') {
            continue;
        }

        $kicker   = (string) ($p['kicker'] ?? '');
        $category = (string) ($p['category'] ?? 'Other');
        $schedule = ($p['schedule'] ?? 'once') === 'weekly' ? 'weekly' : 'once';
        $date     = ($schedule === 'once' && !empty($p['date'])) ? (string) $p['date'] : null;
        $weekday  = ($schedule === 'weekly' && isset($p['weekday'])) ? (int) $p['weekday'] : null;
        $time     = !empty($p['time']) ? ((string) $p['time']) . ':00' : null;
        $location = (string) ($p['location'] ?? '');
        $link     = $safeUrl((string) ($p['link'] ?? ''));

        // 🛡️ Sanitise media URLs BEFORE the $mt derivation — mediaType
        //    depends on $canva / $mediaUrl emptiness.
        $mediaUrl = $safeUrl((string) ($p['image'] ?? ''));
        $canva    = trim((string) ($p['canva'] ?? ''));
        if ($canva !== '' && preg_match('#^https://www\.canva\.com/#i', $canva) !== 1) {
            $canva = '';
        }
        $thumb = $safeUrl((string) ($p['thumb'] ?? ''));

        $mt = (string) ($p['mediaType'] ?? 'image');
        if ($canva !== '') {
            $mt = 'canva';
        } elseif ($mediaUrl === '') {
            $mt = 'text';
        } elseif ($mt !== 'video') {
            $mt = 'image';
        }

        $colorIndex = (int) ($p['colorIndex'] ?? 0);
        $aspect     = (string) ($p['aspect'] ?? '4/5');
        $useSerif   = !empty($p['serif']) ? 1 : 0;
        $order++;

        // 🛡️ 21 placeholders → 21-char type string. NULLs bind fine under 'i'/'s'.
        //    (Previous 20-char literal 'iissssssssssssssisii' fataled every save.)
        $up->bind_param(
            'iisssssisssssssisiiii',
            $dbId, $siteId, $title, $kicker, $category, $schedule, $date, $weekday, $time,
            $location, $link, $mt, $mediaUrl, $canva, $thumb, $colorIndex, $aspect, $useSerif,
            $order, $userId, $userId
        );
        $up->execute();
        $keepIds[] = $dbId !== null ? $dbId : (int) $db->insert_id;
    }
    $up->close();

    // 🗑️ Soft-delete rows no longer present in the payload.
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
