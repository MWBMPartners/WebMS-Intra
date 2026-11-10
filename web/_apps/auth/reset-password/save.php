<?php
// Path: apps/auth/reset-password/save.php
/**
 * -----------------------------------------------------------------------------
 * Reset Password – Save Handler 🔐
 * -----------------------------------------------------------------------------
 * Validates the reset token, enforces the password policy, hashes the new
 * password, updates tblLocalAccounts, and marks the token as consumed.
 * All other pending tokens for the same user are also invalidated.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
 * -----------------------------------------------------------------------------
 * @package    Portal\Auth
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.3.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\AddressFingerprint;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\RateLimiter;
use Portal\Core\Router;
use Portal\Core\Site;

// 🧵 A form field is text or it is nothing (#580). A request written by hand
//    can send a LIST where text is expected ("token[]=x"), which stopped this
//    page with an error 500. Anything that is not text is read as empty.
$text = static fn (mixed $value): string => is_string($value) === true ? $value : '';

// -----------------------------------------------------------------------------
// 1. 🛡️ Only accept POST
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('forgot-password'), true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 2. 🔒 Security checks
// -----------------------------------------------------------------------------

Auth::ensureSession();

// 🚦 This page's OWN limit: 10 tries to use a reset link from one address in
//    15 minutes (#580).
//
//    WHAT WAS WRONG. This used to ask RateLimiter::isBlocked(), the SIGN-IN
//    tally. Somebody blocked for typing the wrong password five times, with a
//    reset link already in their inbox, could open the form but not save it:
//    they were sent to "Forgot password" with no message and the password was
//    not changed. The link is the only way back in without an administrator,
//    and holding it already proves they can read the mailbox.
//
//    NOW it counts its own tries, through the limiter's general counter, the
//    same way "Forgot password" counts its requests (owner, Salem874,
//    12 October 2026). Every POST that gets this far is one try, whatever
//    comes of it: a link that is wrong, a password that does not meet the
//    rules, or a success. Ten leaves room for somebody struggling with the
//    password rules. A try refused here is not counted, so the wait ends 15
//    minutes after the first of the ten.
//
//    A successful reset does NOT clear a sign-in block on an ADDRESS. That
//    block belongs to the address, not to one person: clearing it would let
//    somebody with one account of their own wipe the tally for that address
//    and carry on guessing other people's passwords. So after a reset, a
//    person at a blocked address still has to wait out what is left of the
//    15 minutes; the sign-in page says how long.
//
//    It DOES clear the count kept for the account's own NAME (section 5).
$resetUseFamily = 'pwreset:use:';
// 🔒 SALTED (#712). This was a plain `hash('sha256', …)` of the address.
//    An address is 32 bits and this machine does 4.87 million SHA-256 a
//    second, so the whole IPv4 space takes about fifteen minutes - an
//    independent check reversed such a value in under a second. The salt
//    is the installation's own key, which is not in the database, so a
//    database copy on its own no longer tells anybody which addresses
//    the portal has seen. `AddressFingerprint::of()` is the one place.
$resetUseBucket = $resetUseFamily . AddressFingerprint::of(RateLimiter::clientIp());
$resetUseMax    = 10;
$resetUseWindow = 900; // 15 minutes, in seconds

if (RateLimiter::tooMany($resetUseBucket, $resetUseMax, $resetUseWindow) === true) {
    Logger::activity('PasswordResetBlocked', 'Rate-limited reset-password save');
    // Back to the form, which says how long to wait. The link has not been
    // used up. The refusal is about the address, so it says nothing about
    // whether the link is a real one.
    $waitMinutes = (int) ceil(RateLimiter::retryAfter($resetUseBucket, $resetUseMax, $resetUseWindow) / 60);
    // Only something shaped like a real link (64 characters, 0-9 and a-f) is
    // passed back. Anything else is dropped, so a made-up value of any length
    // cannot be bounced into the address.
    $rawToken  = $text($_POST['token'] ?? '');
    $backToken = preg_match('/^[0-9a-f]{64}\z/', $rawToken) === 1 ? $rawToken : '';
    header(
        'Location: ' . Router::url('reset-password') . '?token=' . $backToken . '&wait=' . max(1, $waitMinutes),
        true,
        302
    );
    exit();
}
RateLimiter::recordHit($resetUseBucket, $resetUseWindow, $resetUseFamily);

if (Auth::verifyCsrf($text($_POST['csrf_token'] ?? '')) === false) {
    Logger::activity('PasswordResetFailed', 'Invalid CSRF token on reset-password');
    header('Location: ' . Site::url('forgot-password'), true, 302);
    exit();
}

if (Captcha::verify($_POST, Captcha::FOR_PASSWORD_RESET) === false) {
    Logger::activity('PasswordResetFailed', 'Captcha failed on reset-password');
    header('Location: ' . Site::url('forgot-password'), true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 3. 🔑 Validate the reset token
// -----------------------------------------------------------------------------

$plaintextToken = $text($_POST['token'] ?? '');

if ($plaintextToken === '') {
    Logger::activity('PasswordResetFailed', 'Empty token on reset-password submit');
    header('Location: ' . Site::url('forgot-password'), true, 302);
    exit();
}

// 🔎 Auth::findResetLink() is the one place that decides whether a link can
//    be used (#580). This page used to carry its own copy of the test, which
//    compared the expiry on the page's own clock and never looked at whether
//    the account was still switched on. A link for an account switched off
//    since it was sent is now answered exactly like a link that never
//    existed.
try {
    $reset = Auth::findResetLink($mysqli, $plaintextToken);
} catch (\Throwable $ex) {
    Logger::errorPlatform('MySQL', 'Error', 'RESET_VALIDATE_FAIL', $ex->getMessage(), '');
    header('Location: ' . Site::url('forgot-password'), true, 302);
    exit();
}

if ($reset['state'] !== 'usable') {
    Logger::activity('PasswordResetFailed', 'Invalid or expired token used');
    // 🔀 Redirect back to the reset page with the (now-invalid) token so it shows the error
    header('Location: ' . Site::url('reset-password') . '?token=' . urlencode($plaintextToken), true, 302);
    exit();
}

$resetId = $reset['resetID'];
$userId  = $reset['userID'];

// -----------------------------------------------------------------------------
// 4. 📝 Validate the new password
// -----------------------------------------------------------------------------

$password        = $text($_POST['password'] ?? '');
$passwordConfirm = $text($_POST['password_confirm'] ?? '');

if ($password === '' || $passwordConfirm === '') {
    // 🔀 Redirect back with token to re-show the form
    header('Location: ' . Site::url('reset-password') . '?token=' . urlencode($plaintextToken), true, 302);
    exit();
}

if ($password !== $passwordConfirm) {
    Logger::activity('PasswordResetFailed', 'Password mismatch on reset');
    header('Location: ' . Site::url('reset-password') . '?token=' . urlencode($plaintextToken), true, 302);
    exit();
}

$validation = Auth::validatePassword($password);
if ($validation['valid'] === false) {
    Logger::activity('PasswordResetFailed', 'Password policy not met on reset');
    header('Location: ' . Site::url('reset-password') . '?token=' . urlencode($plaintextToken), true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 5. 🔐 Hash and update the password in tblLocalAccounts
// -----------------------------------------------------------------------------

$mysqli->begin_transaction();

try {
    // 🔑 ONE PLACE STORES A PASSWORD (#728).
    //
    //    What this gains is the READ-BACK. The old code ran
    //    `UPDATE tblLocalAccounts SET passwordHash = ? WHERE userID = ?` and
    //    never asked whether a row had been changed — and `affected_rows`
    //    could not have told it, because that answers 0 both for "no such row"
    //    and for "the hash was already that". So an account with no sign-in
    //    row would have had its token consumed, every other token
    //    invalidated, its trusted devices revoked and the person told the
    //    reset had worked, with nothing stored.
    //
    //    **Be exact about how reachable that was: narrowly.**
    //    `_apps/auth/forgot-password/save.php` joins `tblLocalAccounts` to
    //    issue the link at all, so the row would have to be deleted between
    //    the link being sent and being used. It is not a live leak; it is a
    //    guarantee that was claimed and not implemented, which is the shape
    //    this repository keeps finding.
    //
    //    No `$signName` is passed, so a missing row is a REFUSAL rather than a
    //    new account: whoever follows a reset link is not creating sign-in
    //    details, and inventing a name here would turn a failed reset into an
    //    account nobody asked for.
    $stored = Auth::setPassword($mysqli, $userId, $password);
    if ($stored['ok'] === false) {
        throw new \RuntimeException('The password could not be stored: ' . $stored['why']);
    }

    // ✅ Mark the token as used
    $consumeStmt = $mysqli->prepare(
        'UPDATE tblPasswordResets SET usedAt = NOW() WHERE resetID = ?'
    );
    if ($consumeStmt === false) {
        throw new \RuntimeException('DB prepare failed: ' . $mysqli->error);
    }
    $consumeStmt->bind_param('i', $resetId);
    $consumeStmt->execute();
    $consumeStmt->close();

    // 🧹 Invalidate all other pending tokens for this user
    $invalidateStmt = $mysqli->prepare(
        'UPDATE tblPasswordResets SET usedAt = NOW() WHERE userID = ? AND usedAt IS NULL'
    );
    if ($invalidateStmt !== false) {
        $invalidateStmt->bind_param('i', $userId);
        $invalidateStmt->execute();
        $invalidateStmt->close();
    }

    // 🔐 Revoke ALL trusted-device 2FA-bypass cookies for this user (#B4).
    // A forgotten-password reset is precisely the "I suspect my account is
    // compromised / I'm locked out" scenario — change-password.php already
    // does this for the logged-in change-password flow; the reset flow is
    // the higher-stakes twin of that and must not leave an attacker's
    // "trust this device" cookie valid after the credential is rotated.
    // Runs on the same $mysqli connection inside this transaction, so it
    // either commits or rolls back together with the hash update above.
    Auth::revokeAllTrustedDevices($userId);

    // 🚪 AND SIGN THE PERSON OUT OF EVERY OTHER BROWSER (#598 item 1, D-104).
    //    WHAT WAS WRONG: this page cancelled remembered devices and nothing
    //    else, so somebody already signed in as this person — the very worry
    //    that sends people here — stayed signed in after the reset. Now every
    //    session whose sign-in began before this moment is ended on its next
    //    request. If THIS browser is signed in as the same person, it stays.
    //    Inside the transaction, so it stands or falls with the password. It
    //    answers false only on a database still waiting for migration 270, and
    //    the reset is then still made: refusing the one way back in without an
    //    administrator would be worse. The error is recorded for the
    //    administrator by Auth::endOtherSessionsOf() itself.
    $othersSignedOut = Auth::endOtherSessionsOf($mysqli, $userId);

    $mysqli->commit();

    // 📝 Log the successful password reset
    Logger::activity(
        'PasswordResetCompleted',
        'Password reset completed for userID: ' . $userId
            . ($othersSignedOut === true
                ? '; their other browsers are signed out'
                : '; their other browsers could NOT be signed out (database upgrade waiting)')
    );

    // 🧹 Stop counting the failed sign-ins recorded so far against this
    //    account's NAME (#580; owner, Salem874, 12 October 2026).
    //
    //    WHAT WAS WRONG. A stranger sending ten wrong passwords for a name,
    //    from several addresses, blocked that name. Its owner reset the
    //    password and was still refused with the new one, and the stranger
    //    could keep that up for as long as they kept sending.
    //
    //    The person has just proved they can read the account's mailbox, so
    //    the name starts again from nothing. The count for an ADDRESS is not
    //    touched: see section 2. It runs after the commit, on its own, so a
    //    problem here cannot undo a password that has already been changed.
    try {
        $nameStmt = $mysqli->prepare('SELECT username FROM tblLocalAccounts WHERE userID = ? LIMIT 1');
        $nameStmt->bind_param('i', $userId);
        $nameStmt->execute();
        $nameRow = $nameStmt->get_result()->fetch_assoc();
        $nameStmt->close();
        if ($nameRow !== null) {
            RateLimiter::clearNameFailures((string) $nameRow['username']);
        }
    } catch (\Throwable $nameEx) {
        // The password IS changed. The worst case is that the name stays
        // blocked for what is left of its 15 minutes.
        Logger::errorPlatform('Auth', 'Warning', 'RESET_NAME_CLEAR_FAIL', $nameEx->getMessage(), '');
    }

} catch (\Throwable $ex) {
    $mysqli->rollback();
    Logger::errorPlatform('Auth', 'Error', 'RESET_UPDATE_FAIL', $ex->getMessage(), '');
    header('Location: ' . Site::url('reset-password') . '?token=' . urlencode($plaintextToken), true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 6. 🔀 Redirect to login with success message
// -----------------------------------------------------------------------------

header('Location: ' . Site::url('login') . '?reset=1', true, 302);
exit();
