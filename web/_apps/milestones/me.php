<?php
// Path: public_html/milestones/me.php
/**
 * Milestones — user manages their own entries.
 *
 * @package   Portal\Milestones
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/259
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$rows = [];
$stmt = $db->prepare('SELECT milestoneID, kind, label, monthDay, originYear, privacy FROM tblUserMilestone WHERE userID = ? ORDER BY monthDay');
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'My Milestones';
$pageSection = 'milestones';
$breadcrumbs = ['Dashboard' => '/', 'Milestones' => '/milestones', 'My Milestones' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-cake-candles me-2"></i>My Milestones</h1>
<p class="text-muted">Add the dates you want others to remember. You control who sees each one.</p>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Add a milestone</h2>
        <form method="post" action="/milestones/save" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-2">
                <label class="form-label small">Kind</label>
                <select name="kind" class="form-select form-select-sm">
                    <option value="birthday">Birthday</option>
                    <option value="anniversary">Anniversary</option>
                    <option value="baptism">Baptism</option>
                    <option value="joining">Joined</option>
                    <option value="wedding">Wedding</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Label (optional)</label>
                <input type="text" name="label" class="form-control form-control-sm" maxlength="100" placeholder="e.g. Started volunteering">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Date</label>
                <input type="date" name="date" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Privacy</label>
                <select name="privacy" class="form-select form-select-sm">
                    <option value="team">Team / role-mates</option>
                    <option value="members" selected>All members</option>
                    <option value="private">Private (admins only)</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5">My existing milestones</h2>
        <?php if (count($rows) === 0): ?>
            <p class="text-muted mb-0">None yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($rows as $r): ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-2"><strong><?php echo htmlspecialchars(date('j M', strtotime(date('Y') . '-' . (string) $r['monthDay'])), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="col-md-2"><?php echo htmlspecialchars(ucfirst((string) $r['kind']), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-3 small text-muted"><?php echo htmlspecialchars((string) ($r['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2"><span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) $r['privacy'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-3 text-end">
                            <form method="post" action="/milestones/save" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="delete" value="<?php echo (int) $r['milestoneID']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                        data-confirm="Delete this milestone?" data-confirm-destructive="true">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
