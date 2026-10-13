<?php
// Path: apps/auth/forgot-password/save.php
/**
 * -----------------------------------------------------------------------------
 * Forgot Password – Save Handler 🔓
 * -----------------------------------------------------------------------------
 * Generates a cryptographically secure reset token, stores its SHA-256 hash in
 * tblPasswordResets, and emails the plaintext link to the user (when the Mailer
 * is configured).
 *
 * Security:
 *   - Always shows the same generic message to prevent email enumeration.
 *   - The answer is sent BEFORE the address is looked up (section 3), so how
 *     long it takes does not depend on whether the address has an account.
 *     The comment on $answerNow in section 2 says how, and on which hosts
 *     that cannot be promised.
 *   - The link is stored only as a fingerprint (SHA-256), so reading
 *     tblPasswordResets does not give anybody a link they can use. (This
 *     used to say "a DB breach never exposes valid tokens". That was untrue:
 *     the activity log kept whole links, in the address the browser said it
 *     came from. Logger::redactSensitiveHeaders() now cuts that off, #580.)
 *   - Existing unused tokens for the same user are invalidated.
 *   - A limit of its own: 5 requests from one address in 15 minutes (#580).
 *     It is separate from the sign-in limit on purpose, so that somebody
 *     blocked from signing in can still ask for a link. See section 2.
 *
 * WHAT THIS CANNOT DO
 *   - While the portal's own web address has NOT been set (Admin, Settings,
 *     Organisation), the link in the e-mail carries whatever host name the
 *     request named, made-up or not. Once it is set, the link carries the
 *     set address whatever the request says. See section 6 and
 *     web/_core/PortalAddress.php (#500).
 *   - On a host where the answer cannot be sent early, the time it takes
 *     still gives a known address away.
 *   - "Sent" on screen is not proof of delivery. Mailer::canSend() reads
 *     settings; only the provider knows whether a message arrived.
 *   - Activity records written before #580 may still hold a whole link, for
 *     the rest of that link's hour.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
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

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\PortalAddress;
use Portal\Core\RateLimiter;

// -----------------------------------------------------------------------------
// 1. 🛡️ Only accept POST
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /forgot-password', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 2. 🔒 Security checks (CSRF, captcha, rate limit)
// -----------------------------------------------------------------------------

Auth::ensureSession();

// 🧵 A form field is text or it is nothing (#580). A request written by hand
//    can send a LIST where text is expected ("email[]=x"), which stopped this
//    page with an error 500. Anything that is not text is read as empty, and
//    answered like any other address that is not valid.
$text = static fn (mixed $value): string => is_string($value) === true ? $value : '';

if (Auth::verifyCsrf($text($_POST['csrf_token'] ?? '')) === false) {
    Logger::activity('PasswordResetFailed', 'Invalid CSRF token on forgot-password');
    header('Location: /forgot-password', true, 302);
    exit();
}

if (Captcha::verify($_POST, Captcha::FOR_PASSWORD_RESET) === false) {
    Logger::activity('PasswordResetFailed', 'Captcha failed on forgot-password');
    header('Location: /forgot-password', true, 302);
    exit();
}

// 📮 ANSWER FIRST, WORK AFTERWARDS: the one function that answers this page.
//
// ⏱️ WHAT WAS WRONG (#580). The answer was sent last. For an address with an
//    account the page first stored a link and called the mail provider; for
//    any other address it did neither. The words were the same, the wait was
//    not: 86 ms against 45 ms with a stand-in mail service on the same
//    machine (12 October 2026), and far more through a real provider across
//    the internet. A comment here called that "timing-safe". It was not: the
//    wait told a stranger which addresses have accounts.
//
//    NOW every request that passes the form's own checks is answered by this
//    one function, in the same words, and the person's browser is let go.
//    Only then does the page look the address up, store a link and send the
//    e-mail. Nothing after that can change the answer or when it arrived. A
//    request over the limit is answered by the same function, so it cannot
//    be told from any other.
//
//    The session is saved and closed first. PHP lets one request at a time
//    use a session, so otherwise the page the browser goes to next would
//    wait for the e-mail to be sent, and its wait would give the same thing
//    away. Nothing below writes to the session.
//
//    HOW THE BROWSER IS LET GO, AND WHERE THAT CANNOT BE PROMISED
//    - fastcgi_finish_request() where PHP has it (PHP-FPM), or
//      litespeed_finish_request() on LiteSpeed: the answer is complete and
//      sent, and this script carries on unseen.
//    - Anywhere else: the answer is given its exact length (it has no body)
//      and the connection is marked to be closed, then pushed out. A browser
//      that has the whole answer does not wait for more.
//      ignore_user_abort() keeps the script going after the browser has left.
//      That is how PHP's built-in server, used by the self-tests, behaves.
//    - WHAT THIS CANNOT GUARANTEE: a web server that holds PHP's output back
//      until the script has ended. Apache running PHP through mod_fcgid or as
//      a CGI program can do that, and so can anything in front that
//      compresses or buffers. There the person still gets the same words,
//      but only after the e-mail has gone, so the wait still differs. A host
//      like that needs PHP-FPM to close the gap; nothing in this page can.
//
//    REJECTED: sleeping for a fixed or random time. It slows everybody down
//    and only blurs the difference; enough tries average it away.
$answerNow = static function (): void {
    header('Location: /forgot-password?sent=1', true, 302);
    session_write_close();
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request') === true) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request') === true) {
        litespeed_finish_request();
    } else {
        header('Content-Length: 0');
        header('Connection: close');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
};

// 🚦 This page's OWN limit: 5 requests from one address in 15 minutes (#580).
//
//    WHAT WAS WRONG. This used to ask RateLimiter::isBlocked(), which is the
//    SIGN-IN tally: five failed sign-ins from this address in 15 minutes. That
//    was wrong in both directions.
//      - Somebody who had just been blocked for typing the wrong password was
//        told "sent" here and sent nothing. "Forgot password" is the only way
//        back in without an administrator, and it was closed to exactly the
//        people who needed it.
//      - Somebody who only ever asked for reset links never added to that
//        tally, so nothing slowed a flood of reset e-mails to one mailbox.
//
//    NOW it counts its own requests, through the limiter's general counter
//    (the one the two-step code page uses). A reset link is no use to somebody
//    who cannot read the mailbox, so letting a blocked person ask for one
//    gives a password-guesser nothing. Decided by the owner (Salem874) on
//    12 October 2026.
//
//    Every request that gets this far is counted, whether or not the address
//    turns out to belong to anybody: counting only real accounts would let the
//    limit itself say which addresses exist. A request refused here is NOT
//    counted, so the wait ends 15 minutes after the first of the five and
//    asking again does not make it longer.
//
//    The address is stored as a fingerprint (SHA-256), as the public forms do.
//    The third argument lets the counter clear out old rows for every address,
//    not only this one; every bucket with this prefix uses the same window.
$resetAskFamily = 'pwreset:ask:';
$resetAskBucket = $resetAskFamily . hash('sha256', RateLimiter::clientIp());
$resetAskMax    = 5;
$resetAskWindow = 900; // 15 minutes, in seconds

if (RateLimiter::tooMany($resetAskBucket, $resetAskMax, $resetAskWindow) === true) {
    // 🛡️ The same answer as always, sent the same way (section 3), so that
    //    being over the limit cannot be told from an ordinary request.
    $answerNow();
    Logger::activity('PasswordResetBlocked', 'Rate-limited forgot-password request');
    exit();
}
RateLimiter::recordHit($resetAskBucket, $resetAskWindow, $resetAskFamily);

// -----------------------------------------------------------------------------
// 3. 📮 Answer the browser NOW, before anything is looked up
// -----------------------------------------------------------------------------

$email = strtolower(trim($text($_POST['email'] ?? '')));

// Every request that gets this far is answered here, whatever the address
// turns out to be. $answerNow is written out above the limit in section 2,
// with the reasons.
$answerNow();

// =============================================================================
// From here on the browser has its answer. Do NOT send a header or print
// anything below: it would go nowhere, and PHP would log a warning.
// =============================================================================

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    // 🛡️ Not an e-mail address: nothing to look up.
    exit();
}

try {
    // -------------------------------------------------------------------------
    // 4. 📧 Look up user by email (must have a local account, switched on)
    // -------------------------------------------------------------------------
    $stmt = $mysqli->prepare(
        'SELECT U.userID, U.fullName, U.emailAddress '
        . 'FROM tblUsers U '
        . 'JOIN tblLocalAccounts LA ON LA.userID = U.userID '
        . 'WHERE U.emailAddress = ? AND U.isActive = 1 '
        . 'LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 🛡️ No such account, or one that is switched off: nothing is stored and
    //    nothing is sent. The person has already been given the usual answer.
    if ($user === null) {
        Logger::activity('PasswordResetNoUser', 'Reset requested for unknown email');
        exit();
    }

    // -------------------------------------------------------------------------
    // 5. 🔑 Generate token and store its fingerprint in tblPasswordResets
    // -------------------------------------------------------------------------
    $userId = (int) $user['userID'];

    // 🧹 Invalidate any existing unused tokens for this user
    $invalidateStmt = $mysqli->prepare(
        'UPDATE tblPasswordResets SET usedAt = NOW() WHERE userID = ? AND usedAt IS NULL'
    );
    $invalidateStmt->bind_param('i', $userId);
    $invalidateStmt->execute();
    $invalidateStmt->close();

    // 🔐 Generate a cryptographically secure plaintext token (64-char hex)
    $plaintextToken = bin2hex(random_bytes(32));

    // 🕐 How long the link is good for (a setting; 60 minutes unless changed).
    //    The expiry itself is worked out by the database, in UTC: see
    //    Auth::storeResetLink() for why it is no longer worked out here.
    $expiryMinutes = (int) (App::settings('auth.passwordReset.tokenExpiry') ?? '60');

    // 🌐 The requester's address, for the audit record.
    // 🛑 This used to read the Cloudflare and X-Forwarded-For headers directly and
    //    believe them. Anybody can send those headers, so anybody could claim any
    //    address they liked. RateLimiter::clientIp() is now the ONE place in the
    //    portal that decides which address to believe: it trusts those headers only
    //    when the request really arrived through a proxy listed in the
    //    portal.trustedProxies setting, and otherwise uses the address the
    //    connection actually came from. Delegating means a fix to that rule reaches
    //    this code too, instead of this copy quietly drifting out of step - which is
    //    exactly how it went wrong. Here the address is written into the
    //    password-reset request's audit record, a record that is only worth keeping
    //    if it is true.
    Auth::storeResetLink($mysqli, $userId, $plaintextToken, $expiryMinutes, RateLimiter::clientIp());

    Logger::activity('PasswordResetRequested', 'Password reset token created for: ' . $email);
} catch (\Throwable $ex) {
    // 📝 The database refused. The person already has the usual answer; the
    //    administrator gets the error.
    Logger::errorPlatform('MySQL', 'Error', 'RESET_STORE_FAIL', $ex->getMessage(), '');
    exit();
}

// -----------------------------------------------------------------------------
// 6. 📨 Send reset email (if a mail route is set up)
// -----------------------------------------------------------------------------

// 🔗 The reset link. PortalAddress is the ONE place that decides the address
//    in a link the portal sends out (#500).
//
//    WHAT WAS WRONG. The link was built from the request's own Host header,
//    which the visitor chooses. Anybody could ask for a reset for somebody
//    else's e-mail address with a made-up host name, and the portal mailed
//    that person a WORKING link pointing at the made-up host (shown by a
//    check on 12 October 2026). Whoever ran that host only had to wait for
//    the click to have the link.
//
//    NOW the link carries the address an administrator set (the `site.url`
//    setting), whatever the request says. Only while none is set does it
//    still use the request's host name; the admin dashboard asks for the
//    address to be set until it is. Do not build this link from $_SERVER
//    again.
$resetLink = PortalAddress::absolute('reset-password?token=' . urlencode($plaintextToken));

// 🧭 Mailer::canSend() is the one place that knows whether mail can go out.
//
//    WHAT WAS WRONG (#580). This page used to test for a Microsoft 365 client
//    ID and a "from" address itself. An organisation that sends through Google
//    has neither, so it was told "sent" and nothing was sent, although the
//    Google route worked. Do not put a test of a provider's settings back
//    here: a new mail route is taught to Mailer, and this page then follows.
if ($resetLink === '') {
    // 📝 No address to give: none is set and the request named no usable
    //    host, or the setting could not be read. A link to nowhere is worse
    //    than no e-mail, so nothing is sent. The person has had the usual
    //    answer; the administrator gets the reason. (The link stored in
    //    section 5 is simply never used and runs out by itself.)
    Logger::errorPlatform(
        'Mailer',
        'Error',
        'RESET_NO_ADDRESS',
        'A password reset e-mail was not sent, because the portal does not know its own web address. Set it at Admin, Settings, Organisation.',
        ''
    );
    Logger::activity('PasswordResetMailFailed', 'Reset email not sent (the portal\'s web address is not known) for: ' . $email);
} elseif (Mailer::canSend() === true) {
    $siteName = App::settings('site.name') ?? 'Portal';
    $userName = htmlspecialchars($user['fullName'] ?? 'User', ENT_QUOTES, 'UTF-8');

    $htmlBody = '<!DOCTYPE html><html><body style="font-family:system-ui,sans-serif;color:#333;">'
        . '<h2 style="color:#0d6efd;">Password Reset Request</h2>'
        . '<p>Hi ' . $userName . ',</p>'
        . '<p>We received a request to reset your password for your '
        . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . ' account.</p>'
        . '<p style="margin:24px 0;">'
        . '<a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" '
        . 'style="background:#198754;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;">'
        . 'Reset My Password</a></p>'
        . '<p>Or copy and paste this link into your browser:</p>'
        . '<p style="word-break:break-all;color:#6c757d;font-size:0.875rem;">'
        . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="color:#6c757d;font-size:0.875rem;">This link expires in '
        . $expiryMinutes . ' minutes. If you did not request this, you can safely ignore this email.</p>'
        . '<hr style="border:none;border-top:1px solid #dee2e6;margin:24px 0;">'
        . '<p style="color:#999;font-size:0.75rem;">'
        . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</body></html>';

    try {
        $mailSent = Mailer::send(
            [$user['emailAddress']],
            'Password Reset Request – ' . $siteName,
            $htmlBody
        );
        // 📝 Only say "sent" when the provider took the message. This used to
        //    write 'PasswordResetEmailed' whatever send() answered, so the
        //    record said an e-mail had gone when the provider had refused it.
        if ($mailSent === true) {
            Logger::activity('PasswordResetEmailed', 'Reset email sent to: ' . $email);
        } else {
            Logger::activity('PasswordResetMailFailed', 'Reset email was refused by the mail provider for: ' . $email);
        }
    } catch (\Throwable $ex) {
        // 📝 Log the error but don't expose it to the user
        Logger::errorPlatform('Mailer', 'Error', 'RESET_MAIL_FAIL', $ex->getMessage(), '');
    }
} else {
    // 📝 No mail route is set up, so nothing can be sent and an administrator
    //    must reset the password by hand. The person asking is told the same as
    //    always. The health page counts these records, so the administrator
    //    learns that people are asking and getting nothing.
    Logger::activity('PasswordResetNoMailer', 'Reset requested but mailer not configured for: ' . $email);
}

// The browser was answered in section 3; there is nothing left to send.
exit();
