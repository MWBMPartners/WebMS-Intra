<?php
// Path: apps/auth/account/change-password.php
/**
 * -----------------------------------------------------------------------------
 * Change Password Handler 🔐
 * -----------------------------------------------------------------------------
 * Verifies the user's current password, validates the new password against the
 * configurable policy, hashes it, and updates tblLocalAccounts.  Regenerates
 * the session ID after a credential change as a security best practice.
 * Since #598: signs the person out of every OTHER browser (D-104) while this
 * one stays, and allows five wrong current passwords per account in 15
 * minutes, shared with "switch off two-factor sign-in".
 * -----------------------------------------------------------------------------
 * @package    Portal\Auth
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

// -----------------------------------------------------------------------------
// 1. 🛡️ Only accept POST from authenticated users
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('account'), true, 302);
    exit();
}

Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    header('Location: ' . Site::url('account') . '?error=csrf', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 2. 📝 Read and validate input
// -----------------------------------------------------------------------------

$userId          = (int) $_SESSION['user_id'];
$currentPassword = $_POST['current_password'] ?? '';
$newPassword     = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['new_password_confirm'] ?? '';

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    header('Location: ' . Site::url('account') . '?error=pw_empty', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 3. 🔑 Verify current password against tblLocalAccounts
// -----------------------------------------------------------------------------

// 🚦 Five wrong current passwords per ACCOUNT in 15 minutes, shared with
//    "switch off two-factor sign-in" (#598 item 5). Asked BEFORE the password
//    is checked, so a refused try costs no check at all and tells a guesser
//    nothing about whether it was right. See Auth::PASSWORD_CHECK_FAMILY for
//    the whole story; this page used to allow unlimited guesses.
if (Auth::currentPasswordTriesUsedUp($userId) === true) {
    Logger::activity('PasswordChangeBlocked', 'Too many wrong current passwords; nothing was checked or changed', $userId);
    header('Location: ' . Site::url('account') . '?error=pw_wait', true, 302);
    exit();
}

$stmt = $mysqli->prepare(
    'SELECT localID, passwordHash FROM tblLocalAccounts WHERE userID = ? LIMIT 1'
);

if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'CHANGEPW_PREP_FAIL', $mysqli->error, '');
    header('Location: ' . Site::url('account') . '?error=db', true, 302);
    exit();
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$local  = $result->fetch_assoc();
$stmt->close();

if ($local === null || ($local['passwordHash'] ?? '') === '') {
    Logger::activity('PasswordChangeFailed', 'No local account found for userID: ' . $userId);
    header('Location: ' . Site::url('account') . '?error=db', true, 302);
    exit();
}

if (password_verify($currentPassword, $local['passwordHash']) === false) {
    Auth::recordWrongCurrentPassword($userId);
    Logger::activity('PasswordChangeFailed', 'Incorrect current password');
    header('Location: ' . Site::url('account') . '?error=pw_current', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 4. 📋 Validate new password
// -----------------------------------------------------------------------------

if ($newPassword !== $confirmPassword) {
    header('Location: ' . Site::url('account') . '?error=pw_match', true, 302);
    exit();
}

$validation = Auth::validatePassword($newPassword);
if ($validation['valid'] === false) {
    header('Location: ' . Site::url('account') . '?error=pw_policy', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 5. 🔐 Hash and update the password
// -----------------------------------------------------------------------------

// 🔑 ONE PLACE STORES A PASSWORD (#728). This page used to hash it and write
//    its own UPDATE. What it gains by asking `Auth::setPassword()` is the
//    READ-BACK: the old code never looked, and `affected_rows` could not have
//    told it anything either, because that answers 0 both for "no such row"
//    and for "the hash was already that".
//
//    No `$signName` is passed, which means "update an existing row and refuse
//    if there is none". That is right here: step 4 above has already read this
//    person's own `tblLocalAccounts` row, so a missing row at this point is a
//    row deleted in the last few milliseconds, and inventing a sign-in name
//    for somebody changing a password would be a new account, not a change.
//
// 🔐 AND IT SIGNS THE PERSON OUT OF EVERY OTHER BROWSER (#598, D-104), with
//    this browser staying signed in. The password, the sign-out and the
//    remembered devices now go in ONE transaction, so a password cannot be
//    changed while the sessions somebody else holds survive (they used to:
//    nothing ended them at all). Auth::endOtherSessionsOf() is the one place
//    that is done; it answers false only on a database still waiting for
//    migration 270, and then the page says so rather than claiming it.
$othersSignedOut = false;
$mysqli->begin_transaction();
try {
    $stored = Auth::setPassword($mysqli, $userId, $newPassword);
    if ($stored['ok'] === false) {
        // The sentence is NOT shown to the person: this page speaks in codes
        // the account page turns into words, and `why` can say more about the
        // account than a sign-in page should. It is recorded instead.
        throw new \RuntimeException($stored['why']);
    }

    $othersSignedOut = Auth::endOtherSessionsOf($mysqli, $userId);

    // 🔐 Revoke ALL trusted-device cookies for this user — if the password
    // was changed because of a suspected compromise, any "trusted device"
    // the attacker had access to is now no longer trusted. (Same connection,
    // so inside the same transaction.)
    Auth::revokeAllTrustedDevices($userId);

    $mysqli->commit();
} catch (\Throwable $ex) {
    $mysqli->rollback();
    Logger::errorPlatform('Auth', 'Error', 'CHANGEPW_UPDATE_FAIL', $ex->getMessage(), '');
    header('Location: ' . Site::url('account') . '?error=db', true, 302);
    exit();
}

// 🖥️ Committed: NOW this browser takes the account's new sign-in number, so it
//    is the one that stays signed in. Only after the commit — given before, a
//    change that rolled back left this browser holding a number the account
//    never had (#598 check round 2).
Auth::passwordChangeCommitted();

// 🔄 Regenerate session ID after credential change (security best practice).
//    The session's entries, including the sign-in number just given to it,
//    are kept.
// See: https://owasp.org/www-community/attacks/Session_fixation
session_regenerate_id(true);

// 📝 Log the successful password change
Logger::activity(
    'PasswordChanged',
    $othersSignedOut === true
        ? 'User changed their password; their other browsers are signed out'
        : 'User changed their password; their other browsers could NOT be signed out (database upgrade waiting)'
);

// -----------------------------------------------------------------------------
// 6. 🔀 Redirect with success message
// -----------------------------------------------------------------------------

// `pwchanged=1` says the other browsers were signed out; `2` says they could
// not be, so the account page never claims a sign-out that did not happen.
header('Location: ' . Site::url('account') . '?pwchanged=' . ($othersSignedOut === true ? '1' : '2'), true, 302);
exit();
