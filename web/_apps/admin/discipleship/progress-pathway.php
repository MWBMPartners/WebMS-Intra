<?php
// Path: _apps/admin/discipleship/progress-pathway.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship Progress: Pathway roster 📖 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * GET /admin/discipleship/progress/pathway?id=<pathwayID>
 *
 * Per-pathway roster — one `portal-data-list` row per enrolled member with
 * progress bar, n/m required steps, status, and last completion (never a
 * members×steps `<table>` matrix — house ban + issue #303 decision 2).
 * Includes the enrol form (site members not already enrolled) and a
 * withdraw button per active/completed row.
 *
 * Gated by:
 *   • Auth::requireLogin()
 *   • App::isAdmin() === true
 *   • Settings::get('discipleship.enabled') resolves truthy
 *   • Cross-site guard (pathway.siteID must match Site::id())
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
use Portal\Core\Discipleship;
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
$pathwayId = (int) ($_GET['id'] ?? 0);

// 🛡️ Cross-site guard — pathway must belong to the active site.
$pathway = null;
if ($pathwayId > 0) {
    $stmt = $db->prepare('SELECT pathwayID, name, description FROM tblPathways WHERE pathwayID = ? AND siteID = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $pathwayId, $siteId);
        $stmt->execute();
        $pathway = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
if ($pathway === null) {
    Router::renderError(404);
    return;
}

// 🤖 Lazy auto-sweep, scoped to this pathway only.
Discipleship::autoSweep($siteId, $pathwayId);

$roster = Discipleship::rosterStats($siteId, $pathwayId);

// 📋 Site members not currently active/completed on this pathway — the
//    enrol-form picker. Withdrawn members DELIBERATELY stay in this list
//    (rather than being excluded like active/completed ones) so picking
//    them again hits enrol-save.php's ON DUPLICATE KEY UPDATE reactivation
//    path instead of needing a separate "re-enrol" control.
$candidates = [];
$stmt = $db->prepare(
    'SELECT u.userID, u.fullName FROM tblUsers u '
    . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
    . 'WHERE u.isActive = 1 '
    . '  AND u.userID NOT IN ('
    . '    SELECT e.userID FROM tblPathwayEnrolments e '
    . '    WHERE e.pathwayID = ? AND e.status != \'withdrawn\''
    . '  ) '
    . 'ORDER BY u.fullName ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $pathwayId);
    $stmt->execute();
    $result = $stmt->get_result();
    while (($r = $result->fetch_assoc()) !== null) {
        $candidates[] = $r;
    }
    $stmt->close();
}

$pageTitle   = (string) $pathway['name'];
$pageSection = 'admin';
$breadcrumbs = [
    'Dashboard'            => '/',
    'Admin'                => '/admin',
    'Discipleship Progress' => '/admin/discipleship/progress',
    (string) $pathway['name'] => '',
];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h1 class="h4 mb-0"><i class="fa-solid fa-route me-2 text-primary"></i><?php echo htmlspecialchars((string) $pathway['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <a href="/admin/discipleship/progress" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>All pathways</a>
    </div>
    <?php if (empty($pathway['description']) === false): ?>
        <p class="text-muted small"><?php echo htmlspecialchars((string) $pathway['description'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

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

    <!-- ➕ Enrol form -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 mb-2"><i class="fa-solid fa-user-plus me-1"></i>Enrol a member</h2>
            <?php if (count($candidates) === 0): ?>
                <p class="text-muted small mb-0">Every active site member is already enrolled (or there are none to add).</p>
            <?php else: ?>
                <form method="post" action="/admin/discipleship/enrol" class="d-flex flex-wrap gap-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="enrol">
                    <input type="hidden" name="pathwayID" value="<?php echo (int) $pathwayId; ?>">
                    <div>
                        <label class="form-label small">Member</label>
                        <select name="userID" required class="form-select form-select-sm">
                            <option value="">Choose a member&hellip;</option>
                            <?php foreach ($candidates as $c): ?>
                                <option value="<?php echo (int) $c['userID']; ?>">
                                    <?php echo htmlspecialchars((string) $c['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-1"></i>Enrol</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- 👥 Roster -->
    <?php if (count($roster) === 0): ?>
        <div class="alert alert-info small">No one is enrolled on this pathway yet.</div>
    <?php else: ?>
        <div class="portal-data-list">
            <?php foreach ($roster as $m): ?>
                <?php
                $required  = (int) $m['requiredCount'];
                $completed = (int) $m['completedCount'];
                $pct       = $required > 0 ? (int) round(($completed / $required) * 100) : 100;
                $statusColors = ['active' => 'primary', 'completed' => 'success', 'withdrawn' => 'secondary'];
                $sColor = $statusColors[(string) $m['status']] ?? 'secondary';
                ?>
                <div class="portal-data-row">
                    <div class="portal-data-row-main">
                        <a href="/admin/discipleship/progress/member?pathway=<?php echo (int) $pathwayId; ?>&user=<?php echo (int) $m['userID']; ?>" class="text-decoration-none">
                            <strong><?php echo htmlspecialchars((string) $m['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                        <span class="badge bg-<?php echo $sColor; ?> ms-1">
                            <?php echo htmlspecialchars(ucfirst((string) $m['status']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <div class="progress mt-2" style="height:8px;max-width:16rem;">
                            <div class="progress-bar bg-<?php echo $sColor; ?>" role="progressbar" style="width:<?php echo $pct; ?>%;"
                                 aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <div class="small text-muted mt-1">
                            <?php echo $completed; ?> of <?php echo $required; ?> required step<?php echo $required === 1 ? '' : 's'; ?>
                            <?php if (empty($m['lastCompletedAt']) === false): ?>
                                &middot; last completion <?php echo htmlspecialchars(date('j M Y', (int) strtotime((string) $m['lastCompletedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="portal-data-row-aside">
                        <a href="/admin/discipleship/progress/member?pathway=<?php echo (int) $pathwayId; ?>&user=<?php echo (int) $m['userID']; ?>" class="btn btn-sm btn-outline-primary" title="Steps">
                            <i class="fa-solid fa-list-check"></i>
                        </a>
                        <?php if ((string) $m['status'] !== 'withdrawn'): ?>
                            <form method="post" action="/admin/discipleship/enrol" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="withdraw">
                                <input type="hidden" name="pathwayID" value="<?php echo (int) $pathwayId; ?>">
                                <input type="hidden" name="userID" value="<?php echo (int) $m['userID']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Withdraw"
                                        data-confirm="Withdraw <?php echo htmlspecialchars((string) $m['fullName'], ENT_QUOTES, 'UTF-8'); ?> from this pathway?">
                                    <i class="fa-solid fa-user-minus"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
