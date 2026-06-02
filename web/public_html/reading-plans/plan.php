<?php
// Path: public_html/reading-plans/plan.php
/**
 * Reading Plans — single-plan view with today's reading + recent history.
 *
 * @package   Portal\ReadingPlans
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/265
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Markdown;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$planId = (int) ($_GET['id'] ?? 0);

if ($planId <= 0) {
    header('Location: /reading-plans');
    exit();
}

$plan = null;
$stmt = $db->prepare(
    'SELECT planID, slug, name, description, kind, totalDays '
    . 'FROM tblReadingPlan WHERE planID = ? AND siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $planId, $siteId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($plan === null) {
    http_response_code(404);
    exit('Plan not found');
}

$enrollment = null;
$stmt = $db->prepare(
    'SELECT enrollmentID, startedAt, currentDay, completedAt '
    . 'FROM tblReadingPlanEnrollment WHERE planID = ? AND userID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $planId, $userId);
    $stmt->execute();
    $enrollment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Today's day content (if there are seeded day rows).
$todayDay = $enrollment !== null ? (int) $enrollment['currentDay'] : 1;
$day = null;
$stmt = $db->prepare(
    'SELECT dayNumber, label, content FROM tblReadingPlanDay '
    . 'WHERE planID = ? AND dayNumber = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $planId, $todayDay);
    $stmt->execute();
    $day = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Last 7 progress entries (streak preview).
$recent = [];
if ($enrollment !== null) {
    $stmt = $db->prepare(
        'SELECT dayNumber, completedAt FROM tblReadingPlanProgress '
        . 'WHERE enrollmentID = ? ORDER BY completedAt DESC LIMIT 7'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $enrollment['enrollmentID']);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $recent[] = $r;
        }
        $stmt->close();
    }
}

// Streak calculation — count consecutive days back from today where progress
// was recorded.
$streak = 0;
if ($enrollment !== null && count($recent) > 0) {
    $expected = strtotime('today');
    foreach ($recent as $r) {
        $completed = strtotime((string) $r['completedAt']);
        if (date('Y-m-d', $completed) === date('Y-m-d', $expected)) {
            $streak++;
            $expected = strtotime('-1 day', $expected);
        } else {
            break;
        }
    }
}

$pageTitle   = (string) $plan['name'];
$pageSection = 'reading-plans';
$breadcrumbs = ['Dashboard' => '/', 'Reading Plans' => '/reading-plans', (string) $plan['name'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();

$progressPct = $enrollment !== null
    ? min(100, (int) (((int) $enrollment['currentDay'] / max(1, (int) $plan['totalDays'])) * 100))
    : 0;
?>

<h1 class="mb-1"><i class="fa-solid fa-book-open me-2"></i><?php echo htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
<p class="text-muted">
    <?php echo htmlspecialchars((string) $plan['kind'], ENT_QUOTES, 'UTF-8'); ?>
    &middot; <?php echo (int) $plan['totalDays']; ?> days
</p>

<?php if ($enrollment === null): ?>
    <div class="card mb-3">
        <div class="card-body">
            <?php if (($plan['description'] ?? '') !== ''): ?>
                <p><?php echo htmlspecialchars((string) $plan['description'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <form method="post" action="/reading-plans/enroll">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="planID" value="<?php echo (int) $planId; ?>">
                <button type="submit" class="btn btn-primary">Enroll in this plan</button>
                <a href="/reading-plans" class="btn btn-outline-secondary">Back</a>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-md-8">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5">Day <?php echo $todayDay; ?> of <?php echo (int) $plan['totalDays']; ?></h2>
                    <div class="progress mb-3" style="height: .5rem;">
                        <div class="progress-bar bg-success" style="width: <?php echo $progressPct; ?>%"></div>
                    </div>
                    <?php if ($day !== null): ?>
                        <p class="lead mb-2"><?php echo htmlspecialchars((string) $day['label'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if (($day['content'] ?? '') !== ''): ?>
                            <div class="portal-markdown"><?php echo Markdown::render((string) $day['content'], ['allow_links' => true]); ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted">No reading content defined for this day. An admin can populate <code>tblReadingPlanDay</code> rows for richer guidance.</p>
                    <?php endif; ?>
                    <?php if ($enrollment['completedAt'] === null && $todayDay <= (int) $plan['totalDays']): ?>
                        <form method="post" action="/reading-plans/check" class="mt-3">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="enrollmentID" value="<?php echo (int) $enrollment['enrollmentID']; ?>">
                            <input type="hidden" name="dayNumber" value="<?php echo $todayDay; ?>">
                            <button type="submit" class="btn btn-success">
                                <i class="fa-solid fa-check me-1"></i>Mark today's reading done
                            </button>
                        </form>
                    <?php elseif ($enrollment['completedAt'] !== null): ?>
                        <div class="alert alert-success mt-3">
                            <i class="fa-solid fa-trophy me-1"></i>You've completed this plan! Finished
                            <?php echo htmlspecialchars(date('j M Y', strtotime((string) $enrollment['completedAt'])), ENT_QUOTES, 'UTF-8'); ?>.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6">Your streak</h2>
                    <p class="display-6 mb-0"><?php echo $streak; ?> <span class="fs-6 text-muted">days</span></p>
                    <hr>
                    <h3 class="h6">Recent activity</h3>
                    <?php if (count($recent) === 0): ?>
                        <p class="small text-muted mb-0">No completions yet — mark today's reading to start a streak.</p>
                    <?php else: ?>
                        <ul class="list-unstyled small mb-0">
                            <?php foreach ($recent as $r): ?>
                                <li>
                                    <i class="fa-solid fa-check-circle text-success me-1"></i>
                                    Day <?php echo (int) $r['dayNumber']; ?> &middot;
                                    <span class="text-muted"><?php echo htmlspecialchars(date('j M', strtotime((string) $r['completedAt'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
