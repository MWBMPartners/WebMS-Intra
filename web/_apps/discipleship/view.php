<?php
// Path: _apps/discipleship/view.php
/**
 * -----------------------------------------------------------------------------
 * Discipleship — Pathway view (member) 🧭 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * GET /discipleship/view?id=<pathwayID>
 *
 * Ordered step list for one pathway, showing the member's own completion
 * state (done tick + completedAt + auto/manual source badge, isOptional
 * badge, description/hint text).
 *
 * Access control: 404 unless the current user holds a
 * `tblPathwayEnrolments` row for this pathway AT THIS SITE — a member can
 * never see another member's progress, and a parameter-tampered pathwayID
 * (someone else's pathway, another site's, or one never enrolled on) 404s
 * rather than leaking any pathway/step content.
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
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (Discipleship::isEnabled() === false) {
    $_SESSION['flash_msg']  = 'Discipleship is not enabled on this site.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /dashboard', true, 302);
    exit();
}

$db        = App::db();
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$pathwayId = (int) ($_GET['id'] ?? 0);

// 🛡️ Access control — "can this user see this pathway?" A row must exist
// in tblPathwayEnrolments for (pathwayID, userID) AND the parent pathway
// must belong to the active site. Any mismatch (wrong site, wrong user,
// pathway that doesn't exist) falls through to the same 404 — no
// distinguishing error that would leak whether the pathway exists.
$enrolment = null;
if ($pathwayId > 0) {
    $stmt = $db->prepare(
        'SELECT en.enrolmentID, en.status, p.name, p.description '
        . 'FROM tblPathwayEnrolments en '
        . 'JOIN tblPathways p ON p.pathwayID = en.pathwayID AND p.siteID = ? '
        . 'WHERE en.pathwayID = ? AND en.userID = ? AND en.siteID = ? '
        . 'LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iiii', $siteId, $pathwayId, $userId, $siteId);
        $stmt->execute();
        $enrolment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if ($enrolment === null) {
    Router::renderError(404);
    return;
}

// 🤖 Lazy auto-sweep, scoped to this one pathway (cheaper than a full
// site sweep for a single-pathway page view).
Discipleship::autoSweep($siteId, $pathwayId);

$steps = Discipleship::progressFor($siteId, $pathwayId, $userId);

$pathwayName = (string) $enrolment['name'];

$pageTitle   = $pathwayName;
$pageSection = 'discipleship';
$breadcrumbs = ['Dashboard' => '/', 'Discipleship' => '/discipleship', $pathwayName => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h1 class="h4 mb-0"><i class="fa-solid fa-route me-2 text-primary"></i><?php echo htmlspecialchars($pathwayName, ENT_QUOTES, 'UTF-8'); ?></h1>
        <a href="/discipleship" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>My pathways</a>
    </div>
    <?php if (empty($enrolment['description']) === false): ?>
        <p class="text-muted small"><?php echo htmlspecialchars((string) $enrolment['description'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if (count($steps) === 0): ?>
        <div class="alert alert-info small">This pathway has no steps yet.</div>
    <?php else: ?>
        <div class="portal-data-list">
            <?php foreach ($steps as $s): ?>
                <?php $done = $s['progressID'] !== null; ?>
                <div class="portal-data-row">
                    <div class="portal-data-row-main">
                        <?php if ($done === true): ?>
                            <i class="fa-solid fa-circle-check text-success me-1"></i>
                        <?php else: ?>
                            <i class="fa-regular fa-circle text-muted me-1"></i>
                        <?php endif; ?>
                        <strong><?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if ((int) $s['isOptional'] === 1): ?>
                            <span class="badge bg-secondary ms-1">Optional</span>
                        <?php endif; ?>
                        <?php if ($done === true): ?>
                            <span class="badge bg-<?php echo ($s['source'] === 'auto' ? 'info' : 'success'); ?> ms-1">
                                <?php echo $s['source'] === 'auto' ? 'Auto' : 'Manual'; ?>
                            </span>
                        <?php endif; ?>
                        <?php if (empty($s['description']) === false): ?>
                            <div class="small text-muted"><?php echo htmlspecialchars((string) $s['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php if ($done === true && $s['completedAt'] !== null): ?>
                            <div class="small text-muted">
                                Completed <?php echo htmlspecialchars(date('j M Y', (int) strtotime((string) $s['completedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php elseif (empty($s['completionHint']) === false): ?>
                            <div class="small text-muted fst-italic"><?php echo htmlspecialchars((string) $s['completionHint'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
