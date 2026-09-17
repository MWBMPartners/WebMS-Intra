<?php
// Path: public_html/offboarding/do.php
/**
 * -----------------------------------------------------------------------------
 * Offboarding — Revocation Handler 🚪
 * -----------------------------------------------------------------------------
 * POST handler that performs revocation across multiple tables in a
 * transaction. Records per-step outcomes in tblOffboarding.stepsLog.
 *
 * Also revokes tblLinkedAccounts (SSO links) and tblTrustedDevices
 * (2FA-bypass cookies) (#B7a) so an offboarded user's stale SSO link or
 * trusted-device cookie can't be used to sign back in, and so a later SSO
 * sign-in attempt fails cleanly instead of crashing — see the matching
 * inactive-account guard in Auth::callbackMS365()/callbackGoogle() (#B7b).
 *
 * #518 FIX (17 September 2026): App::isAdmin() plus the own-account check
 * below let an administrator of ANY organisation offboard ANY account —
 * deactivate it, wipe its passkeys, clear its password — with no check
 * that the account belonged to their own organisation at all. Now goes
 * through Portal\Core\AccountGuard first.
 *
 * @package   Portal\Offboarding
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/240
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db        = App::db();
$adminId   = (int) ($_SESSION['user_id'] ?? 0);
$userId    = (int) ($_POST['userID'] ?? 0);
$reason    = trim((string) ($_POST['reason'] ?? ''));
$disp      = (string) ($_POST['dataDisposition'] ?? 'retain');
$effective = (string) ($_POST['effectiveDate'] ?? date('Y-m-d'));

if ($userId <= 0 || $reason === '') {
    header('Location: /admin/users');
    exit();
}
if (in_array($disp, ['retain','anonymise','delete'], true) === false) {
    $disp = 'retain';
}
if ($userId === $adminId) {
    $_SESSION['flash_msg']  = "You can't offboard your own account.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/users');
    exit();
}

// 🛡️ #518: for a non-global administrator, this has already confirmed the
//    account has no membership row — active or ended — in any OTHER
//    organisation, and is not a global or portal-wide administrator's
//    account. Before this fix, a missing account number reached the
//    database directly below and, depending on the row shape, could
//    surface a raw database error instead of a clean message.
$verdict = AccountGuard::check($userId, AccountGuard::REACH_ACCOUNT, 'offboard account #' . $userId);
if ($verdict !== AccountGuard::ALLOW) {
    $_SESSION['flash_msg']  = AccountGuard::message($verdict, AccountGuard::REACH_ACCOUNT);
    $_SESSION['flash_type'] = 'danger';
    header('Location: /offboarding');
    exit();
}

$stepsLog = [];

/**
 * Run a DML statement; record success / error.
 *
 * @return int Affected rows (0 on error or no matches).
 */
$run = function (string $label, string $sql, array $params, string $types) use ($db, &$stepsLog): int {
    try {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $stepsLog[] = ['step' => $label, 'ok' => false, 'error' => $db->error];
            return 0;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $stepsLog[] = ['step' => $label, 'ok' => true, 'affected' => $affected];
        return $affected;
    } catch (\Throwable $e) {
        $stepsLog[] = ['step' => $label, 'ok' => false, 'error' => $e->getMessage()];
        return 0;
    }
};

try {
    $db->begin_transaction();

    // 1. Deactivate the user (no sign-in).
    $run('deactivate_user',
        'UPDATE tblUsers SET isActive = 0 WHERE userID = ?',
        [$userId], 'i'
    );

    // 2. Delete passkeys / WebAuthn credentials.
    $run('delete_webauthn',
        'DELETE FROM tblWebAuthnCredentials WHERE userID = ?',
        [$userId], 'i'
    );

    // 3. Disable the local-account password — clear hash so it's unusable.
    //    A future rehire requires the user to reset via the forgot-password
    //    flow.
    $run('clear_password_hash',
        "UPDATE tblLocalAccounts SET passwordHash = '' WHERE userID = ?",
        [$userId], 'i'
    );

    // 4. End site memberships.
    $run('end_site_memberships',
        'UPDATE tblUserSites SET isActive = 0 WHERE userID = ?',
        [$userId], 'i'
    );

    // 5. End leadership assignments (any with NULL endDate).
    $run('end_leadership_assignments',
        'UPDATE tblLeadershipAssignments SET endDate = ? WHERE userID = ? AND endDate IS NULL',
        [$effective, $userId], 'si'
    );

    // 5a. End Small Groups memberships (#150) — a group leadership is
    //     exactly the kind of standing access offboarding exists to
    //     revoke. Ends both active AND pending (an outstanding join
    //     request a leaver never followed up on should not linger either).
    //     History is retained (status='ended'), never deleted.
    $run('end_small_group_memberships',
        "UPDATE tblSmallGroupMembers SET status = 'ended', endedAt = ? WHERE userID = ? AND status IN ('active','pending')",
        [$effective, $userId], 'si'
    );

    // 6. Remove user role assignments.
    $run('delete_user_roles',
        'DELETE FROM tblUserRoles WHERE userID = ?',
        [$userId], 'i'
    );

    // 7. Delete linked SSO accounts (#B7a). Without this, tblLinkedAccounts
    //    rows survive deactivation — findUserByLink()/findUserByEmail() in
    //    Auth::callbackMS365()/callbackGoogle() both filter `isActive = 1`,
    //    so a later SSO sign-in from the offboarded person would fall
    //    through to createUser() and crash on the tblUsers.emailAddress
    //    UNIQUE key. A stale link is also a live re-entry surface on its
    //    own. Mirrors the table this deletes from account/unlink.php.
    $run('delete_linked_accounts',
        'DELETE FROM tblLinkedAccounts WHERE userID = ?',
        [$userId], 'i'
    );

    // 8. Revoke trusted-device 2FA-bypass cookies (#B7a). Same WHERE clause
    //    as Auth::revokeAllTrustedDevices() — kept as an explicit $run()
    //    step (rather than calling that helper) so the outcome is captured
    //    in stepsLog like every other offboarding action.
    $run('revoke_trusted_devices',
        'UPDATE tblTrustedDevices SET revokedAt = NOW() WHERE userID = ? AND revokedAt IS NULL',
        [$userId], 'i'
    );

    // 8a. Delete Web Push subscriptions (#322) — offboarding's whole point
    //     is credential revocation; an endpoint + p256dh/auth key pair is
    //     exactly that (a leaving volunteer's own device should stop
    //     receiving portal notifications the moment they're offboarded).
    $run('delete_push_subscriptions',
        'DELETE FROM tblPushSubscriptions WHERE userID = ?',
        [$userId], 'i'
    );

    // 9. Audit row.
    $logJson = json_encode($stepsLog);
    $stmt = $db->prepare(
        'INSERT INTO tblOffboarding (userID, effectiveDate, reason, dataDisposition, offboardedByID, stepsLog) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('isssis', $userId, $effective, $reason, $disp, $adminId, $logJson);
        $stmt->execute();
        $stmt->close();
    }

    $db->commit();

    $_SESSION['flash_msg']  = 'User offboarded. Audit row + per-step outcomes recorded.';
    $_SESSION['flash_type'] = 'success';
} catch (\Throwable $e) {
    $db->rollback();
    \Portal\Core\Logger::errorPlatform('Offboarding', 'Critical', 'OFFBOARD_FAIL', $e->getMessage(), 'userID=' . $userId);
    $_SESSION['flash_msg']  = 'Offboarding failed: ' . $e->getMessage();
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /offboarding');
exit();
