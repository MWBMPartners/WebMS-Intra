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
 * -----------------------------------------------------------------------------
 * #518 FIX (17 September 2026): the membership fetch below already scopes
 * `userID` to THIS site (so a cross-organisation id already got 404), but
 * nothing stopped a session-mode administrator changing `isAdmin` — the
 * PORTAL-WIDE flag — from inside one organisation, and nothing stopped
 * changing `fullName`/`isActive` on a global administrator's account, a
 * portal-wide administrator's account, or an account that also belongs to
 * another organisation (each of those reaches beyond this site even
 * though the site-membership row itself is fine). Every write now goes
 * through Portal\Core\AccountGuard first — see that class for the full
 * rule.
 *
 * @package   Portal\API\Users
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/323
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
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

// 🛡️ #518: is this caller even allowed to SEE this account? A missing
//    account and one that belongs to another organisation must look
//    identical — a plain 404 either way — see AccountGuard's own
//    docblock for why. This runs before anything else touches the
//    account.
if (AccountGuard::check($userId, AccountGuard::REACH_VIEW, 'API: update account #' . $userId) !== AccountGuard::ALLOW) {
    ApiResponse::error('User not found', 404);
}

$db     = App::db();
$siteId = Site::id();

// 🔍 Fetch the existing user AS A MEMBER OF THIS SITE — 404 otherwise (both
//    for a genuinely unknown userID and for a cross-site id probe). On a
//    SINGLE-organisation installation this still 404s for an account with
//    no membership row at all, exactly as it did before #518 — the
//    isSiteAdmin field this endpoint can also write needs that row to
//    update, so an account with none simply cannot be reached here.
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

// 🛡️ isAdmin is the PORTAL-WIDE admin flag. `sessionNeedsAdmin` (passed to
//    ApiAuth::requireWrite() above) means ANY administrator of the
//    organisation that is open — including an ordinary site
//    administrator, NOT only a global one (App::isAdmin(); see
//    ApiAuth.php's own corrected comment, #518). That is why a bearer
//    key is excluded here (a site-scoped key must never promote a member
//    to portal admin, #323 Phase 2 review) AND why, further down, this
//    write is also re-checked against AccountGuard::REACH_PORTAL — which
//    is where the actual "only a GLOBAL administrator" rule is enforced.
//    isSiteAdmin (site-scoped, below) remains available to bearer keys.
if (ApiAuth::source() === 'session' && array_key_exists('isAdmin', $body) === true) {
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
// 🛡️ #518: work out what is REALLY changing, against the row already
//    fetched — a field re-submitted with its own current value is not a
//    change, and does not get checked (or refused). $old's flags came
//    from a prepared statement, so they are compared here as whole
//    numbers, never as text (#497).
// -----------------------------------------------------------------------------
$portalChange = (
    ApiAuth::source() === 'session'
    && array_key_exists('isAdmin', $body) === true
    && ((bool) $body['isAdmin'] === true ? 1 : 0) !== (int) $old['isAdmin']
);
$accountChange = (
    (array_key_exists('fullName', $body) === true && trim((string) $body['fullName']) !== (string) $old['fullName'])
    || (array_key_exists('isActive', $body) === true && ((bool) $body['isActive'] === true ? 1 : 0) !== (int) $old['isActive'])
);
$thisOrgChange = (
    $hasSiteAdmin === true
    && $isSiteAdmin !== (int) $old['isSiteAdmin']
);

// 🛡️ Portal-wide rights first, on their own — this is where "only a
//    GLOBAL administrator" is actually enforced (see the isAdmin comment
//    above).
if ($portalChange === true) {
    $verdict = AccountGuard::check($userId, AccountGuard::REACH_PORTAL, 'API: change portal-wide administrator rights on account #' . $userId);
    if ($verdict === AccountGuard::NOT_FOUND) {
        ApiResponse::error('User not found', 404);
    }
    if ($verdict !== AccountGuard::ALLOW) {
        ApiResponse::error(AccountGuard::message($verdict, AccountGuard::REACH_PORTAL), 403);
    }
}

// 🛡️ Then anything stored on the account itself.
if ($accountChange === true) {
    $verdict = AccountGuard::check($userId, AccountGuard::REACH_ACCOUNT, 'API: change account #' . $userId);
    if ($verdict === AccountGuard::NOT_FOUND) {
        ApiResponse::error('User not found', 404);
    }
    if ($verdict !== AccountGuard::ALLOW) {
        ApiResponse::error(AccountGuard::message($verdict, AccountGuard::REACH_ACCOUNT), 403);
    }
}

// 🛡️ Finally, a change limited to THIS organisation's own membership row.
if ($thisOrgChange === true) {
    $verdict = AccountGuard::check($userId, AccountGuard::REACH_THIS_ORG, 'API: change site administrator flag on account #' . $userId);
    if ($verdict === AccountGuard::NOT_FOUND) {
        ApiResponse::error('User not found', 404);
    }
    if ($verdict !== AccountGuard::ALLOW) {
        ApiResponse::error(AccountGuard::message($verdict, AccountGuard::REACH_THIS_ORG), 403);
    }
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
