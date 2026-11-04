<?php
// Path: _apps/auth/account/add-password.php
/**
 * -----------------------------------------------------------------------------
 * Account — "Add a password to my account" 🔑 (#731)
 * -----------------------------------------------------------------------------
 * For a member whose account signs in ONLY through Microsoft, Google or a
 * passkey. Such an account has no password at all, and until now there was no
 * route to one: an administrator may not type one for somebody else (the
 * owner's decision, D-018 / #586), "forgot password" finds nothing because it
 * joins the sign-in table, and "change password" changes a password that is not
 * there. So losing the Microsoft or Google account, or the only device holding
 * the passkey, locked the person out for good.
 *
 * WHAT THIS PAGE IS, AND WHAT IT IS NOT
 * -----------------------------------------------------------------------------
 * It is an ADDITION. Nothing is taken away: the Microsoft or Google link stays,
 * every passkey stays, and the person keeps signing in whichever way they
 * prefer. After this they have one more way in, not a different one.
 *
 * It decides nothing itself. `Portal\Core\SelfServicePassword` is the one place
 * that decides whether somebody may add a password, and this page and
 * `add-password-save.php` both ask it, so the form and the handler cannot
 * disagree (the `ExpenseRules::whyNotEditable()` pattern, #647).
 *
 * THE THREE THINGS IT DRAWS
 * -----------------------------------------------------------------------------
 *   1. The form, when the rule says yes.
 *   2. "Prove it is you", when the only thing missing is a fresh sign-in. The
 *      buttons offered are the methods the ACCOUNT really has — never an
 *      e-mail link, because the e-mail address may BE the Microsoft or Google
 *      account, so a link sent there would prove nothing about the person
 *      sitting at the keyboard.
 *   3. The reason, when the rule says no for any other reason.
 *
 * It tells those three apart by comparing the rule's answer with
 * `SelfServicePassword::MSG_NEEDS_PROOF`. That is NOT a second decision: the
 * page recognises the answer it was given, and never works one out.
 *
 * WHY THE PASSKEY STEP HAS ITS OWN SCRIPT
 * -----------------------------------------------------------------------------
 * The browser part of a passkey sign-in — asking the authenticator and posting
 * the answer — has no shared file in this repository; the sign-in page and the
 * Account page each hold their own copy. This is a third copy of that
 * PLUMBING, and deliberately not a third copy of the CEREMONY: the assertion
 * is verified by `_apps/auth/login/webauthn.php`, which is the one place that
 * does it, and which is also where the proof is recorded. Pulling the plumbing
 * out into one script file would mean editing the sign-in page and the Account
 * page as well, and is written up as its own piece of work rather than done
 * here.
 *
 * WHY IT CANNOT SEND SOMEBODY TO THE SIGN-IN PAGE TO PROVE THEMSELVES
 * -----------------------------------------------------------------------------
 * `_apps/auth/login/index.php` redirects away immediately when
 * `Auth::check()` is true, so a signed-in person sent there bounces straight
 * back here having proved nothing. That is why the methods are offered from
 * this page instead.
 *
 * WHAT THIS PAGE CANNOT DO
 * -----------------------------------------------------------------------------
 *   - It cannot tell whether the person at the keyboard is the account's owner.
 *     Nothing can. What it can insist on is a fresh use of something the owner
 *     holds, which is what the proof is, and that the window is short.
 *   - It runs no check of its own on the password. The portal's policy is
 *     applied by the handler, through `Auth::validatePassword()`, because
 *     anything can be posted and a rule in a form is a courtesy.
 *   - It does not promise the notification e-mail will arrive. The handler says
 *     what really happened to it.
 *
 * ADDRESSES: every link and form target is built by `Site::url()` (#536), so it
 * stays inside the organisation's own address, and the one inside the script is
 * built by `Router::urlForScript()`.
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

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Mailer;
use Portal\Core\Router;
use Portal\Core\SelfServicePassword;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);

// 📋 The facts, then the rule. In that order and never the other way round:
//    the rule is the only thing that judges.
$facts  = SelfServicePassword::factsFor($mysqli, $userId);
$why    = SelfServicePassword::whyNotAllowed($facts);
$allow  = $why === '';
$needsProof = $why === SelfServicePassword::MSG_NEEDS_PROOF;
$methods    = SelfServicePassword::methodsToProveWith($facts);
$policy     = Auth::passwordPolicy();

// 📨 What the handler left for this page to say. This page prints its own
//    flash block, because nothing in this portal prints one centrally — 141
//    pages print their own, and a handler's message is simply swallowed by a
//    page that does not (#276's named trap). The Account page itself speaks in
//    `?error=` codes rather than flash messages, which is why the handler sends
//    the person back HERE and not there.
$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 🔙 Where a sign-in should bring them back to. Written only when a sign-in is
//    what is being asked for, so that merely reading any other state of this
//    page leaves nothing behind.
//
//    `login_redirect` is the session key this portal already uses for "where to
//    go back to after signing in"; `Auth::comeBackAfterSignIn()` is the one
//    place it is taken out again, and it is TAKEN rather than read, so an
//    abandoned attempt cannot send a later sign-in here.
if ($needsProof === true) {
    $_SESSION['login_redirect'] = Site::url('account/add-password');
}

$pageTitle   = 'Add a password';
$pageSection = 'auth';
$pageDescription = 'Give your account a password, so you can still sign in if you lose your other way in.';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Account' => Site::url('account'), 'Add a password' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-key me-2" aria-hidden="true"></i>Add a password</h1>
    <a href="<?php echo htmlspecialchars(Site::url('account'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back to Account
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>" role="alert">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="card portal-card">
            <div class="card-body">

                <p>
                    Your account signs in with
                    <?php
                    // 🏷️ What they have today, in ordinary words. Said out loud
                    //    because the whole point of the page is "a second way
                    //    in", and somebody should be able to see what the first
                    //    one is before they add to it.
                    echo $methods === []
                        ? 'no method this page can offer'
                        : htmlspecialchars(implode(' and ', $methods), ENT_QUOTES, 'UTF-8');
                    ?>.
                    Adding a password gives you one more way in, and changes nothing about the way you sign in now.
                </p>
                <p class="small text-muted">
                    It is worth doing if you would otherwise be stuck: losing the Microsoft or Google account,
                    or the only device your passkey is on, would leave nobody able to let you back in — an
                    administrator cannot set a password for you.
                </p>

                <?php if ($allow === false && $needsProof === false): ?>
                    <div class="alert alert-warning mb-0" role="alert">
                        <?php echo htmlspecialchars($why, ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($why === SelfServicePassword::MSG_ALREADY_HAS_ONE): ?>
                            <div class="mt-2">
                                <a href="<?php echo htmlspecialchars(Site::url('account'), ENT_QUOTES, 'UTF-8'); ?>">Go to your Account page</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($needsProof === true): ?>
                    <hr>
                    <h2 class="h5">First, prove it is you</h2>
                    <p>
                        <?php echo htmlspecialchars(SelfServicePassword::MSG_NEEDS_PROOF, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="small text-muted">
                        You have five minutes after signing in to finish adding the password. We do not send
                        a link by e-mail for this, because your e-mail address may be the very account you
                        are signing in with.
                    </p>

                    <?php if (in_array(SelfServicePassword::METHOD_MICROSOFT, $methods, true) === true): ?>
                        <a href="<?php echo htmlspecialchars(Site::url('login/ms365'), ENT_QUOTES, 'UTF-8'); ?>"
                           class="btn btn-outline-primary w-100 mb-2">
                            <i class="fa-brands fa-microsoft me-1" aria-hidden="true"></i> Sign in again with Microsoft
                        </a>
                    <?php endif; ?>

                    <?php if (in_array(SelfServicePassword::METHOD_GOOGLE, $methods, true) === true): ?>
                        <a href="<?php echo htmlspecialchars(Site::url('login/google'), ENT_QUOTES, 'UTF-8'); ?>"
                           class="btn btn-outline-danger w-100 mb-2">
                            <i class="fa-brands fa-google me-1" aria-hidden="true"></i> Sign in again with Google
                        </a>
                    <?php endif; ?>

                    <?php if (in_array(SelfServicePassword::METHOD_PASSKEY, $methods, true) === true): ?>
                        <div id="addPasswordPasskeyBlock" class="d-none">
                            <button type="button" class="btn btn-outline-secondary w-100" id="addPasswordPasskeyButton">
                                <i class="fa-solid fa-fingerprint me-1" aria-hidden="true"></i> Use my passkey
                            </button>
                            <div id="addPasswordPasskeyStatus" class="small mt-2 text-center" role="status" aria-live="polite"></div>
                        </div>
                        <noscript>
                            <div class="alert alert-secondary small mb-0" role="alert">
                                A passkey needs JavaScript switched on to work. If you cannot switch it on, sign in
                                again with Microsoft or Google instead, where your account has one.
                            </div>
                        </noscript>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($allow === true): ?>
                    <hr>
                    <h2 class="h5">Choose a password</h2>
                    <p class="small text-muted">
                        You proved it was you with
                        <?php echo htmlspecialchars(SelfServicePassword::proofMethod($userId), ENT_QUOTES, 'UTF-8'); ?>.
                        Your sign-in name will be your e-mail address,
                        <strong><?php echo htmlspecialchars($facts['email'], ENT_QUOTES, 'UTF-8'); ?></strong>.
                    </p>

                    <form method="post" action="<?php echo htmlspecialchars(Site::url('account/add-password-save'), ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label for="new_password" class="form-label">New password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password"
                                   autocomplete="new-password" required
                                   minlength="<?php echo (int) $policy['minLength']; ?>"
                                   maxlength="<?php echo (int) $policy['maxLength']; ?>"
                                   data-portal-password-input>
                            <div class="portal-password-strength mt-2" data-portal-password-meter hidden>
                                <div class="progress" style="height:6px;">
                                    <div class="progress-bar" role="progressbar" style="width:0%"></div>
                                </div>
                                <small class="form-text" data-portal-password-meter-label>Password strength</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="new_password_confirm" class="form-label">Type it again</label>
                            <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm"
                                   autocomplete="new-password" required
                                   minlength="<?php echo (int) $policy['minLength']; ?>"
                                   maxlength="<?php echo (int) $policy['maxLength']; ?>">
                        </div>

                        <div class="small text-muted mb-3">
                            <strong>Password requirements:</strong>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($policy['rules'] as $rule): ?>
                                    <li><?php echo htmlspecialchars($rule, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <button type="submit" class="btn btn-success">
                            <i class="fa-solid fa-key me-1" aria-hidden="true"></i> Add this password
                        </button>
                        <a href="<?php echo htmlspecialchars(Site::url('account'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card portal-card">
            <div class="card-header">
                <h2 class="h6 card-title mb-0"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i> What happens next</h2>
            </div>
            <div class="card-body small">
                <?php
                // 📧 CHECK A PAGE'S CLAIM AGAINST THE FALLBACK, NOT THE HAPPY
                //    PATH (#671). The first version of this card promised the
                //    e-mail unconditionally, and on an installation whose mail
                //    route is not set up that sentence is simply untrue — and
                //    it is the sentence a member reads BEFORE deciding to add
                //    a second way into their account, so it is exactly the
                //    place an overstated guarantee does harm. `Mailer::canSend()`
                //    is the one place that answers whether a route can send;
                //    this page never tests a provider's settings itself.
                $canTellThem = Mailer::canSend();
                ?>
                <ul class="mb-0 ps-3">
                    <li>Nothing you already use is removed or changed.</li>
                    <?php if ($canTellThem === true): ?>
                        <li>We e-mail you to say a sign-in method was added, so that nobody can add one quietly.</li>
                    <?php else: ?>
                        <li>
                            This portal cannot send e-mail at the moment, so <strong>nobody will be e-mailed</strong>
                            about this. On a portal that can, the account's own address is told, so that nobody can
                            add a sign-in method quietly.
                        </li>
                    <?php endif; ?>
                    <li>You can change the password afterwards from your Account page.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($needsProof === true && in_array(SelfServicePassword::METHOD_PASSKEY, $methods, true) === true): ?>
<?php
// 🔐 The passkey step.
//
//    THE NONCE IS NOT DECORATION (#625). Without the answer's own nonce the
//    browser REFUSES this script and logs nothing, so the button would simply
//    do nothing and the page would look finished.
//
//    It talks to `login/webauthn`, the ONE place a passkey assertion is
//    verified, and follows the address that endpoint answers with — which is
//    the address posted below, after that endpoint has judged it safe. For an
//    account with two-factor authentication the endpoint answers with the
//    two-factor page instead, and the come-back address survives that step
//    (`Auth::comeBackAfterSignIn()`).
?>
<script nonce="<?php echo htmlspecialchars(App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
(function () {
    'use strict';

    if (!window.PublicKeyCredential) { return; }

    var block  = document.getElementById('addPasswordPasskeyBlock');
    var button = document.getElementById('addPasswordPasskeyButton');
    var status = document.getElementById('addPasswordPasskeyStatus');
    if (!block || !button || !status) { return; }

    block.classList.remove('d-none');

    function toBuffer(value) {
        var base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4 !== 0) { base64 += '='; }
        var binary = atob(base64);
        var bytes  = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) { bytes[i] = binary.charCodeAt(i); }
        return bytes.buffer;
    }

    function toBase64Url(buffer) {
        var bytes  = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.length; i++) { binary += String.fromCharCode(bytes[i]); }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    function say(text, kind) {
        status.textContent = '';
        var span = document.createElement('span');
        if (kind) { span.className = kind; }
        span.textContent = text;
        status.appendChild(span);
    }

    button.addEventListener('click', async function () {
        // 🖱️ ONE request at a time. Pressed twice, the second ceremony would
        //    start while the first was still waiting for the authenticator, and
        //    the person would see whichever answer came back last.
        button.disabled = true;
        say('Asking your device…');

        try {
            var optionsAnswer = await fetch('<?php echo Router::urlForScript('login/webauthn'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'auth_options' })
            });
            var optionsData = await optionsAnswer.json();
            if (!optionsData.success) { throw new Error(optionsData.error || 'Your passkey could not be asked for just now.'); }

            var options = optionsData.options;
            options.challenge = toBuffer(options.challenge);
            if (options.allowCredentials) {
                options.allowCredentials = options.allowCredentials.map(function (one) {
                    one.id = toBuffer(one.id);
                    return one;
                });
            }

            say('Waiting for your device…');
            var assertion = await navigator.credentials.get({ publicKey: options });

            say('Checking…');
            var verifyAnswer = await fetch('<?php echo Router::urlForScript('login/webauthn'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'auth_verify',
                    redirect: '<?php echo Router::urlForScript('account/add-password'); ?>',
                    credential: {
                        id:    assertion.id,
                        rawId: toBase64Url(assertion.rawId),
                        type:  assertion.type,
                        response: {
                            clientDataJSON:    toBase64Url(assertion.response.clientDataJSON),
                            authenticatorData: toBase64Url(assertion.response.authenticatorData),
                            signature:         toBase64Url(assertion.response.signature),
                            userHandle:        assertion.response.userHandle ? toBase64Url(assertion.response.userHandle) : null
                        }
                    }
                })
            });
            var verifyData = await verifyAnswer.json();
            if (!verifyData.success) { throw new Error(verifyData.error || 'That did not work. Please try again.'); }

            window.location.href = verifyData.redirect || '<?php echo Router::urlForScript('account/add-password'); ?>';
        } catch (problem) {
            // 🗣️ Say only what is known. "Cancelled" is the browser's own
            //    NotAllowedError, which also covers a time-out, so the wording
            //    does not claim the person pressed cancel.
            if (problem && problem.name === 'NotAllowedError') {
                say('Nothing was sent: your device did not answer. You can try again.', 'text-warning');
            } else {
                say(problem && problem.message ? problem.message : 'That did not work. Please try again.', 'text-danger');
            }
            button.disabled = false;
        }
    });
})();
</script>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
