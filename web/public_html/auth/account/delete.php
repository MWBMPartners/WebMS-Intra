<?php
// Path: public_html/auth/account/delete.php
/**
 * -----------------------------------------------------------------------------
 * Account — Self-deletion request form 🗑️
 * -----------------------------------------------------------------------------
 * Confirm page. Shows the user EXACTLY what will happen, then sends them
 * to /account/delete/confirm to execute the deletion. Two-step to make
 * accidents harder.
 *
 * @package   Portal\Auth
 * @license   All Rights Reserved
 * @version   1.0.0
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

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Delete my account</h1>
    <a href="/account" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Account
    </a>
</div>

<div class="alert alert-danger">
    <strong>This action cannot be undone.</strong>
    Please read the following carefully.
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h5">What will be deleted</h2>
        <ul>
            <li>Your profile (name, email, phone, avatar).</li>
            <li>Your local-account credentials (username, password hash).</li>
            <li>Any linked SSO accounts (Microsoft 365, Google).</li>
            <li>Any registered WebAuthn / PassKeys, TOTP secret, backup codes.</li>
            <li>Any active or revoked trusted-device cookies.</li>
            <li>Your password-reset history.</li>
        </ul>

        <h2 class="h5 mt-4">What will be retained (anonymised)</h2>
        <p class="small">
            Records that other people rely on — expense approvals you signed off,
            event series you created, attendance records you took — stay in the
            system <strong>without your name</strong>. The audit trail still shows
            "An account, now deleted, performed this action" so other admins can
            still see the history but you are no longer identified.
        </p>

        <h2 class="h5 mt-4">Need a copy of your data first?</h2>
        <p class="small mb-0">
            Use <a href="/account/data-export">Data Export</a> to download a JSON of everything we hold.
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
      onsubmit="return confirm('FINAL CONFIRMATION — delete account permanently?');">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="mb-3">
        <label for="confirm-phrase" class="form-label">
            To confirm, type <strong><code>DELETE MY ACCOUNT</code></strong> below:
        </label>
        <input type="text" name="confirm_phrase" id="confirm-phrase" class="form-control"
               pattern="DELETE MY ACCOUNT" required autocomplete="off" placeholder="DELETE MY ACCOUNT">
    </div>

    <button type="submit" class="btn btn-danger">
        <i class="fa-solid fa-trash me-1"></i> Delete my account permanently
    </button>
    <a href="/account" class="btn btn-outline-secondary">Cancel</a>
</form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
