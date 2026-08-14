<?php
// Path: _apps/admin/discipleship/progress.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship Progress: Pathway list 📖 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * Entry point for the pastor/admin progress surface — lists every pathway
 * at the active site with enrolment counts (active/completed/withdrawn),
 * linking through to the per-pathway roster.
 *
 * Gated by:
 *   • Auth::requireLogin()
 *   • App::isAdmin() === true
 *   • Settings::get('discipleship.enabled') resolves truthy
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
use Portal\Core\Settings;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🚪 Feature gate — same friendly inline notice as Phase 1's pathways.php
//    rather than a hard exit, so a bookmarked URL explains why it's empty.
$enabled = (string) Settings::get('discipleship.enabled', 'false');
if ($enabled !== '1' && $enabled !== 'true') {
    $pageTitle = 'Discipleship Progress';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    echo '<div class="container py-4" style="max-width:720px;">';
    echo '<h1 class="h4 mb-2"><i class="fa-solid fa-route me-2 text-primary"></i>Discipleship Progress</h1>';
    echo '<div class="alert alert-info">';
    echo '<p class="mb-2">The Discipleship Pathway Tracker is currently disabled.</p>';
    echo '<p class="mb-0 small">Enable it under <a href="/admin/settings">Settings</a> by setting <code>discipleship.enabled</code> to <code>true</code>.</p>';
    echo '</div></div>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

$siteId = Site::id();
$db     = App::db();

$pathways = [];
$stmt = $db->prepare(
    'SELECT p.pathwayID, p.name, p.description, p.isActive, '
    . '  (SELECT COUNT(*) FROM tblPathwaySteps s WHERE s.pathwayID = p.pathwayID) AS stepCount, '
    . '  (SELECT COUNT(*) FROM tblPathwayEnrolments e WHERE e.pathwayID = p.pathwayID AND e.status = \'active\') AS activeCount, '
    . '  (SELECT COUNT(*) FROM tblPathwayEnrolments e WHERE e.pathwayID = p.pathwayID AND e.status = \'completed\') AS completedCount, '
    . '  (SELECT COUNT(*) FROM tblPathwayEnrolments e WHERE e.pathwayID = p.pathwayID AND e.status = \'withdrawn\') AS withdrawnCount '
    . 'FROM tblPathways p '
    . 'WHERE p.siteID = ? '
    . 'ORDER BY p.isActive DESC, p.name ASC '
    . 'LIMIT 500'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while (($r = $result->fetch_assoc()) !== null) {
        $pathways[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Discipleship Progress';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Discipleship Progress' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 mb-0"><i class="fa-solid fa-route me-2 text-primary"></i>Discipleship Progress</h1>
        <a href="/admin/discipleship/pathways" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-list-ol me-1"></i>Manage pathways
        </a>
    </div>
    <p class="text-muted small">
        Per-pathway rosters showing who is enrolled, who's stuck, and who's finished.
        Steps and auto-completion rules are edited on the
        <a href="/admin/discipleship/pathways">Pathways</a> page.
    </p>

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

    <?php if (count($pathways) === 0): ?>
        <div class="alert alert-info small">
            No pathways defined yet. <a href="/admin/discipleship/pathways/new">Create one</a> first.
        </div>
    <?php else: ?>
        <div class="portal-data-list">
            <?php foreach ($pathways as $p): ?>
                <?php $isActive = (int) $p['isActive'] === 1; ?>
                <div class="portal-data-row">
                    <div class="portal-data-row-main">
                        <a href="/admin/discipleship/progress/pathway?id=<?php echo (int) $p['pathwayID']; ?>" class="text-decoration-none">
                            <strong><?php echo htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                        <?php if ($isActive === false): ?>
                            <span class="badge bg-secondary ms-1">Inactive</span>
                        <?php endif; ?>
                        <div class="small text-muted">
                            <?php echo (int) $p['stepCount']; ?> step<?php echo (int) $p['stepCount'] === 1 ? '' : 's'; ?>
                            &middot;
                            <span class="badge bg-primary"><?php echo (int) $p['activeCount']; ?> active</span>
                            <span class="badge bg-success"><?php echo (int) $p['completedCount']; ?> completed</span>
                            <?php if ((int) $p['withdrawnCount'] > 0): ?>
                                <span class="badge bg-secondary"><?php echo (int) $p['withdrawnCount']; ?> withdrawn</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="portal-data-row-aside">
                        <a href="/admin/discipleship/progress/pathway?id=<?php echo (int) $p['pathwayID']; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-users me-1"></i>Roster
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
