<?php
// Path: _apps/prayer-requests/api/moderate.php
/**
 * Prayer Requests API — Moderation (admin / prayer-team only)
 *
 *   POST /api/prayer-requests/moderate
 *   {
 *     "requestID": 42,
 *     "status":    "active",                (one of: active|answered|archived)
 *     "testimony": "Praise — got the job!"  (optional; appended when status = answered)
 *   }
 *
 * @package   Portal\API\PrayerRequests
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/157
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Capabilities;
use Portal\Core\Logger;
use Portal\Core\Site;

ApiAuth::requireMethod('POST');
// 🛡️ sessionNeedsAdmin: false — moderation also allows the prayer_team role
// (not admin-only), so the finer-grained gate below stays in charge for the
// session path; bearer keys are gated purely on the prayer-requests:write
// scope (no per-role concept for API keys yet).
$body = ApiAuth::requireWrite('prayer-requests:write', sessionNeedsAdmin: false);

// Session mode gates on admin OR whoever this organisation has pointed
// "Moderate prayer requests" at; a bearer key is already authorised by its
// prayer-requests:write scope + site pin, so skip this session-only gate for
// bearer (#323 Phase 2 review — dual-mode).
//
// 🚪 #739: was `App::hasRole('prayer_team')`. It is now the `prayer.moderate`
//    capability, which ships pointing at the `prayer_team` role and falls open
//    for a global administrator, so this answers exactly what it answered
//    before on an installation that has never opened the Roles page. The
//    refusal's wording still names the role because that is the role it ships
//    with, and an organisation that re-points the capability has renamed or
//    replaced that role itself and will recognise its own name for it.
if (ApiAuth::source() === 'session'
    && App::isAdmin() === false && App::can(Capabilities::PRAYER_MODERATE) === false
) {
    ApiResponse::error('Forbidden — moderation requires admin or prayer_team role', 403);
}

$id     = (int) ($_GET['id'] ?? $body['requestID'] ?? 0);
$status = (string) ($body['status'] ?? '');
if ($id <= 0) {
    ApiResponse::error('requestID is required', 400);
}
if (in_array($status, ['active', 'answered', 'archived'], true) === false) {
    ApiResponse::error('status must be one of active|answered|archived', 400);
}
$testimony = isset($body['testimony']) === true ? trim((string) $body['testimony']) : '';

$siteId      = Site::id();
$moderatorId = ApiAuth::actorUserId();

$db = App::db();
// 🛡️ kind = 'request' — defense in depth so a bearer key or session with
// prayer-requests:write scope can't flip a Praise (#260) row's status
// through this prayer-requests-labelled endpoint (literal, not bound —
// fixed constant, not user input).
if ($status === 'answered') {
    $stmt = $db->prepare(
        'UPDATE tblPrayerRequests SET status = ?, testimony = ?, answeredAt = NOW(), '
        . 'moderatorID = ?, moderatedAt = NOW() '
        . 'WHERE requestID = ? AND siteID = ? AND kind = \'request\''
    );
    if ($stmt === false) {
        ApiResponse::error('Database error', 500);
    }
    $stmt->bind_param('ssiii', $status, $testimony, $moderatorId, $id, $siteId);
} else {
    $stmt = $db->prepare(
        'UPDATE tblPrayerRequests SET status = ?, moderatorID = ?, moderatedAt = NOW() '
        . 'WHERE requestID = ? AND siteID = ? AND kind = \'request\''
    );
    if ($stmt === false) {
        ApiResponse::error('Database error', 500);
    }
    $stmt->bind_param('siii', $status, $moderatorId, $id, $siteId);
}
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
if ($ok === false) {
    ApiResponse::error('Failed to moderate request', 500);
}
if ($affected === 0) {
    ApiResponse::error('Prayer request not found', 404);
}

Logger::activity('ApiPrayerRequestModerate', 'API: moderated prayer request #' . $id . ' → ' . $status);

ApiResponse::success(['requestID' => $id, 'status' => $status]);
