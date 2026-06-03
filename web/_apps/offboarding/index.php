<?php
// Path: public_html/offboarding/index.php
/**
 * Offboarding — list of recent offboarding actions (audit view).
 *
 * @package   Portal\Offboarding
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/240
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

$db = App::db();

$rows = [];
$rs = $db->query(
    'SELECT o.offboardingID, o.userID, o.effectiveDate, o.reason, o.dataDisposition, '
    . '       o.offboardedAt, o.rehiredAt, '
    . '       u.fullName, u.emailAddress, '
    . '       b.fullName AS byName '
    . 'FROM tblOffboarding o '
    . 'JOIN tblUsers u ON u.userID = o.userID '
    . 'LEFT JOIN tblUsers b ON b.userID = o.offboardedByID '
    . 'ORDER BY o.offboardedAt DESC LIMIT 100'
);
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $rs->free();
}

$undoWindowDays = (int) (App::settings()['offboarding']['undo_window_days'] ?? 7);

$pageTitle   = 'Offboarding';
$pageSection = 'offboarding';
$breadcrumbs = ['Dashboard' => '/', 'Offboarding' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-door-open me-2 text-danger"></i>Offboarding</h1>
        <p class="text-secondary mb-0">Revoke access when a volunteer or staff member leaves. <?php echo $undoWindowDays; ?>-day undo window.</p>
    </div>
    <a href="/admin/users" class="btn btn-outline-secondary btn-sm">Pick user → /admin/users</a>
</div>

<div class="alert alert-info small">
    To offboard a user, navigate to <a href="/admin/users">/admin/users</a> → click the user → "Offboard" action. This page lists what's already been done.
</div>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-secondary">No offboarding events recorded.</div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($rows as $r):
                    $canUndo = $r['rehiredAt'] === null &&
                        strtotime((string) $r['offboardedAt']) > (time() - $undoWindowDays * 86400);
                ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-3">
                            <strong><?php echo htmlspecialchars((string) $r['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <br><span class="small text-muted"><?php echo htmlspecialchars((string) $r['emailAddress'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="col-md-2 small">
                            <?php echo htmlspecialchars(date('j M Y', strtotime((string) $r['offboardedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            <br><span class="text-muted">by <?php echo htmlspecialchars((string) ($r['byName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="col-md-2"><span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) $r['dataDisposition'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-3 small text-muted"><?php echo htmlspecialchars((string) ($r['reason'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 text-end">
                            <?php if ($r['rehiredAt'] !== null): ?>
                                <span class="badge bg-success">Rehired</span>
                            <?php elseif ($canUndo === true): ?>
                                <form method="post" action="/offboarding/rehire" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="offboardingID" value="<?php echo (int) $r['offboardingID']; ?>">
                                    <button type="submit" class="btn btn-outline-success btn-sm"
                                            data-confirm="Rehire this user? Their account becomes active again — they'll need to set a new password.">Rehire</button>
                                </form>
                            <?php else: ?>
                                <span class="small text-muted">Window passed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
