<?php
// Path: _apps/users/api/create.php
/**
 * -----------------------------------------------------------------------------
 * Users API — Create User 👤
 * -----------------------------------------------------------------------------
 * Admin-gated (session admin ALWAYS; bearer requires `users:write` AND the
 * `api.users.create.enabled` flag, seeded 'false' by migration 147 — an admin
 * must consciously opt in before this endpoint is reachable at all).
 *
 * Passwords are NEVER accepted here. The new account gets a random,
 * cryptographically-unusable password hash and is enrolled with a
 * (deactivated-by-design) local account so the EXISTING forgot-password
 * flow (`_apps/auth/forgot-password/save.php`, which only requires a
 * tblUsers + tblLocalAccounts pair to exist) can be used to set a real
 * password later — no separate invite/reset mechanism is invented here.
 *
 *   POST /api/v1/users
 *   Content-Type: application/json
 *   {
 *     "emailAddress": "jane@example.com",   (required, valid + globally unique)
 *     "fullName":     "Jane Doe",            (required, ≤255)
 *     "isActive":     true,                   (optional, default true)
 *     "isAdmin":      false,                  (optional, default false)
 *     "isSiteAdmin":  false                   (optional, default false — tblUserSites)
 *   }
 *
 * Deliberately EXCLUDED from v1 (highest-blast-radius surface — kept out of
 * scope rather than under-validated): `isRootAdmin` / `isSiteRootAdmin`
 * (root-tier privilege escalation) and any password field.
 *
 * -----------------------------------------------------------------------------
 * #518 FIX (17 September 2026): `isAdmin` here reads exactly the same way
 * `sessionNeedsAdmin` does — "any administrator of the organisation that
 * is open", not "a global administrator" — so a session-mode SITE
 * administrator could already mint a brand-new portal-wide administrator
 * account from inside one organisation. Refused now unless the caller is
 * a global administrator (Portal\Core\AccountGuard::actorIsGlobal()).
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

// 🛡️ #518: refused BEFORE any field validation, so the answer never
//    depends on whether the rest of the request would otherwise have
//    succeeded. Only a session request from a GLOBAL administrator may
//    ask for the portal-wide isAdmin flag — a bearer key never reaches
//    here at all (the condition below is always false for one, since
//    ApiAuth::source() is only ever 'apikey' or 'session'), which matches
//    isAdmin being silently ignored for bearer requests further down.
if (ApiAuth::source() === 'session'
    && array_key_exists('isAdmin', $body) === true
    && (bool) $body['isAdmin'] === true
    && AccountGuard::actorIsGlobal() === false
) {
    AccountGuard::logRefusal(
        'API: create a new account with portal-wide administrator rights',
        AccountGuard::GLOBAL_ONLY,
        'portal_grant',
        null
    );
    ApiResponse::error(AccountGuard::message(AccountGuard::GLOBAL_ONLY, AccountGuard::REACH_PORTAL), 403);
}

$db     = App::db();
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 📥 Required fields
// -----------------------------------------------------------------------------
$emailAddress = strtolower(trim((string) ($body['emailAddress'] ?? '')));
if ($emailAddress === '' || filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
    ApiResponse::error('emailAddress is required and must be a valid email address', 400);
}

$fullName = trim((string) ($body['fullName'] ?? ''));
if ($fullName === '' || mb_strlen($fullName) > 255) {
    ApiResponse::error('fullName is required and must be ≤255 characters', 400);
}

// 🔍 Global uniqueness — tblUsers.emailAddress has a UNIQUE key
$dupCheck = $db->prepare('SELECT userID FROM tblUsers WHERE emailAddress = ? LIMIT 1');
if ($dupCheck === false) {
    Logger::errorPlatform('MySQL', 'Error', 'API_USER_CREATE_DUP_PREP', $db->error, '');
    ApiResponse::error('Database error', 500);
}
$dupCheck->bind_param('s', $emailAddress);
$dupCheck->execute();
$exists = $dupCheck->get_result()->fetch_assoc() !== null;
$dupCheck->close();
if ($exists === true) {
    ApiResponse::error('A user with that email address already exists', 409);
}

// -----------------------------------------------------------------------------
// 📥 Optional fields — role/site-membership flags only, no password
// -----------------------------------------------------------------------------
$isActive    = array_key_exists('isActive', $body) === false || (bool) $body['isActive'] === true ? 1 : 0;
// 🛡️ tblUsers.isAdmin is the PORTAL-WIDE ("Legacy Admin") flag — App::isAdmin()
//    returns true from it regardless of site. Granting it from an otherwise
//    tenant-pinned, site-scoped endpoint would let a site-scoped bearer key mint
//    a portal admin (#323 Phase 2 review). So honoured ONLY in session mode,
//    never for a bearer key — which gets the SITE-scoped isSiteAdmin only.
//    `sessionNeedsAdmin` does NOT mean the caller is a global administrator —
//    it means any administrator of the organisation that is open (#518). The
//    block above this comment is what actually restricts this to a GLOBAL
//    administrator; by the time execution reaches this line, either the body
//    genuinely has no isAdmin=true request from a session caller, or that
//    caller already passed the AccountGuard check — so this line just carries
//    the already-authorised value forward.
$isAdmin     = ApiAuth::source() === 'session'
    && array_key_exists('isAdmin', $body) === true && (bool) $body['isAdmin'] === true ? 1 : 0;
$isSiteAdmin = array_key_exists('isSiteAdmin', $body) === true && (bool) $body['isSiteAdmin'] === true ? 1 : 0;

// -----------------------------------------------------------------------------
// 💾 Transaction: tblUsers + tblLocalAccounts (unusable hash) + tblUserSites
// -----------------------------------------------------------------------------
App::beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT INTO tblUsers (fullName, emailAddress, isActive, isAdmin) VALUES (?, ?, ?, ?)'
    );
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare user insert: ' . $db->error);
    }
    $stmt->bind_param('ssii', $fullName, $emailAddress, $isActive, $isAdmin);
    if ($stmt->execute() === false) {
        throw new \RuntimeException('Failed to insert user: ' . $stmt->error);
    }
    $newUserId = (int) $stmt->insert_id;
    $stmt->close();

    // 🔐 Random, unusable password hash — this account can ONLY gain a real
    //    password via the existing forgot-password reset flow (or SSO).
    $unusableHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $laStmt = $db->prepare(
        'INSERT INTO tblLocalAccounts (userID, username, passwordHash, isVerified) VALUES (?, ?, ?, 0)'
    );
    if ($laStmt === false) {
        throw new \RuntimeException('Failed to prepare local account insert: ' . $db->error);
    }
    $laStmt->bind_param('iss', $newUserId, $emailAddress, $unusableHash);
    if ($laStmt->execute() === false) {
        throw new \RuntimeException('Failed to insert local account: ' . $laStmt->error);
    }
    $laStmt->close();

    $usStmt = $db->prepare(
        'INSERT INTO tblUserSites (userID, siteID, isSiteAdmin, isActive) VALUES (?, ?, ?, 1)'
    );
    if ($usStmt === false) {
        throw new \RuntimeException('Failed to prepare site membership insert: ' . $db->error);
    }
    $usStmt->bind_param('iii', $newUserId, $siteId, $isSiteAdmin);
    if ($usStmt->execute() === false) {
        throw new \RuntimeException('Failed to insert site membership: ' . $usStmt->error);
    }
    $usStmt->close();

    App::commit();
} catch (\Throwable $ex) {
    App::rollback();
    Logger::errorPlatform('MySQL', 'Error', 'API_USER_CREATE_FAIL', $ex->getMessage(), '');
    ApiResponse::error('Database error', 500);
}

$newData = [
    'userID'       => $newUserId,
    'fullName'     => $fullName,
    'emailAddress' => $emailAddress,
    'isActive'     => $isActive,
    'isAdmin'      => $isAdmin,
    'isSiteAdmin'  => $isSiteAdmin,
    'siteID'       => $siteId,
];

Logger::audit('tblUsers', $newUserId, 'create', null, ApiResponse::filterSensitive($newData));
Logger::activity('ApiUserCreate', 'API: created user #' . $newUserId . ' (' . $emailAddress . ')');

ApiResponse::success(ApiResponse::filterSensitive($newData), 201);
