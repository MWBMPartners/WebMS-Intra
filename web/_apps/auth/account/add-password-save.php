<?php
// Path: _apps/auth/account/add-password-save.php
/**
 * -----------------------------------------------------------------------------
 * Account — store the password somebody has just added to their own account 🔑
 * -----------------------------------------------------------------------------
 * The handler behind `add-password.php`. POST only. It asks the same rule the
 * form page asked (`Portal\Core\SelfServicePassword`), stores the password
 * through the one place that stores one (`Auth::setPassword()`, #728), records
 * it in the activity log as an ADDITION, and e-mails the account's own address
 * to say a sign-in method was added.
 *
 * THE RULE IS ASKED TWICE, and that is the design (#647)
 * -----------------------------------------------------------------------------
 * Once before anything is read, and once on facts read INSIDE the transaction
 * that writes. Between the two, somebody else could have switched the setting
 * off, or the account could have been given a password by another route. The
 * second ask is the one that counts, and `Auth::setPassword()` locks the
 * account's own row, so the facts read after it cannot move under this handler.
 *
 * WHAT IT NEVER DOES
 * -----------------------------------------------------------------------------
 *   - It never writes a password itself. `tools/audit-checks/check_one_password_writer.py`
 *     is a hard gate that fails anything under `web/` which hashes or writes a
 *     password outside `Auth.php`, and rightly: before #728 seven places did it
 *     and no rule about passwords could be enforced anywhere.
 *   - **It never logs or prints the password, any part of its hash, or whose
 *     sign-in name a clash belongs to.** `Auth::setPassword()`'s refusals are
 *     written for a person and name nobody (#521: telling somebody which
 *     address is taken tells them that address has an account here), so they
 *     are shown as they come. Nothing else from the database is.
 *   - It never removes anything. The Microsoft or Google link and every passkey
 *     are untouched, and that is checked by a test rather than intended.
 *
 * TWO THINGS ABOUT `Auth::setPassword()` THAT THIS HANDLER HAS TO ALLOW FOR
 * -----------------------------------------------------------------------------
 *   1. **It opens no transaction**, on purpose, and its lock on the account's
 *      own row is therefore only real inside a caller's transaction (#697: a
 *      lock outside one is decoration). So this handler opens one.
 *   2. **It THROWS rather than answering when the database itself refuses**,
 *      because `DbServer::connect()` sets `MYSQLI_REPORT_STRICT |
 *      MYSQLI_REPORT_ERROR` — it takes BOTH, measured. An exception escaping
 *      with the transaction open leaves it open (#694, round 3), so the write
 *      is inside a try/catch that rolls back.
 *
 * WHY THE E-MAIL COMES AFTER THE COMMIT
 * -----------------------------------------------------------------------------
 * The password is what the person asked for; the notice is how somebody else
 * would find out. If sending could stop the password being stored, a portal
 * with a broken mail route would have no recovery route either — so the e-mail
 * is sent after the commit, inside its own try/catch, and what happened to it
 * is reported honestly. `Mailer::send()` answers true for SENT **or** KEPT in
 * the mail queue, so `Mailer::lastOutcome()` is what this page reads before
 * saying anything about it, and the default Microsoft route THROWS when it is
 * not set up, which is why the catch is there.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * -----------------------------------------------------------------------------
 * It does not revoke the trusted-device cookies, which `change-password.php`
 * does. That page is handling a password being REPLACED, often because somebody
 * fears their account is compromised. This is an addition the person asked for,
 * and signing them out of every device they trust would be a cost with nothing
 * behind it.
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/731
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\SelfServicePassword;
use Portal\Core\Site;

/**
 * 🔀 Leave a message for `add-password.php` to print, and go back there.
 *
 * Back THERE and not to the Account page: the Account page speaks in `?error=`
 * codes and reads no flash message, so a sentence left for it would simply
 * disappear (#276's named trap — a handler's message is swallowed by a page
 * that prints no flash block).
 */
$goBack = static function (string $message, string $type): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . Site::url('account/add-password'), true, 302);
    exit();
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('account/add-password'), true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $goBack('This form had been open too long, so it was not sent. Nothing was changed. Please try again.', 'danger');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🚦 The rule, the cheap way round. The same question is asked again below on
//    facts read inside the transaction; this one keeps the ordinary refusals
//    out of a transaction altogether.
$why = SelfServicePassword::whyNotAllowed(SelfServicePassword::factsFor($mysqli, $userId));
if ($why !== '') {
    $goBack($why, 'warning');
}

// 🧵 A form field is text or it is nothing. A browser sends text; a request
//    written by hand can send a LIST instead (`new_password[]=x`), and handing
//    a list to a function expecting text ends the page with an error 500
//    (#580's shape, and #642's: a request chooses the TYPE of what it sends as
//    well as the value). Anything that is not text is read as empty and
//    answered like any other empty value.
$asText  = static fn (mixed $value): string => is_string($value) === true ? $value : '';
$chosen  = $asText($_POST['new_password'] ?? '');
$again   = $asText($_POST['new_password_confirm'] ?? '');

if ($chosen === '' || $again === '') {
    $goBack('Please fill in both password boxes. Nothing was changed.', 'danger');
}
if ($chosen !== $again) {
    $goBack('The two passwords were not the same, so nothing was changed. Please try again.', 'danger');
}

$check = Auth::validatePassword($chosen);
if ($check['valid'] === false) {
    // 🗣️ The policy's own words, which say what is missing. They describe the
    //    RULES and never quote what was typed.
    $goBack('That password does not meet this portal\'s requirements: ' . implode(' ', $check['errors'])
        . ' Nothing was changed.', 'danger');
}

// =============================================================================
// 🔐 Store it
// =============================================================================
$mysqli->begin_transaction();
$stored = ['ok' => false, 'created' => false, 'why' => ''];
$email  = '';

try {
    // 🔒 The rule again, on facts read inside the transaction. The important
    //    one of the five is "does this account already have a usable
    //    password": two requests could otherwise both pass the cheap check
    //    above and both believe they were adding the first one.
    $facts = SelfServicePassword::factsFor($mysqli, $userId);
    $why   = SelfServicePassword::whyNotAllowed($facts);
    if ($why !== '') {
        $mysqli->rollback();
        $goBack($why, 'warning');
    }
    $email = (string) $facts['email'];

    // 📇 THE SIGN-IN NAME IS THE ACCOUNT'S OWN E-MAIL ADDRESS, as #586 does on
    //    the administrator's side. It is read from `tblUsers` and never from
    //    the session, which holds a copy made when the person signed in.
    //
    //    It is passed even when a sign-in row already exists with an unusable
    //    password, because `Auth::setPassword()` ignores the name in that case
    //    and updates the row it finds. Passing null would mean "refuse if there
    //    is no row", which is the opposite of what this page is for.
    $stored = Auth::setPassword($mysqli, $userId, $chosen, $email);
    if ($stored['ok'] === false) {
        $mysqli->rollback();
        // 🗣️ `why` is written for a person and names nobody — not the
        //    password, not the hash, and not whose sign-in name is already in
        //    use. So it is shown as it comes rather than replaced with
        //    something vaguer, which would leave somebody with a long e-mail
        //    address no idea why they were refused.
        $goBack($stored['why'], 'danger');
    }

    $mysqli->commit();
} catch (\Throwable $problem) {
    // 🛟 Roll back, then say so. The transaction must not escape open: with
    //    the lock in `Auth::setPassword()` real, a second request can genuinely
    //    wait, and a lock-wait timeout throws (#694 round 3).
    try {
        $mysqli->rollback();
    } catch (\Throwable $ignored) {
        // Nothing can be done about a rollback that fails; the connection
        // closing at the end of the request undoes the work anyway.
    }
    // 🙈 The fault's OWN WORDS are not recorded. A database refusal can quote
    //    the value it refused, and the value here is the person's sign-in name
    //    — their e-mail address. The code says where to look without putting
    //    an address in the error table.
    Logger::errorPlatform('Auth', 'Error', 'ADDPW_STORE_FAIL', 'A password could not be added to an account', '');
    $goBack('Something went wrong and no password was added. Nothing was changed. Please try again.', 'danger');
}

// =============================================================================
// ✅ Stored. Now spend the proof, say so, and tell the person.
// =============================================================================

// 🔑 SINGLE USE. Spent now rather than before the write, so that a mistyped
//    password or one the policy refuses does not cost a whole sign-in ceremony
//    — and spent at all, so one proof cannot add two things.
SelfServicePassword::spendProof();

// 🔄 A new way into the account is a change of credentials, so the session gets
//    a new identifier. See https://owasp.org/www-community/attacks/Session_fixation
session_regenerate_id(true);

// 📝 PasswordAdded, and NOT PasswordChanged. The second already exists and
//    means something else: somebody replaced a password they had. This record
//    says a sign-in method was ADDED, which is the thing an administrator
//    reading the log needs to be able to tell apart. It names no password and
//    no hash.
Logger::activity(
    'PasswordAdded',
    'A password sign-in was added to this account by its owner, after signing in again.',
    $userId
);

// 📧 Tell the account's own address. A stolen session must not be able to add a
//    sign-in method quietly, and this is the part that makes that true: the
//    person finds out even if they were not the one who did it.
$mailWentOut = false;
$mailWaiting = false;
try {
    $mailWentOut = Mailer::send(
        $email,
        'A password sign-in was added to your account',
        "Hello,\n\n"
        . "A password has just been added as a way of signing in to your account on "
        . Site::productName() . ".\n\n"
        . "Nothing else changed: you can still sign in the way you did before, and any passkey you "
        . "had still works.\n\n"
        . "If you did this, there is nothing to do.\n\n"
        . "If you did NOT do this, somebody else may have been signed in as you. Please tell whoever "
        . "looks after the portal for your organisation straight away.\n",
        [],
        // `bodyIsText` (#971): plain text naming the product, whose name is
        //    chosen at install and may hold a "<". Escaped once, footer kept.
        ['bodyIsText' => true]
    );
    $mailWaiting = Mailer::lastIsWaiting();
} catch (\Throwable $problem) {
    // 🛟 The mail route can throw when it is not set up — the Microsoft route
    //    always has. The password is already stored and committed, and the
    //    person is told below that the notice could not be sent, so there is
    //    nothing here to undo. The fault's own words are not recorded: a mail
    //    server's refusal quotes the recipient's address.
    Logger::errorPlatform('Mailer', 'Warning', 'ADDPW_NOTICE_FAIL', 'The "password added" notice could not be sent', '');
    $mailWentOut = false;
}

// 🗣️ One sentence per real outcome, spelled out whole rather than built from
//    branches in the middle of a clause (#694's lesson: four of six wording
//    faults in one package were ternaries inside a sentence). "Sent" is never
//    said about a message that was only KEPT: `Mailer::send()` answers true for
//    either, and `lastIsWaiting()` is the one thing that tells them apart.
if ($mailWentOut === true && $mailWaiting === false) {
    $told = 'We have e-mailed you to say so.';
} elseif ($mailWentOut === true) {
    $told = 'A message saying so is waiting in the portal\'s mail queue and will be sent shortly.';
} else {
    $told = 'We could not send you an e-mail to say so, so please tell whoever looks after the portal '
        . 'if you did not expect this.';
}

$goBack(
    'Your password is set, and you can now sign in with your e-mail address and that password. '
    . 'Everything you used before still works. ' . $told,
    'success'
);
