<?php
// Path: public_html/reading-plans/index.php
/**
 * -----------------------------------------------------------------------------
 * Reading Plans — Browse 📖
 * -----------------------------------------------------------------------------
 * @package   Portal\ReadingPlans
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/265
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// All public + site-scoped plans, with my enrollment status if any.
$plans = [];
$stmt = $db->prepare(
    'SELECT p.planID, p.slug, p.name, p.description, p.kind, p.totalDays, '
    . '       e.enrollmentID, e.currentDay, e.completedAt '
    . 'FROM tblReadingPlan p '
    . 'LEFT JOIN tblReadingPlanEnrollment e ON e.planID = p.planID AND e.userID = ? '
    . 'WHERE p.siteID = ? AND p.isPublic = 1 '
    . 'ORDER BY p.kind, p.name'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $userId, $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $plans[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Reading Plans';
$pageSection = 'reading-plans';
$breadcrumbs = ['Dashboard' => '/', 'Reading Plans' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-book-open me-2"></i>Reading Plans</h1>
        <p class="text-secondary mb-0">Daily reading commitments with progress tracking.</p>
    </div>
    <a href="/reading-plans/my" class="btn btn-outline-primary btn-sm">My plans</a>
</div>

<?php if (count($plans) === 0): ?>
    <div class="alert alert-info">No plans available yet. An admin can seed plans via the database.</div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($plans as $p):
            $enrolled = $p['enrollmentID'] !== null;
            $completed = $enrolled === true && $p['completedAt'] !== null;
            $progressPct = $enrolled === true ? min(100, (int) (((int) $p['currentDay'] / max(1, (int) $p['totalDays'])) * 100)) : 0;
        ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 <?php echo $enrolled === true ? 'border-success' : ''; ?>">
                    <div class="card-body d-flex flex-column">
                        <h2 class="h6 mb-1"><?php echo htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p class="small text-muted mb-2">
                            <?php echo htmlspecialchars((string) $p['kind'], ENT_QUOTES, 'UTF-8'); ?>
                            &middot; <?php echo (int) $p['totalDays']; ?> days
                        </p>
                        <?php if (($p['description'] ?? '') !== ''): ?>
                            <p class="small flex-grow-1"><?php echo htmlspecialchars((string) $p['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <?php if ($completed === true): ?>
                            <div class="alert alert-success py-1 px-2 small mb-2 mt-auto">
                                <i class="fa-solid fa-check-circle me-1"></i>Completed!
                            </div>
                        <?php elseif ($enrolled === true): ?>
                            <div class="progress mt-auto mb-2" style="height: .5rem;">
                                <div class="progress-bar bg-success" style="width: <?php echo $progressPct; ?>%"></div>
                            </div>
                            <p class="small text-muted mb-2">Day <?php echo (int) $p['currentDay']; ?> of <?php echo (int) $p['totalDays']; ?></p>
                        <?php endif; ?>
                        <a href="/reading-plans/plan?id=<?php echo (int) $p['planID']; ?>" class="btn btn-sm <?php echo $enrolled === true ? 'btn-success' : 'btn-outline-primary'; ?>">
                            <?php echo $enrolled === true ? 'Continue' : 'View / enroll'; ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
