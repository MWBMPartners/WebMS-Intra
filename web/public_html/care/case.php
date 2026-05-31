<?php
// Path: public_html/care/case.php
/**
 * Care Register — single case view with visit log + add-visit form.
 *
 * @package   Portal\Care
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/257
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Markdown;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false && App::hasRole('care_team') === false) {
    http_response_code(403);
    exit('This area is restricted to the care team.');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$caseId = (int) ($_GET['id'] ?? 0);

if ($caseId <= 0) {
    header('Location: /care');
    exit();
}

$case = null;
$stmt = $db->prepare(
    'SELECT c.caseID, c.category, c.summary, c.status, c.openedAt, c.closedAt, '
    . "       COALESCE(u.fullName, c.personName, '(unknown)') AS personName "
    . 'FROM tblCareCase c LEFT JOIN tblUsers u ON u.userID = c.personUserID '
    . 'WHERE c.caseID = ? AND c.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $caseId, $siteId);
    $stmt->execute();
    $case = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($case === null) {
    http_response_code(404);
    exit('Case not found.');
}

// 🪞 Audit-log this read (#257 — every case view is recorded).
try {
    $stmt = $db->prepare('INSERT INTO tblCareAccessLog (caseID, viewerID) VALUES (?, ?)');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $caseId, $userId);
        $stmt->execute();
        $stmt->close();
    }
} catch (\Throwable $ignored) {
    // Non-fatal — audit log shouldn't block the view.
}

$visits = [];
$stmt = $db->prepare(
    'SELECT v.visitID, v.visitedAt, v.kind, v.notes, v.followUpAt, '
    . '       u.fullName AS visitorName '
    . 'FROM tblCareVisit v JOIN tblUsers u ON u.userID = v.visitedByID '
    . 'WHERE v.caseID = ? ORDER BY v.visitedAt DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $caseId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $visits[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Care case';
$pageSection = 'care';
$breadcrumbs = ['Dashboard' => '/', 'Care Register' => '/care', $case['personName'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="alert alert-warning small mb-3">
    <i class="fa-solid fa-lock me-1"></i>Confidential — your access to this case has been logged.
</div>

<h1 class="mb-1"><?php echo htmlspecialchars((string) $case['personName'], ENT_QUOTES, 'UTF-8'); ?></h1>
<p class="text-muted"><?php echo htmlspecialchars((string) $case['category'], ENT_QUOTES, 'UTF-8'); ?> &middot; opened <?php echo htmlspecialchars(date('j M Y', strtotime((string) $case['openedAt'])), ENT_QUOTES, 'UTF-8'); ?> &middot; status: <strong><?php echo htmlspecialchars((string) $case['status'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
<p><?php echo htmlspecialchars((string) $case['summary'], ENT_QUOTES, 'UTF-8'); ?></p>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5">Add visit / contact</h2>
        <form method="post" action="/care/visit-save" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="caseID" value="<?php echo (int) $caseId; ?>">
            <div class="col-md-3">
                <label class="form-label small">Kind</label>
                <select name="kind" class="form-select form-select-sm">
                    <option value="visit">In-person visit</option>
                    <option value="call">Phone call</option>
                    <option value="message">Text / message</option>
                    <option value="prayer">Prayer</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Follow-up date (optional)</label>
                <input type="date" name="followUpAt" class="form-control form-control-sm">
            </div>
            <div class="col-12">
                <label class="form-label small">Notes</label>
                <textarea name="notes" class="form-control form-control-sm" rows="3" placeholder="What was discussed / how they were doing. Markdown supported."></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm">Record contact</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Contact log</h2>
        <?php if (count($visits) === 0): ?>
            <p class="text-muted mb-0">No contacts recorded yet.</p>
        <?php else: ?>
            <?php foreach ($visits as $v): ?>
                <div class="border-bottom py-2">
                    <p class="mb-1 small text-muted">
                        <?php echo htmlspecialchars(date('j M Y, H:i', strtotime((string) $v['visitedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        &middot; <strong><?php echo htmlspecialchars((string) $v['visitorName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        &middot; <?php echo htmlspecialchars((string) $v['kind'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($v['followUpAt'] !== null): ?>
                            &middot; <span class="badge bg-info text-dark">Follow up: <?php echo htmlspecialchars(date('j M', strtotime((string) $v['followUpAt'])), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </p>
                    <div class="portal-markdown"><?php echo Markdown::render((string) ($v['notes'] ?? ''), ['allow_links' => true]); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
