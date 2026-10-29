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
use Portal\Core\GdprEraser;
use Portal\Core\Site;

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
    header('Location: ' . Site::url('admin/users'));
    exit();
}
// 🗂️ What the form may ask for (#569). It offers two choices now — keep
//    their records, or erase their personal details — because the portal
//    performs ONE kind of erasure; see the note on the form itself.
//
//    'anonymise' and 'delete' are still ACCEPTED and both mean 'erase'.
//    They are the two words the old form sent, they are still valid values
//    of the dataDisposition column so that existing audit rows keep their
//    wording, and a bookmarked form or a replayed post may still send one.
//    Treating them as 'erase' is the honest reading: under the old code they
//    did nothing at all, so there is no behaviour to preserve.
//
//    Anything else, including nothing at all, means 'retain'. A request
//    chooses the TYPE of what it sends as well as the value, so $disp is
//    cast to a string above before it reaches this test.
if (in_array($disp, ['anonymise', 'delete', 'erase'], true) === true) {
    $disp = 'erase';
} elseif ($disp !== 'retain') {
    $disp = 'retain';
}
if ($userId === $adminId) {
    $_SESSION['flash_msg']  = "You can't offboard your own account.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/users'));
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
    header('Location: ' . Site::url('offboarding'));
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

    // 6. Remove EVERY role holding, in EVERY organisation (#516: a role now
    //    belongs to one organisation, but offboarding ends every
    //    membership this person has, everywhere — see
    //    admin/sites/users.php for the "leave ONE organisation" case,
    //    where the composite foreign key on tblUserRoles removes only that
    //    organisation's holdings when the membership row is deleted).
    $run('delete_user_roles',
        'DELETE FROM tblUserRoles WHERE userID = ?',
        [$userId], 'i'
    );

    // 6a/6b. Remove EVERY user-group and department membership, in EVERY
    //    organisation (#517), flags included — the same reasoning as step 6.
    //    Offboarding is a WHOLE-PORTAL exit, and step 4 above only switches
    //    membership rows OFF (it does not delete them), so the composite
    //    foreign keys on tblUserGroups/tblUserDepts do not remove anything
    //    by themselves here. "Leave ONE organisation" is
    //    admin/sites/users.php, where deleting the membership row removes
    //    only that organisation's group and department memberships. The
    //    "awaiting placement" pens (tblUserGroupsUnplaced,
    //    tblUserDeptsUnplaced) are deliberately NOT touched, exactly as
    //    step 6 leaves tblUserRolesUnplaced alone: a parked row waits for a
    //    global administrator's decision, and grants nothing meanwhile.
    //    rehire.php restores none of these.
    $run('delete_user_groups',
        'DELETE FROM tblUserGroups WHERE userID = ?',
        [$userId], 'i'
    );
    $run('delete_user_depts',
        'DELETE FROM tblUserDepts WHERE userID = ?',
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

    // 10. 🗑️ The erasure itself, when that is what was asked for (#569).
    //
    //     This used to be the gap: the choice was written to the audit row
    //     above and NOTHING ELSE HAPPENED. An administrator picked
    //     "Anonymise" or "Delete", was told the offboarding had succeeded,
    //     and the person's details stayed exactly where they were. The code
    //     said the erasure engine had not shipped; it had.
    //
    //     WHY A REQUEST IS FILED RATHER THAN THE ERASURE RUN HERE. There is
    //     one audited erasure path in this portal — file, confirm, run —
    //     with a hash chain over what it did, and the Erasure pages drive
    //     it. Writing a second path that erased inline would mean two pieces
    //     of code that must agree about 35 tables for ever. It also keeps
    //     the undo window meaningful: offboarding can be undone by
    //     offboarding/rehire.php, and erasing a person's details at this
    //     moment would destroy the very things rehire puts back.
    //
    //     `true` means ALREADY CONFIRMED: the request carries no token and
    //     sends no e-mail, because the organisation has made this decision
    //     about somebody who is leaving, and there is no link for anybody to
    //     click. It lands as `pending_review`.
    //
    //     Filed INSIDE the transaction on purpose: if any step above fails
    //     and the whole thing rolls back, the erasure request must go with
    //     it, or a failed offboarding would leave a live request to erase
    //     somebody who was never offboarded.
    //
    //     WHEN IT RUNS is `offboarding.erasureMode`:
    //       on_request        — it waits on Admin → Erasure requests (the
    //                           nothing new has to be set up for it).
    //       after_undo_window — the daily housekeeping job runs it once the
    //                           undo window has passed.
    //     Running it immediately is deliberately not offered yet: #663.
    $erasureNote = '';
    if ($disp === 'erase') {
        // An erasure request may already be open for this person — they may
        // have asked for one themselves. Filing a second would mean two runs
        // over the same account, so the existing one is used.
        $existing = GdprEraser::openRequestFor(Site::id(), $userId);
        if ($existing !== null) {
            $stepsLog[] = ['step' => 'erasure_request', 'ok' => true, 'reused' => $existing];
            $erasureNote = ' An erasure request was already open for this person (#' . $existing . '), so that one stands.';
        } else {
            $filed = GdprEraser::fileRequest(Site::id(), $userId, true);
            $stepsLog[] = ['step' => 'erasure_request', 'ok' => true, 'requestID' => $filed['requestID']];
            $mode = (string) (App::settings('offboarding.erasureMode') ?? 'on_request');
            $erasureNote = $mode === 'after_undo_window'
                ? ' An erasure request has been filed and will be carried out automatically once the window for undoing this offboarding has passed.'
                : ' An erasure request has been filed. Nothing is erased until somebody carries it out on Admin, Erasure requests.';
        }
        // The audit row was written before this step, so its log is brought
        // up to date rather than left saying less than happened.
        $logJson = json_encode($stepsLog);
        $stmt = $db->prepare('UPDATE tblOffboarding SET stepsLog = ? WHERE userID = ? ORDER BY offboardingID DESC LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('si', $logJson, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $db->commit();

    $_SESSION['flash_msg']  = 'User offboarded. Audit row + per-step outcomes recorded.' . $erasureNote;
    $_SESSION['flash_type'] = 'success';
} catch (\Throwable $e) {
    $db->rollback();
    \Portal\Core\Logger::errorPlatform('Offboarding', 'Critical', 'OFFBOARD_FAIL', $e->getMessage(), 'userID=' . $userId);
    $_SESSION['flash_msg']  = 'Offboarding failed: ' . $e->getMessage();
    $_SESSION['flash_type'] = 'danger';
}

header('Location: ' . Site::url('offboarding'));
exit();
