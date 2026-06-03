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

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$settings = App::settings();
$defaultExpiry = (int) ($settings['invites']['default_expiry_days'] ?? 7);
$defaultRole   = (string) ($settings['invites']['default_role'] ?? 'user');

$pageTitle   = 'New invitation';
$pageSection = 'invites';
$breadcrumbs = ['Dashboard' => '/', 'Invitations' => '/invites', 'New' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-envelope-open-text me-2"></i>New invitation</h1>
<p class="text-muted">Generate one or more single-use invite links. Recipients self-register with the assigned role pre-set.</p>

<div class="card">
    <div class="card-body">
        <form method="post" action="/invites/save">
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
                        <?php foreach (['user', 'volunteer', 'staff', 'admin'] as $role): ?>
                            <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $role === $defaultRole ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>
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
                <button type="submit" class="btn btn-primary">Generate invitations</button>
                <a href="/invites" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
