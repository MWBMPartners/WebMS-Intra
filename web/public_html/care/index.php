<?php
// Path: public_html/care/index.php
/**
 * Care Register — list active cases (role-restricted).
 *
 * @package   Portal\Care
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/257
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Role gate — care_team or admin only.
if (App::isAdmin() === false && App::hasRole('care_team') === false) {
    http_response_code(403);
    exit('This area is restricted to the care team.');
}

$db     = App::db();
$siteId = Site::id();

$cases = [];
$rs = $db->query(
    "SELECT c.caseID, c.category, c.summary, c.status, c.openedAt, c.closedAt, "
    . "       COALESCE(u.fullName, c.personName, '(unknown)') AS personName, "
    . "       (SELECT MAX(visitedAt) FROM tblCareVisit v WHERE v.caseID = c.caseID) AS lastVisit "
    . "FROM tblCareCase c "
    . "LEFT JOIN tblUsers u ON u.userID = c.personUserID "
    . "WHERE c.siteID = " . $siteId . " "
    . "ORDER BY c.status = 'active' DESC, c.openedAt DESC LIMIT 100"
);
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $cases[] = $r;
    }
    $rs->free();
}

$pageTitle   = 'Care Register';
$pageSection = 'care';
$breadcrumbs = ['Dashboard' => '/', 'Care Register' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="alert alert-warning small mb-3">
    <i class="fa-solid fa-lock me-1"></i>
    <strong>Confidential.</strong> All access is audit-logged. Notes are visible only to the care team.
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0"><i class="fa-solid fa-hand-holding-heart me-2 text-danger"></i>Care Register</h1>
    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#newCase">
        <i class="fa-solid fa-plus me-1"></i>Open new case
    </button>
</div>

<div class="collapse mb-3" id="newCase">
    <div class="card">
        <div class="card-body">
            <h2 class="h6">Open new case</h2>
            <form method="post" action="/care/case-save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="col-md-4">
                    <label class="form-label small">Person (name, if not a portal user)</label>
                    <input type="text" name="personName" class="form-control form-control-sm" maxlength="255">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Category</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="illness">Illness</option>
                        <option value="hospital">Hospital</option>
                        <option value="bereavement">Bereavement</option>
                        <option value="family">Family</option>
                        <option value="transition">Life transition</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small">Brief summary</label>
                    <input type="text" name="summary" class="form-control form-control-sm" required maxlength="500">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">Open case</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Cases</h2>
        <?php if (count($cases) === 0): ?>
            <p class="text-muted mb-0">No cases recorded.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($cases as $c):
                    $statusClass = match ($c['status']) {
                        'active'    => 'danger',
                        'long-term' => 'warning',
                        'resolved'  => 'success',
                        default     => 'secondary',
                    };
                ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-3"><a href="/care/case?id=<?php echo (int) $c['caseID']; ?>"><strong><?php echo htmlspecialchars((string) $c['personName'], ENT_QUOTES, 'UTF-8'); ?></strong></a></div>
                        <div class="col-md-2"><span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars((string) $c['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2"><span class="text-muted small"><?php echo htmlspecialchars((string) $c['category'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-3 small"><?php echo htmlspecialchars((string) $c['summary'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 small text-muted">
                            <?php echo $c['lastVisit'] !== null ? 'Last contact: ' . htmlspecialchars(date('j M', strtotime((string) $c['lastVisit'])), ENT_QUOTES, 'UTF-8') : 'No contact yet'; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
