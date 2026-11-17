<?php
// Path: public_html/invites/new.php
/**
 * Invite Onboarding — admin form to create new invitations (single + bulk).
 *
 * @package   Portal\Invites
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/239
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\PortalAddress;
use Portal\Core\PublicWebsiteSetup;
use Portal\Core\Roles;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$settings = App::settings();
$defaultExpiry = (int) ($settings['invites']['default_expiry_days'] ?? 7);
$defaultRole   = (string) ($settings['invites']['default_role'] ?? 'user');

// 🎟️ #539: the invitation roles that really grant something here. "volunteer" and
//    "staff" are offered only if this organisation still has them AND this person
//    may hand them out under D-093 (an ordinary administrator may not hand out a
//    role that opens "Set up the public website"). save.php asks both again.
$grantableRoles = [];
$dbForRoles     = App::db();
$inviterNumber  = (int) ($_SESSION['user_id'] ?? 0);
foreach (Roles::forSite($dbForRoles, Site::id()) as $roleRow) {
    if (($roleRow['roleKey'] === 'volunteer' || $roleRow['roleKey'] === 'staff')
        && PublicWebsiteSetup::whyRoleChangeRefused($dbForRoles, $inviterNumber, Site::id(), [$roleRow['roleKey']]) === ''
    ) {
        $grantableRoles[] = $roleRow['roleKey'];
    }
}

// 🔗 Can an invitation's e-mail carry a working link (#500)? Asked here as
//    well as in the save handler, which refuses on the same answer, so the
//    reason is on screen before anybody types a list of addresses.
$canLink = PortalAddress::absolute('auth/invite') !== '';

// 💬 A refusal from invites/save.php (an organisation without the role, or D-093's
//    hand-out rule) is carried here in the session; neither invitations page used
//    to print one, so a refusal would have looked like nothing happening.
$refusalFlash = (string) ($_SESSION['flash_msg'] ?? '');
$refusalType  = (string) ($_SESSION['flash_type'] ?? 'info');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'New invitation';
$pageSection = 'invites';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Invitations' => Site::url('invites'), 'New' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-envelope-open-text me-2"></i>New invitation</h1>
<p class="text-muted">Generate one or more single-use invite links. Recipients self-register with the assigned role pre-set.</p>

<?php if ($refusalFlash !== ''): ?>
<div class="alert alert-<?php echo htmlspecialchars($refusalType !== '' ? $refusalType : 'info', ENT_QUOTES, 'UTF-8'); ?>" role="status" data-invite-refusal="1">
    <?php echo htmlspecialchars($refusalFlash, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php endif; ?>

<?php if ($canLink === false): ?>
<div class="alert alert-danger" role="alert">
    <strong>Invitations cannot be sent yet.</strong>
    The portal does not know its own web address, so an invitation's e-mail could not carry a working link.
    A global administrator can set the address at Admin, Settings, Organisation
    (<a href="<?php echo htmlspecialchars(Site::url('admin/settings/organisation'), ENT_QUOTES, 'UTF-8'); ?>" class="alert-link">open that page</a>). Nothing is issued until then.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?php echo htmlspecialchars(Site::url('invites/save'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label">Email addresses (one per line)</label>
                    <textarea name="emails" class="form-control" rows="5" required placeholder="jane@example.org&#10;john@example.org"></textarea>
                    <div class="form-text">One invitation will be generated per address. Duplicate active invitations to the same address are skipped.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Role on acceptance</label>
                    <select name="role" class="form-select">
                        <?php foreach (['user', 'volunteer', 'staff', 'admin'] as $role):
                // 🎟️ #539: "volunteer" and "staff" grant that role on acceptance now, so
                //    they are offered only where the organisation still has the role and
                //    where THIS person may hand it out (D-093; save.php asks again).
                if (($role === 'volunteer' || $role === 'staff') && in_array($role, $grantableRoles, true) === false) {
                    continue;
                } ?>
                            <?php // 🛡️ #518: the label makes clear "admin" only reaches this
                                  // organisation — see invites/accept.php, which now enforces
                                  // that (it used to grant portal-wide rights). The submitted
                                  // value is unchanged. ?>
                            <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $role === $defaultRole ? 'selected' : ''; ?>>
                                <?php echo $role === 'admin'
                                    ? 'admin (of this organisation)'
                                    : htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Expires in (days)</label>
                    <input type="number" name="expiryDays" min="1" max="90" class="form-control" value="<?php echo $defaultExpiry; ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Welcome message (optional, markdown)</label>
                    <textarea name="welcomeMessage" class="form-control" rows="3" placeholder="Anything personal to say in the invite email…"></textarea>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"<?php echo $canLink === false ? ' disabled' : ''; ?>>Generate invitations</button>
                <a href="<?php echo htmlspecialchars(Site::url('invites'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
