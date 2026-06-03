<?php
// Path: public_html/invites/index.php
/**
 * Invite Onboarding — admin list of issued invitations.
 *
 * @package   Portal\Invites
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/239
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$db     = App::db();
$siteId = Site::id();

$invites = [];
$stmt = $db->prepare(
    'SELECT i.invitationID, i.email, i.intendedRole, i.expiresAt, '
    . '       i.acceptedAt, i.revokedAt, i.createdAt, '
    . '       u.fullName AS acceptedByName '
    . 'FROM tblInvitation i '
    . 'LEFT JOIN tblUsers u ON u.userID = i.acceptedByID '
    . 'WHERE i.siteID = ? '
    . 'ORDER BY i.createdAt DESC LIMIT 200'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $invites[] = $r;
    }
    $stmt->close();
}

$now = time();
$status = static function (array $r) use ($now): array {
    if ($r['acceptedAt'] !== null) {
        return ['Accepted', 'success'];
    }
    if ($r['revokedAt'] !== null) {
        return ['Revoked', 'secondary'];
    }
    if (strtotime((string) $r['expiresAt']) < $now) {
        return ['Expired', 'warning'];
    }
    return ['Pending', 'info'];
};

$pageTitle   = 'Invitations';
$pageSection = 'invites';
$breadcrumbs = ['Dashboard' => '/', 'Invitations' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-envelope-open-text me-2"></i>Invitations</h1>
        <p class="text-secondary mb-0">Single-use invite links for new-member self-registration.</p>
    </div>
    <a href="/invites/new" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>New invitation</a>
</div>

<?php if (count($invites) === 0): ?>
    <div class="alert alert-info">No invitations sent yet.</div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($invites as $r):
                    [$label, $cls] = $status($r);
                ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-4"><strong><?php echo htmlspecialchars((string) $r['email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="col-md-2"><span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) ($r['intendedRole'] ?? 'user'), ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2"><span class="badge bg-<?php echo $cls; ?>"><?php echo $label; ?></span></div>
                        <div class="col-md-2 small text-muted">
                            <?php if ($r['acceptedAt'] !== null): ?>
                                Accepted <?php echo htmlspecialchars(date('j M', strtotime((string) $r['acceptedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($r['acceptedByName'] !== null): ?>
                                    <br>→ <?php echo htmlspecialchars((string) $r['acceptedByName'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                Expires <?php echo htmlspecialchars(date('j M', strtotime((string) $r['expiresAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2 text-end">
                            <?php if ($label === 'Pending'): ?>
                                <form method="post" action="/invites/revoke" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="invitationID" value="<?php echo (int) $r['invitationID']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"
                                            data-confirm="Revoke this invitation? The link will no longer work." data-confirm-destructive="true">Revoke</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
