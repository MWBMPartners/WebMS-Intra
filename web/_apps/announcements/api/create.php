<?php
// Path: _apps/announcements/api/create.php
/**
 * Announcements API — Create
 *
 *   POST /api/announcements/create
 *   Content-Type: application/json
 *   {
 *     "title":        "Sabbath service cancelled this week",  (required)
 *     "body":         "Boiler failure — see updates …",        (required)
 *     "priority":     "important",                              (optional: normal|important|urgent)
 *     "isPinned":     true,                                     (optional)
 *     "publishAt":    "2026-06-07T08:00:00",                   (optional ISO 8601; NULL = immediate)
 *     "expiresAt":    "2026-06-14T00:00:00",                   (optional; NULL = no expiry)
 *     "isPublished":  true                                       (optional, default true)
 *   }
 *
 * Publish-approval wiring (#443 SEC-02 fix): when `workflows.announcements.
 * enabled` is on for this site AND the request would create a PUBLISHED
 * announcement (isPublished true — the default), `isPublished` is withheld
 * (inserted as 0) and the same `announcement_publish` workflow save.php uses
 * is started instead, via the shared `_workflow-gate.php` helper — this
 * handler can no longer publish straight past the approval gate the way it
 * used to. Flag off ⇒ byte-identical to before.
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

$title = trim((string) ($body['title'] ?? ''));
$text  = trim((string) ($body['body']  ?? ''));
if ($title === '' || $text === '') {
    ApiResponse::error('title and body are required', 400);
}
$priority = (string) ($body['priority'] ?? 'normal');
if (in_array($priority, ['normal', 'important', 'urgent'], true) === false) {
    $priority = 'normal';
}
$isPinned    = isset($body['isPinned'])    === true && (bool) $body['isPinned']    === true ? 1 : 0;
$isPublished = isset($body['isPublished']) === false || (bool) $body['isPublished'] === true ? 1 : 0;

$publishAt = null;
if (isset($body['publishAt']) === true && trim((string) $body['publishAt']) !== '') {
    $ts = strtotime((string) $body['publishAt']);
    if ($ts === false) {
        ApiResponse::error('publishAt is not a valid timestamp', 400);
    }
    $publishAt = date('Y-m-d H:i:s', $ts);
}
$expiresAt = null;
if (isset($body['expiresAt']) === true && trim((string) $body['expiresAt']) !== '') {
    $ts = strtotime((string) $body['expiresAt']);
    if ($ts === false) {
        ApiResponse::error('expiresAt is not a valid timestamp', 400);
    }
    $expiresAt = date('Y-m-d H:i:s', $ts);
}

$slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title), '-'));
$slug = substr($slug !== '' ? $slug : 'announcement-' . bin2hex(random_bytes(4)), 0, 200);

$siteId    = Site::id();
$creatorId = ApiAuth::actorUserId();

// 🚦 Workflow gate (#443 SEC-02 fix) — mirrors announcements/save.php's
// create-flow gate exactly (shared logic lives in _workflow-gate.php):
// flag on AND this request would create a PUBLISHED row ⇒ withhold
// isPublished (insert as 0) and start the approval workflow instead. Flag
// off ⇒ this block never fires; $isPublished flows through unchanged.
// App::settingForSite() (not Settings::get()'s ambient snapshot) because a
// bearer-key request's site can differ from the host-detected site the
// bootstrap snapshot was frozen for.
$workflowGate = (App::settingForSite('workflows.announcements.enabled', $siteId) === 'true');
$isPublishRequest = ($workflowGate === true && $isPublished === 1);
if ($isPublishRequest === true) {
    $isPublished = 0; // withheld until approved
}

$db = App::db();
$stmt = $db->prepare(
    'INSERT INTO tblAnnouncements '
    . '(siteID, title, slug, body, priority, isPinned, publishAt, expiresAt, isPublished, createdByID) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ANNOUNCEMENT_CREATE_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
// 🛠️ expiresAt is a 'Y-m-d H:i:s' string (or NULL) → bind as 's', not 'i'
// (position 8). Binding a datetime string as 'i' coerces it to an int and
// corrupts the stored expiry; save.php's equivalent INSERT binds it 's'.
$stmt->bind_param(
    'issssissii',
    $siteId, $title, $slug, $text, $priority, $isPinned,
    $publishAt, $expiresAt, $isPublished, $creatorId
);
$ok    = $stmt->execute();
$newId = (int) $stmt->insert_id;
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_ANNOUNCEMENT_CREATE_FAIL', $db->error, '');
    ApiResponse::error('Failed to create announcement', 500);
}

Logger::activity('ApiAnnouncementCreate', 'API: created announcement #' . $newId . ' "' . $title . '"');

// 🚦 Publish was withheld above — run the gate now the row exists (needs a
// real announcementID as the workflow's subject record).
if ($isPublishRequest === true) {
    $gate = announcements_workflow_gate_publish($db, $siteId, $newId, $creatorId, $title, $slug, 'created');
    ApiResponse::success([
        'announcementID'  => $newId,
        'slug'            => $slug,
        'isPublished'     => ($gate['status'] === 'published_direct'),
        'approvalStatus'  => $gate['status'],
        'message'         => $gate['message'],
    ], 201);
}

ApiResponse::success([
    'announcementID' => $newId,
    'slug'           => $slug,
], 201);
