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
use Portal\Core\Auth;
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

// 🔍 #521 (20 September 2026): tblUsers.emailAddress has a UNIQUE key, so
//    global uniqueness still has to be checked — but the old flat "already
//    exists" 409 told a non-global caller (a session-mode organisation
//    administrator, or a site-scoped API key) whether an address belongs
//    to an account on ANOTHER organisation, the same installation-wide
//    oracle #521 closes on the admin members page. AccountGuard::
//    emailAvailability() bounds it instead: an address in this caller's
//    reach still reads back plainly (409), one outside reach is refused
//    with vaguer wording (also 409 — a caller reaching this endpoint at
//    all already knows SOMETHING is wrong with the address, so 409 stays
//    right for both), rate limited (429, 5/hour per administrator or key,
//    with a Retry-After header) and recorded for a global administrator.
//    See that method's own docblock for the full design.
$emailVerdict = AccountGuard::emailAvailability($emailAddress, null, 'create account via API');
if ($emailVerdict === AccountGuard::EMAIL_TOO_MANY) {
    header('Retry-After: ' . AccountGuard::emailRetryAfter());
    ApiResponse::error(AccountGuard::emailMessage($emailVerdict), 429);
}
if ($emailVerdict === AccountGuard::EMAIL_IN_USE_HERE || $emailVerdict === AccountGuard::EMAIL_NOT_AVAILABLE) {
    ApiResponse::error(AccountGuard::emailMessage($emailVerdict), 409);
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

    // 🔐 A SIGN-IN ROW WITH NO USABLE PASSWORD (#728). The account can only
    //    gain a real password through the forgot-password reset flow, or sign
    //    in another way. The row has to exist for the reset flow to find it:
    //    `_apps/auth/forgot-password/save.php` joins `tblLocalAccounts` and
    //    puts NO condition on the hash, which was measured rather than assumed
    //    before this changed.
    //
    //    **What this changes, and it is a fix rather than a move.** It used to
    //    store `password_hash(bin2hex(random_bytes(32)))` — a hash nobody can
    //    match, but one nothing can RECOGNISE either. So a random hash made
    //    every account created through this API read as HAVING a sign-in
    //    password that nobody could use.
    //
    //    FOUR places read the EMPTY string as "no password" and ONE writes it:
    //    `Auth::loginLocal()` refuses an empty hash before `password_verify()`
    //    is reached, `_apps/auth/account/change-password.php` and
    //    `_apps/auth/2fa/disable.php` both refuse on it, and the
    //    disaster-recovery runbook reads `passwordHash <> ''` as "this account
    //    has a password"; `_apps/offboarding/do.php` writes it.
    //
    //    **An earlier version of this comment said "three places read" and
    //    named `admin/users/save.php`'s #586 read-back as one — which #728
    //    DELETED**, so it cited as evidence the thing it removed, missed two
    //    real readers, and called the one writer a reader.
    $stored = Auth::setUnusablePassword($db, $newUserId, $emailAddress, false);
    if ($stored['ok'] === false) {
        throw new \RuntimeException('Failed to create the sign-in row: ' . $stored['why']);
    }

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
// #933: the number only, never the e-mail address: this record belongs to
//    whoever called the API, so erasing the new member could never reach it.
Logger::activity('ApiUserCreate', 'API: created user #' . $newUserId);

ApiResponse::success(ApiResponse::filterSensitive($newData), 201);
