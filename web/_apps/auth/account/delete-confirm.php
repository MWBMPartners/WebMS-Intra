<?php
// Path: _apps/auth/account/delete-confirm.php
/**
 * -----------------------------------------------------------------------------
 * Account — "Delete my account": files an erasure request 🗑️
 * -----------------------------------------------------------------------------
 * POST-only. Checks the typed confirmation, then FILES AN ORDINARY ERASURE
 * REQUEST for the signed-in person and tells them what happens next.
 *
 * IT DOES NOT EMPTY THE ACCOUNT. Nothing is erased by this page.
 *
 * 🛑 WHAT THIS PAGE USED TO DO, so that nobody puts it back.
 *    Until 11 October 2026 it emptied the account itself, on the spot, with
 *    its own short list of statements, and signed the person out. That was a
 *    second way to erase somebody, and it went round everything the real
 *    erasure does:
 *      - it never called the erasure engine (Portal\Core\GdprEraser), so it
 *        cleared about a dozen columns and five tables and left the person's
 *        number on everything else the engine deals with;
 *      - no administrator saw it, and no audit trail was written;
 *      - it kept nothing the organisation is obliged to keep, such as the
 *        name behind a tax declaration, and started no keep-until date;
 *      - it used a different made-up name and address from the engine's, so
 *        a later, proper erasure of the same account did not recognise them.
 *    Salem874 decided that day that there is ONE ROUTE ONLY: this page files
 *    a request exactly as "Request data erasure" (/account/erasure-request)
 *    does, through the same function, and an administrator's run of that
 *    request is the only thing that ever empties an account.
 *
 * One difference from the old page that people may notice: leadership-only
 * prayer requests used to be deleted here. The erasure engine keeps the text
 * of every prayer request and removes the name, email address and sender
 * address from it.
 *
 * THE TYPED PHRASE IS THE CONFIRMATION (Salem874, 11 October 2026). The
 * person is signed in and has typed DELETE MY ACCOUNT, so the request is filed
 * as already confirmed: it goes straight to "pending review", and no
 * confirmation email is sent. (The first version of this rewrite filed it
 * unconfirmed and emailed a link, like the other page. That asked somebody
 * who had just typed the words to confirm a second time, and left them stuck
 * whenever the email could not be sent.) "Request data erasure" has no typed
 * phrase and keeps its email step.
 *
 * What this page cannot do: carry the request out. An administrator does
 * that, and the page says so.
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GdprEraser;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /account/delete', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if ((App::settings('privacy.allowAccountDelete') ?? 'true') !== 'true') {
    Router::renderError(403);
    return;
}

// 🔁 Already asked? Looked at BEFORE the form token, on purpose. A double-click
//    sends the form twice. The first press files the request and uses the
//    token up, so the second fails the token test below, and this page used to
//    answer it with "Nothing was changed. Please try again." - untrue, a
//    moment after the request had been filed, and an invitation to file
//    another. Now a second press, however it arrives, is told the truth and
//    files nothing. Nothing is changed by looking: it reads the signed-in
//    person's own request and no more.
$alreadyAsked = GdprEraser::openRequestFor(Site::id(), (int) ($_SESSION['user_id'] ?? 0));
if ($alreadyAsked !== null) {
    $_SESSION['flash_msg']  = 'Your request is already recorded: erasure request #' . $alreadyAsked . ' is with the '
        . 'administrators. Nothing more was sent just now, and nothing has been erased yet. Until the request is '
        . 'carried out your account works exactly as it does now.';
    $_SESSION['flash_type'] = 'info';
    header('Location: /account/my-data', true, 302);
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'This form had been open too long, so it was not sent. Nothing was changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /account/delete', true, 302);
    exit();
}

if (trim((string) ($_POST['confirm_phrase'] ?? '')) !== 'DELETE MY ACCOUNT') {
    $_SESSION['flash_msg']  = 'The words you typed did not match DELETE MY ACCOUNT, so nothing was sent. Nothing was changed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /account/delete', true, 302);
    exit();
}

$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);
if ($userId <= 0) {
    header('Location: /account/delete', true, 302);
    exit();
}

// 🛡️ A root administrator cannot ask for their own account to be deleted from
//    here: carried out, it would leave the installation with nobody able to
//    look after it. Kept from the old page unchanged. They can ask another
//    root administrator, or step down first.
if ((string) ($user['isRootAdmin'] ?? '0') === '1') {
    $_SESSION['flash_msg']  = 'Root admins cannot delete their own account. Demote yourself first or ask another root admin.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /account/delete', true, 302);
    exit();
}

// 📨 File the request: the same function and the same kind of request as
//    "Request data erasure". The `true` says it is already confirmed, which
//    is only right because this page has just checked the typed phrase a few
//    lines up. Nothing else happens here.
try {
    $filed = GdprEraser::fileRequest(Site::id(), $userId, true);
} catch (\Throwable $ex) {
    Logger::exception($ex);
    $_SESSION['flash_msg']  = 'Your request could not be recorded, and nothing was changed. Please try again; if it '
        . 'keeps happening, ask an administrator for help.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /account/delete', true, 302);
    exit();
}

Logger::activity(
    'GdprErasureRequested',
    'Erasure request #' . $filed['requestID'] . ' filed from "Delete my account", confirmed by the typed phrase',
    $userId
);

// The person stays signed in: their account has not been touched. They land
// on "My data", which shows the message and lists the request as waiting.
$next = GdprEraser::whatHappensNext($filed);
$_SESSION['flash_msg']  = $next['message'];
$_SESSION['flash_type'] = $next['type'];
header('Location: /account/my-data', true, 302);
exit();
