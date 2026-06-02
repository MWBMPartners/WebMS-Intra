<?php
// Path: public_html/reading-plans/my.php
/**
 * Reading Plans — my enrolled plans dashboard.
 *
 * @package   Portal\ReadingPlans
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/265
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$enrollments = [];
$stmt = $db->prepare(
    'SELECT e.enrollmentID, e.currentDay, e.startedAt, e.completedAt, '
    . '       p.planID, p.name, p.totalDays, p.kind '
    . 'FROM tblReadingPlanEnrollment e '
    . 'JOIN tblReadingPlan p ON p.planID = e.planID '
    . 'WHERE e.userID = ? '
    . 'ORDER BY e.completedAt IS NULL DESC, e.startedAt DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $enrollments[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'My Reading Plans';
$pageSection = 'reading-plans';
$breadcrumbs = ['Dashboard' => '/', 'Reading Plans' => '/reading-plans', 'My plans' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-book-bookmark me-2"></i>My Reading Plans</h1>

<?php if (count($enrollments) === 0): ?>
    <div class="alert alert-info">You're not enrolled in any plans yet. <a href="/reading-plans">Browse available plans</a>.</div>
<?php else: ?>
    <div class="portal-data-list">
        <?php foreach ($enrollments as $e):
            $pct = min(100, (int) (((int) $e['currentDay'] / max(1, (int) $e['totalDays'])) * 100));
            $completed = $e['completedAt'] !== null;
        ?>
            <div class="row py-3 border-bottom align-items-center">
                <div class="col-md-4">
                    <a href="/reading-plans/plan?id=<?php echo (int) $e['planID']; ?>"><strong><?php echo htmlspecialchars((string) $e['name'], ENT_QUOTES, 'UTF-8'); ?></strong></a>
                </div>
                <div class="col-md-5">
                    <div class="progress" style="height: .5rem;">
                        <div class="progress-bar bg-<?php echo $completed === true ? 'success' : 'primary'; ?>" style="width: <?php echo $pct; ?>%"></div>
                    </div>
                    <small class="text-muted">Day <?php echo (int) $e['currentDay']; ?> / <?php echo (int) $e['totalDays']; ?></small>
                </div>
                <div class="col-md-3 text-end">
                    <?php if ($completed === true): ?>
                        <span class="badge bg-success">Completed</span>
                    <?php else: ?>
                        <a href="/reading-plans/plan?id=<?php echo (int) $e['planID']; ?>" class="btn btn-sm btn-success">Continue</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
