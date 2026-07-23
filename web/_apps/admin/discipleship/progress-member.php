<?php
// Path: _apps/admin/discipleship/progress-member.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship Progress: Member steps 📖 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * GET /admin/discipleship/progress/member?pathway=<pathwayID>&user=<userID>
 *
 * One member's step list for one pathway — mark-complete / unmark buttons
 * (never native `confirm()` — `data-confirm` on the destructive unmark),
 * an optional note field, and auto-evidence (`autoRef`) / revocation state
 * for steps a coordinator has unmarked. Unlike `Discipleship::progressFor()`
 * (which only surfaces the CURRENT unrevoked row), this page also shows
 * revoked rows so a coordinator can see "this was auto-completed, then
 * unmarked" rather than just "not done".
 *
 * Gated by:
 *   • Auth::requireLogin()
 *   • App::isAdmin() === true
 *   • Settings::get('discipleship.enabled') resolves truthy
 *   • Cross-site guard (pathway.siteID must match Site::id())
 *   • Target user must hold an enrolment for this pathway (else 404)
 *
 * @package   Portal\App\Admin\Discipleship
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
use Portal\Core\Router;
use Portal\Core\Settings;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$enabled = (string) Settings::get('discipleship.enabled', 'false');
if ($enabled !== '1' && $enabled !== 'true') {
    $_SESSION['flash_msg']  = 'Discipleship app is disabled.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/discipleship/pathways', true, 302);
    exit();
}

$db        = App::db();
$siteId    = Site::id();
$pathwayId = (int) ($_GET['pathway'] ?? 0);
$targetId  = (int) ($_GET['user'] ?? 0);

// 🛡️ Cross-site guard + enrolment guard — pathway must belong to the
// active site AND the target user must hold an enrolment for it.
$context = null;
if ($pathwayId > 0 && $targetId > 0) {
    $stmt = $db->prepare(
        'SELECT p.pathwayID, p.name AS pathwayName, u.fullName AS memberName, en.status '
        . 'FROM tblPathways p '
        . 'JOIN tblPathwayEnrolments en ON en.pathwayID = p.pathwayID AND en.userID = ? '
        . 'JOIN tblUsers u ON u.userID = en.userID '
        . 'WHERE p.pathwayID = ? AND p.siteID = ? '
        . 'LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iii', $targetId, $pathwayId, $siteId);
        $stmt->execute();
        $context = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
if ($context === null) {
    Router::renderError(404);
    return;
}

// 📋 Every step, LEFT JOINed to the target member's progress row (if any —
// revoked or not; this admin view needs to see revocation state too).
$steps = [];
$stmt = $db->prepare(
    'SELECT s.stepID, s.sortOrder, s.name, s.description, s.completionHint, s.isOptional, '
    . '       pr.progressID, pr.source, pr.autoRef, pr.notes, pr.completedAt, '
    . '       pr.revokedAt, pr.markedByID, pr.revokedByID '
    . 'FROM tblPathwaySteps s '
    . 'JOIN tblPathways p ON p.pathwayID = s.pathwayID AND p.siteID = ? '
    . 'LEFT JOIN tblPathwayProgress pr ON pr.stepID = s.stepID AND pr.userID = ? '
    . 'WHERE s.pathwayID = ? '
    . 'ORDER BY s.sortOrder ASC, s.stepID ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $siteId, $targetId, $pathwayId);
    $stmt->execute();
    $result = $stmt->get_result();
    while (($r = $result->fetch_assoc()) !== null) {
        $steps[] = $r;
    }
    $stmt->close();
}

$pageTitle   = (string) $context['memberName'] . ' — ' . (string) $context['pathwayName'];
$pageSection = 'admin';
$breadcrumbs = [
    'Dashboard'             => '/',
    'Admin'                 => '/admin',
    'Discipleship Progress' => '/admin/discipleship/progress',
    (string) $context['pathwayName'] => '/admin/discipleship/progress/pathway?id=' . $pathwayId,
    (string) $context['memberName']  => '',
];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h1 class="h4 mb-0">
            <i class="fa-solid fa-user me-2 text-primary"></i>
            <?php echo htmlspecialchars((string) $context['memberName'], ENT_QUOTES, 'UTF-8'); ?>
            <small class="text-muted">on <?php echo htmlspecialchars((string) $context['pathwayName'], ENT_QUOTES, 'UTF-8'); ?></small>
        </h1>
        <a href="/admin/discipleship/progress/pathway?id=<?php echo $pathwayId; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i>Roster
        </a>
    </div>

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

    <?php if (count($steps) === 0): ?>
        <div class="alert alert-info small">This pathway has no steps yet.</div>
    <?php else: ?>
        <div class="portal-data-list">
            <?php foreach ($steps as $s): ?>
                <?php
                $isDone    = $s['progressID'] !== null && $s['revokedAt'] === null;
                $isRevoked = $s['progressID'] !== null && $s['revokedAt'] !== null;
                ?>
                <div class="portal-data-row">
                    <div class="portal-data-row-main">
                        <?php if ($isDone === true): ?>
                            <i class="fa-solid fa-circle-check text-success me-1"></i>
                        <?php elseif ($isRevoked === true): ?>
                            <i class="fa-solid fa-rotate-left text-warning me-1" title="Previously completed, now unmarked"></i>
                        <?php else: ?>
                            <i class="fa-regular fa-circle text-muted me-1"></i>
                        <?php endif; ?>
                        <strong><?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if ((int) $s['isOptional'] === 1): ?>
                            <span class="badge bg-secondary ms-1">Optional</span>
                        <?php endif; ?>
                        <?php if ($isDone === true): ?>
                            <span class="badge bg-<?php echo ($s['source'] === 'auto' ? 'info' : 'success'); ?> ms-1">
                                <?php echo $s['source'] === 'auto' ? 'Auto' : 'Manual'; ?>
                            </span>
                        <?php elseif ($isRevoked === true): ?>
                            <span class="badge bg-warning text-dark ms-1">Unmarked</span>
                        <?php endif; ?>
                        <?php if (empty($s['description']) === false): ?>
                            <div class="small text-muted"><?php echo htmlspecialchars((string) $s['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php if (empty($s['completionHint']) === false): ?>
                            <div class="small text-muted fst-italic"><?php echo htmlspecialchars((string) $s['completionHint'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php if (($isDone === true || $isRevoked === true) && empty($s['autoRef']) === false): ?>
                            <div class="small text-muted">Evidence: <code><?php echo htmlspecialchars((string) $s['autoRef'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                        <?php endif; ?>
                        <?php if ($isDone === true && $s['completedAt'] !== null): ?>
                            <div class="small text-muted">Completed <?php echo htmlspecialchars(date('j M Y, H:i', (int) strtotime((string) $s['completedAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php if (empty($s['notes']) === false): ?>
                            <div class="small text-muted">Note: <?php echo htmlspecialchars((string) $s['notes'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="portal-data-row-aside">
                        <?php if ($isDone === true): ?>
                            <form method="post" action="/admin/discipleship/progress/mark" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="pathwayID" value="<?php echo $pathwayId; ?>">
                                <input type="hidden" name="stepID" value="<?php echo (int) $s['stepID']; ?>">
                                <input type="hidden" name="userID" value="<?php echo $targetId; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning"
                                        data-confirm="Unmark this step? Any auto-completion evidence stays recorded, but the step will show as incomplete again.">
                                    Unmark
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/admin/discipleship/progress/mark" class="d-flex gap-1">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="pathwayID" value="<?php echo $pathwayId; ?>">
                                <input type="hidden" name="stepID" value="<?php echo (int) $s['stepID']; ?>">
                                <input type="hidden" name="userID" value="<?php echo $targetId; ?>">
                                <input type="text" name="notes" maxlength="500" class="form-control form-control-sm"
                                       placeholder="Note (optional)" style="max-width:12rem;">
                                <button type="submit" class="btn btn-sm btn-outline-success">Mark complete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
