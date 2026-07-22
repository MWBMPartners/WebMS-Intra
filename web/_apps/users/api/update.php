<?php
// Path: _apps/users/api/update.php
/**
 * -----------------------------------------------------------------------------
 * Users API — Update User 👤
 * -----------------------------------------------------------------------------
 * Admin-gated (session admin ALWAYS; bearer requires `users:write` AND the
 * `api.users.update.enabled` flag, seeded 'false' by migration 147).
 *
 *   PUT/PATCH /api/v1/users/{id}
 *   (or POST /api/users/update?id=N — legacy alias, {"userID": N} in body)
 *
 * Editable: fullName, isActive, isAdmin (role), isSiteAdmin (site-membership,
 * tblUserSites) — ONLY. Email address and password are EXCLUDED from v1
 * (silently ignored if present in the body; use the dedicated auth flows).
 *
 * A session caller can never deactivate their OWN account via this endpoint
 * (`isActive` → 0 on the caller's own userID is rejected with 400) — this
 * only guards session self-lockout; a bearer key has no session to protect.
 *
 * @package   Portal\API\Users
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/323
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Site;

ApiAuth::requireMethod('POST');
$body = ApiAuth::requireWrite('users:write', sessionNeedsAdmin: true);

$userId = (int) ($_GET['id'] ?? $body['userID'] ?? 0);
if ($userId <= 0) {
    ApiResponse::error('userID is required', 400);
}

$db     = App::db();
$siteId = Site::id();

// 🔍 Fetch the existing user AS A MEMBER OF THIS SITE — 404 otherwise (both
//    for a genuinely unknown userID and for a cross-site id probe).
$fetch = $db->prepare(
    'SELECT u.userID, u.fullName, u.isActive, u.isAdmin, COALESCE(us.isSiteAdmin, 0) AS isSiteAdmin '
    . 'FROM tblUsers u '
    . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
    . 'WHERE u.userID = ? LIMIT 1'
);
if ($fetch === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_USER_UPDATE_FETCH_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$fetch->bind_param('ii', $siteId, $userId);
$fetch->execute();
$old = $fetch->get_result()->fetch_assoc();
$fetch->close();
if ($old === null) {
    ApiResponse::error('User not found', 404);
}

$new = $old;

// -----------------------------------------------------------------------------
// 🛡️ Self-deactivation guard (session mode only has an actor to protect)
// -----------------------------------------------------------------------------
if (array_key_exists('isActive', $body) === true) {
    $requestedActive = (bool) $body['isActive'] === true ? 1 : 0;
    if ($requestedActive === 0 && $userId === (ApiAuth::actorUserId() ?? -1)) {
        ApiResponse::error('Cannot deactivate your own account', 400);
    }
}

// -----------------------------------------------------------------------------
// 🛠️ Collect provided, updatable fields (email + password are excluded — not
//    read from the body at all, so they are silently ignored if present)
// -----------------------------------------------------------------------------
$userSet    = [];
$userTypes  = '';
$userParams = [];

if (array_key_exists('fullName', $body) === true) {
    $fullName = trim((string) $body['fullName']);
    if ($fullName === '' || mb_strlen($fullName) > 255) {
        ApiResponse::error('fullName must be non-empty and ≤255 characters', 400);
    }
    $userSet[]    = 'fullName = ?';
    $userTypes   .= 's';
    $userParams[] = $fullName;
    $new['fullName'] = $fullName;
}

if (array_key_exists('isActive', $body) === true) {
    $isActive = (bool) $body['isActive'] === true ? 1 : 0;
    $userSet[]    = 'isActive = ?';
    $userTypes   .= 'i';
    $userParams[] = $isActive;
    $new['isActive'] = $isActive;
}

if (array_key_exists('isAdmin', $body) === true) {
    $isAdmin = (bool) $body['isAdmin'] === true ? 1 : 0;
    $userSet[]    = 'isAdmin = ?';
    $userTypes   .= 'i';
    $userParams[] = $isAdmin;
    $new['isAdmin'] = $isAdmin;
}

$hasSiteAdmin = array_key_exists('isSiteAdmin', $body) === true;
$isSiteAdmin  = null;
if ($hasSiteAdmin === true) {
    $isSiteAdmin = (bool) $body['isSiteAdmin'] === true ? 1 : 0;
    $new['isSiteAdmin'] = $isSiteAdmin;
}

if (count($userSet) === 0 && $hasSiteAdmin === false) {
    ApiResponse::error('No updatable fields in request body', 400);
}

// -----------------------------------------------------------------------------
// 💾 Transaction: tblUsers (role/active) + tblUserSites (site-membership)
// -----------------------------------------------------------------------------
App::beginTransaction();
try {
    if (count($userSet) > 0) {
        $userTypes   .= 'i';
        $userParams[] = $userId;
        $sql = 'UPDATE tblUsers SET ' . implode(', ', $userSet) . ' WHERE userID = ?';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare user update: ' . $db->error);
        }
        $stmt->bind_param($userTypes, ...$userParams);
        if ($stmt->execute() === false) {
            throw new \RuntimeException('Failed to update user: ' . $stmt->error);
        }
        $stmt->close();
    }

    if ($hasSiteAdmin === true) {
        $usStmt = $db->prepare(
            'UPDATE tblUserSites SET isSiteAdmin = ? WHERE userID = ? AND siteID = ?'
        );
        if ($usStmt === false) {
            throw new \RuntimeException('Failed to prepare site membership update: ' . $db->error);
        }
        $usStmt->bind_param('iii', $isSiteAdmin, $userId, $siteId);
        if ($usStmt->execute() === false) {
            throw new \RuntimeException('Failed to update site membership: ' . $usStmt->error);
        }
        $usStmt->close();
    }

    App::commit();
} catch (\Throwable $ex) {
    App::rollback();
    Logger::errorPlatform('MySQL', 'Error', 'API_USER_UPDATE_FAIL', $ex->getMessage(), '');
    ApiResponse::error('Database error', 500);
}

Logger::audit('tblUsers', $userId, 'update', ApiResponse::filterSensitive($old), ApiResponse::filterSensitive($new));
Logger::activity('ApiUserUpdate', 'API: updated user #' . $userId);

ApiResponse::success(ApiResponse::filterSensitive($new), 200);
