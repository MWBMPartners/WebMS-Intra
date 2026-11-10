<?php
// Path: _apps/auth/account/delete.php
/**
 * -----------------------------------------------------------------------------
 * Account — "Delete my account" form 🗑️
 * -----------------------------------------------------------------------------
 * Says plainly what asking for deletion does, then sends the typed
 * confirmation to /account/delete/confirm.
 *
 * That handler FILES AN ERASURE REQUEST. It does not delete anything itself.
 * Until 11 October 2026 it emptied the account on the spot, and this page
 * said so ("This action cannot be undone", "Delete my account permanently").
 * Every sentence below was rewritten that day to match what now happens: a
 * request, confirmed by the typed words (there is no email to click on this
 * route), then a review by an administrator.
 *
 * ADDRESSES: every redirect, link and form target here is built by
 * Router::url(), so it stays inside the organisation's own address. They
 * used to be written out ("/account/my-data"), which lands on the DEFAULT
 * organisation when organisations are told apart by the first part of the
 * address (roll-out package B2b, 12 October 2026; part of #536).
 * tools/audit-checks/check_site_aware_addresses.py holds this file to it.
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
use Portal\Core\Router;

Auth::ensureSession();
Auth::requireLogin();

$allowDelete = (App::settings('privacy.allowAccountDelete') ?? 'true') === 'true';
if ($allowDelete === false) {
    Router::renderError(403);
    return;
}

$pageTitle   = 'Delete my account';
$pageSection = 'auth';
$breadcrumbs = ['Dashboard' => Router::url(''), 'Account' => Router::url('account'), 'Delete' => ''];
$contactEmail = (string) (App::settings('privacy.contactEmail') ?? '');

// A message from /account/delete/confirm when the form could not be accepted
// (the words did not match, or the form had been open too long). This page
// never showed it before, so the form simply came back with no explanation.
$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Delete my account</h1>
    <a href="<?php echo htmlspecialchars(Router::url('account'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Account
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="alert alert-warning">
    <strong>This sends a request. It does not delete your account straight away.</strong>
    Once the request has been carried out, it cannot be undone.
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h5">What happens next</h2>
        <ol>
            <li>You type the words below. That is your confirmation: there is no email to wait for.</li>
            <li>We record your request. Nothing is erased at this point.</li>
            <li>An administrator reviews the request and carries it out, within one month.</li>
        </ol>
        <p class="small">
            Until the request is carried out your account works exactly as it does now, and you stay signed in.
            When it is carried out, your sign-in details are deleted and you will not be able to sign in again.
            You can see the request, and whether it is still waiting, on
            <a href="<?php echo htmlspecialchars(Router::url('account/my-data'), ENT_QUOTES, 'UTF-8'); ?>">My data</a>.
        </p>

        <h2 class="h5 mt-4">What is erased when the request is carried out</h2>
        <ul>
            <li>Your profile: name, email address, telephone number, address, photo and biography.</li>
            <li>Your sign-in details: password, any linked Microsoft 365 or Google account, passkeys, two-step codes and trusted devices.</li>
            <li>Records that are about you, such as your group memberships and saved payment methods.</li>
        </ul>

        <?php // 🗣️ #703 round 7: this said everything kept was kept "without your name", listing "notes
              //    you wrote about somebody else" and "gifts and expense claims" among them. Untrue: a
              //    pastoral visit keeps who made it, by name (D-016), and so do care cases opened
              //    (D-110) and children checked in or out (D-111); gifts kept for tax keep the donor's
              //    name until the keep-until date; an expense claim keeps the claimant's account
              //    number (D-113). Each kind below is checked against GdprEraser::catalogue(). ?>
        <h2 class="h5 mt-4">What is kept</h2>
        <p class="small">
            Records that other people rely on stay, with your name and account number taken off them:
            events you created, attendance you marked and tasks you were given.
        </p>
        <p class="small">
            Some records keep your account number, and some keep your name as well, where the law or the
            organisation's own duties require it:
        </p>
        <ul class="small">
            <li>If you worked or volunteered here, the pastoral visits you made (with their notes), the care
                cases you opened, the DBS (safeguarding) checks you recorded and the children you checked in
                or out keep your name and account number as the person who did it.</li>
            <li>Gifts kept for tax, and your tax declarations, stay with your name and home address kept beside
                them until this organisation's keep-until date. Your other gifts lose your name.</li>
            <li>Your expense claims, your own DBS checks, your giving statements and an approval step given to
                you by name keep your account number.</li>
        </ul>
        <p class="small">
            Your account itself is emptied, but through that number, and the names kept beside it, anyone who
            can read the database or a backup can match these records back to you. Records kept for security
            and as a history of changes can mention your account number too; the
            <a href="<?php echo htmlspecialchars(Router::url('privacy/policy'), ENT_QUOTES, 'UTF-8'); ?>">privacy notice</a>
            says what they are and how each is cleared.
            <a href="<?php echo htmlspecialchars(Router::url('account/erasure-request'), ENT_QUOTES, 'UTF-8'); ?>">Request data erasure</a> lists exactly what we hold about you
            and what would happen to each kind of record.
        </p>

        <h2 class="h5 mt-4">Need a copy of your data first?</h2>
        <p class="small mb-0">
            Use <a href="<?php echo htmlspecialchars(Router::url('account/data-export'), ENT_QUOTES, 'UTF-8'); ?>">Data Export</a> to download a copy of what we hold.
            <?php if ($contactEmail !== ''): ?>
                Questions? Email
                <a href="mailto:<?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>
                </a>.
            <?php endif; ?>
        </p>
    </div>
</div>

<form method="post" action="<?php echo htmlspecialchars(Router::url('account/delete/confirm'), ENT_QUOTES, 'UTF-8'); ?>"
      data-confirm="Send a request to delete your account? Typing the words is your confirmation: an administrator will then review the request and carry it out." data-confirm-destructive="true" data-confirm-confirm-label="Send the request">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="mb-3">
        <label for="confirm-phrase" class="form-label">
            To send the request, type <strong><code>DELETE MY ACCOUNT</code></strong> below:
        </label>
        <input type="text" name="confirm_phrase" id="confirm-phrase" class="form-control"
               pattern="DELETE MY ACCOUNT" required autocomplete="off" placeholder="DELETE MY ACCOUNT">
    </div>

    <button type="submit" class="btn btn-danger">
        <i class="fa-solid fa-trash me-1"></i> Ask for my account to be deleted
    </button>
    <a href="<?php echo htmlspecialchars(Router::url('account'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Cancel</a>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
