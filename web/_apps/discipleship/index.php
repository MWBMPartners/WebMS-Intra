<?php
// Path: _apps/discipleship/index.php
/**
 * -----------------------------------------------------------------------------
 * Discipleship — My Pathways 🧭 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * Member-facing landing page. Lists the current user's own enrolments at
 * the active site with a progress bar (completed/required steps, from
 * `Discipleship::progressFor()`). This is the page the dashboard app card
 * links to — Phase 1 seeded `discipleship.enabled` but never a
 * `discipleship` route, so enabling the app rendered a dead card link;
 * this route + migration 153's route seed fix that.
 *
 * Every query is scoped to `Site::id()` AND `$_SESSION['user_id']` — a
 * member only ever sees their own enrolments here.
 *
 * @package   Portal\App\Discipleship
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/303
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Discipleship;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🚪 Feature gate — mirrors Phase 1's admin gate, redirecting out with a
// flash instead of a hard exit (edge case: app disabled mid-flight).
if (Discipleship::isEnabled() === false) {
    $_SESSION['flash_msg']  = 'Discipleship is not enabled on this site.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /dashboard', true, 302);
    exit();
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🤖 Lazy auto-sweep — cheap set-based SQL, no scheduler dependency. Keeps
// auto-completed steps fresh on every visit to a discipleship surface.
Discipleship::autoSweep($siteId);

// 📋 This member's own enrolments at the active site — never another
// member's (userID = ? is always present on this query).
$enrolments = [];
$stmt = $db->prepare(
    'SELECT en.enrolmentID, en.pathwayID, en.status, en.enrolledAt, en.completedAt, '
    . '       p.name, p.description '
    . 'FROM tblPathwayEnrolments en '
    . 'JOIN tblPathways p ON p.pathwayID = en.pathwayID AND p.siteID = ? '
    . 'WHERE en.siteID = ? AND en.userID = ? '
    . 'ORDER BY (en.status = \'active\') DESC, p.name ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $siteId, $siteId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while (($row = $result->fetch_assoc()) !== null) {
        $enrolments[] = $row;
    }
    $stmt->close();
}

// 📊 Progress counts per pathway — from progressFor(), never re-derived
// from raw progress rows so member/admin/roster views agree on one truth.
foreach ($enrolments as &$en) {
    $steps = Discipleship::progressFor($siteId, (int) $en['pathwayID'], $userId);
    $required  = 0;
    $completed = 0;
    foreach ($steps as $s) {
        if ((int) $s['isOptional'] === 0) {
            $required++;
            if ($s['progressID'] !== null) {
                $completed++;
            }
        }
    }
    $en['requiredCount']  = $required;
    $en['completedCount'] = $completed;
    $en['pct'] = $required > 0 ? (int) round(($completed / $required) * 100) : 100;
}
unset($en);

$pageTitle   = 'Discipleship';
$pageSection = 'discipleship';
$breadcrumbs = ['Dashboard' => '/', 'Discipleship' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-3"><i class="fa-solid fa-route me-2 text-primary"></i>My Pathways</h1>

    <?php if (isset($_SESSION['flash_msg']) === true): ?>
        <?php
        $msg  = (string) $_SESSION['flash_msg'];
        $type = (string) ($_SESSION['flash_type'] ?? 'info');
        unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
        $allowed = ['success', 'info', 'warning', 'danger'];
        if (in_array($type, $allowed, true) === false) { $type = 'info'; }
        ?>
        <div class="alert alert-<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?> py-2 small">
            <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (count($enrolments) === 0): ?>
        <div class="alert alert-info small">
            You are not currently enrolled on any discipleship pathway. Speak to a pastor or
            coordinator to get started.
        </div>
    <?php else: ?>
        <div class="portal-data-list">
            <?php foreach ($enrolments as $en): ?>
                <?php
                $statusColors = ['active' => 'primary', 'completed' => 'success', 'withdrawn' => 'secondary'];
                $sColor = $statusColors[(string) $en['status']] ?? 'secondary';
                $pct    = (int) $en['pct'];
                ?>
                <div class="portal-data-row">
                    <div class="portal-data-row-main">
                        <a href="/discipleship/view?id=<?php echo (int) $en['pathwayID']; ?>" class="text-decoration-none">
                            <strong><?php echo htmlspecialchars((string) $en['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                        <span class="badge bg-<?php echo $sColor; ?> ms-1">
                            <?php echo htmlspecialchars(ucfirst((string) $en['status']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php if (empty($en['description']) === false): ?>
                            <div class="small text-muted"><?php echo htmlspecialchars((string) $en['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <div class="progress mt-2" style="height:10px;max-width:20rem;">
                            <div class="progress-bar bg-<?php echo $sColor; ?>" role="progressbar"
                                 style="width:<?php echo $pct; ?>%;"
                                 aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <div class="small text-muted mt-1">
                            <?php echo (int) $en['completedCount']; ?> of <?php echo (int) $en['requiredCount']; ?>
                            required step<?php echo (int) $en['requiredCount'] === 1 ? '' : 's'; ?> complete
                        </div>
                    </div>
                    <div class="portal-data-row-aside">
                        <a href="/discipleship/view?id=<?php echo (int) $en['pathwayID']; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-arrow-right me-1"></i>View
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
