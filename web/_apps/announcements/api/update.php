<?php
// Path: _apps/announcements/api/update.php
/**
 * Announcements API — Update (partial)
 *
 *   POST /api/announcements/update
 *   Content-Type: application/json
 *   {
 *     "announcementID": 42,                     (required)
 *     "title":          "…",                     (optional)
 *     "body":           "…",                     (optional)
 *     "priority":       "important",             (optional)
 *     "isPinned":       false,                   (optional)
 *     "publishAt":      "2026-06-07T08:00:00", (optional)
 *     "expiresAt":      null,                    (optional)
 *     "isPublished":    true                     (optional)
 *   }
 *
 * Publish-approval wiring (#443 SEC-02 fix): when `workflows.announcements.
 * enabled` is on for this site AND this request is a genuine publish
 * request (posted isPublished=true AND the stored row is currently
 * isPublished=0), the 0→1 flip is withheld and the same `announcement_
 * publish` workflow save.php uses is started instead, via the shared
 * `_workflow-gate.php` helper. An update that doesn't touch isPublished, or
 * one where the row is already published (or being unpublished), passes
 * through completely unchanged — no approval needed to edit or retract, and
 * flag off ⇒ byte-identical to before.
 *
 * @package   Portal\API\Announcements
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/157
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/443
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Site;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_workflow-gate.php';

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('announcements:write');

$id = (int) ($_GET['id'] ?? $body['announcementID'] ?? 0);
if ($id <= 0) {
    ApiResponse::error('announcementID is required', 400);
}

$siteId    = Site::id();
$updaterId = ApiAuth::actorUserId();

$db = App::db();
$stmt = $db->prepare('SELECT * FROM tblAnnouncements WHERE announcementID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1');
if ($stmt === false) {
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param('ii', $id, $siteId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($existing === null) {
    ApiResponse::error('Announcement not found', 404);
}

// 🚦 Workflow gate (#443 SEC-02 fix) — mirrors announcements/save.php's
// update-flow gate exactly (shared logic lives in _workflow-gate.php):
// flag on AND this is a genuine 0→1 publish flip ⇒ withhold it below.
// App::settingForSite() (not Settings::get()'s ambient snapshot) because a
// bearer-key request's site can differ from the host-detected site the
// bootstrap snapshot was frozen for.
$workflowGate    = (App::settingForSite('workflows.announcements.enabled', $siteId) === 'true');
$storedPublished = (int) $existing['isPublished'];
$isPublishRequest = false;

// Build the SET clause from supplied fields only — partial update.
$updates = [];
$types   = '';
$params  = [];

if (array_key_exists('title', $body) === true) {
    $title = trim((string) $body['title']);
    if ($title === '') {
        ApiResponse::error('title cannot be empty', 400);
    }
    $updates[] = 'title = ?';
    $types    .= 's';
    $params[]  = $title;
}
if (array_key_exists('body', $body) === true) {
    $text = trim((string) $body['body']);
    if ($text === '') {
        ApiResponse::error('body cannot be empty', 400);
    }
    $updates[] = 'body = ?';
    $types    .= 's';
    $params[]  = $text;
}
if (array_key_exists('priority', $body) === true) {
    $priority = (string) $body['priority'];
    if (in_array($priority, ['normal', 'important', 'urgent'], true) === false) {
        ApiResponse::error('priority must be one of normal|important|urgent', 400);
    }
    $updates[] = 'priority = ?';
    $types    .= 's';
    $params[]  = $priority;
}
if (array_key_exists('isPinned', $body) === true) {
    $updates[] = 'isPinned = ?';
    $types    .= 'i';
    $params[]  = (bool) $body['isPinned'] === true ? 1 : 0;
}
if (array_key_exists('isPublished', $body) === true) {
    $requestedPublished = (bool) $body['isPublished'] === true ? 1 : 0;
    // A "publish request" is: gate on, caller wants Published=1, AND the
    // stored row is currently unpublished (0→1). Already-published rows
    // being edited, and an unpublish (1→0), pass through unchanged — no
    // approval needed to edit or retract in v1 (mirrors save.php exactly).
    $isPublishRequest = ($workflowGate === true && $requestedPublished === 1 && $storedPublished === 0);
    if ($isPublishRequest === true) {
        // 🚦 Withhold the flip — Workflow::start() (or the fail-open
        // fallback) flips isPublished inside its own transaction once
        // approved. Deliberately NOT added to $updates at all: the stored
        // value is already 0, so there is nothing to SET.
    } else {
        $updates[] = 'isPublished = ?';
        $types    .= 'i';
        $params[]  = $requestedPublished;
    }
}
if (array_key_exists('publishAt', $body) === true) {
    if ($body['publishAt'] === null || trim((string) $body['publishAt']) === '') {
        $updates[] = 'publishAt = NULL';
    } else {
        $ts = strtotime((string) $body['publishAt']);
        if ($ts === false) {
            ApiResponse::error('publishAt is not a valid timestamp', 400);
        }
        $updates[] = 'publishAt = ?';
        $types    .= 's';
        $params[]  = date('Y-m-d H:i:s', $ts);
    }
}
if (array_key_exists('expiresAt', $body) === true) {
    if ($body['expiresAt'] === null || trim((string) $body['expiresAt']) === '') {
        $updates[] = 'expiresAt = NULL';
    } else {
        $ts = strtotime((string) $body['expiresAt']);
        if ($ts === false) {
            ApiResponse::error('expiresAt is not a valid timestamp', 400);
        }
        $updates[] = 'expiresAt = ?';
        $types    .= 's';
        $params[]  = date('Y-m-d H:i:s', $ts);
    }
}

// 🚦 A publish-request-only body (just {"isPublished": true}) legitimately
// withholds its one-and-only field above, so $updates being empty at this
// point is NOT "nothing to do" when $isPublishRequest is true — the
// approval start below is the real effect of this request.
if ($updates === [] && $isPublishRequest === false) {
    ApiResponse::error('No updatable fields supplied', 400);
}

$updates[] = 'updatedByID = ?';
$types    .= 'i';
$params[]  = $updaterId;

$sql = 'UPDATE tblAnnouncements SET ' . implode(', ', $updates) . ' WHERE announcementID = ? AND siteID = ?';
$types  .= 'ii';
$params[] = $id;
$params[] = $siteId;

$stmt = $db->prepare($sql);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ANNOUNCEMENT_UPDATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$stmt->bind_param($types, ...$params);
$ok = $stmt->execute();
$stmt->close();
if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ANNOUNCEMENT_UPDATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to update announcement', 500);
}

Logger::activity('ApiAnnouncementUpdate', 'API: updated announcement #' . $id);

// 🚦 Publish flip was withheld above — run the gate now (row + all other
// field changes are already committed).
if ($isPublishRequest === true) {
    $label = array_key_exists('title', $body) === true ? (string) $title : (string) ($existing['title'] ?? '');
    $slug  = (string) ($existing['slug'] ?? '');
    $gate  = announcements_workflow_gate_publish($db, $siteId, $id, $updaterId, $label, $slug, 'updated');
    ApiResponse::success([
        'announcementID'  => $id,
        'updated'         => true,
        'isPublished'     => ($gate['status'] === 'published_direct'),
        'approvalStatus'  => $gate['status'],
        'message'         => $gate['message'],
    ]);
}

ApiResponse::success(['announcementID' => $id, 'updated' => true]);
