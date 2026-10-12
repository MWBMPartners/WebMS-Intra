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
$breadcrumbs = ['Dashboard' => '/', 'Account' => '/account', 'Delete' => ''];
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
    <a href="/account" class="btn btn-outline-secondary">
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
            <a href="/account/my-data">My data</a>.
        </p>

        <h2 class="h5 mt-4">What is erased when the request is carried out</h2>
        <ul>
            <li>Your profile: name, email address, telephone number, address, photo and biography.</li>
            <li>Your sign-in details: password, any linked Microsoft 365 or Google account, passkeys, two-step codes and trusted devices.</li>
            <li>Records that are about you, such as your group memberships and saved payment methods.</li>
        </ul>

        <h2 class="h5 mt-4">What is kept, without your name</h2>
        <p class="small">
            Records that other people rely on stay, with your name taken off them: events you created,
            attendance you took, tasks you were given, notes you wrote about somebody else.
            Some records, such as gifts and expense claims, are kept without your name where the law
            or the organisation's own duties require it.
            <a href="/account/erasure-request">Request data erasure</a> lists exactly what we hold about you
            and what would happen to each kind of record.
        </p>

        <h2 class="h5 mt-4">Need a copy of your data first?</h2>
        <p class="small mb-0">
            Use <a href="/account/data-export">Data Export</a> to download a copy of what we hold.
            <?php if ($contactEmail !== ''): ?>
                Questions? Email
                <a href="mailto:<?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>
                </a>.
            <?php endif; ?>
        </p>
    </div>
</div>

<form method="post" action="/account/delete/confirm"
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
    <a href="/account" class="btn btn-outline-secondary">Cancel</a>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
