<?php
// Path: _apps/admin/discipleship/pathways.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship Pathways list 📖 (#303 Phase 1)
 * -----------------------------------------------------------------------------
 * Lists every pathway scoped to the active site, with step counts and last
 * updated timestamp. Composes the portal-data-list primitive (no <table>).
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
 * @version   1.0.0
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

// 🚪 Feature gate — surface a friendly 404-ish notice rather than a hard exit
//    so admins arriving via a bookmarked URL know why the page is empty.
$enabled = (string) Settings::get('discipleship.enabled', 'false');
if ($enabled !== '1' && $enabled !== 'true') {
    $pageTitle = 'Discipleship Pathways';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    echo '<div class="container py-4" style="max-width:720px;">';
    echo '<h1 class="h4 mb-2"><i class="fa-solid fa-route me-2 text-primary"></i>Discipleship Pathways</h1>';
    echo '<div class="alert alert-info">';
    echo '<p class="mb-2">The Discipleship Pathway Tracker is currently disabled.</p>';
    echo '<p class="mb-0 small">Enable it under <a href="/admin/settings">Settings</a> by setting <code>discipleship.enabled</code> to <code>true</code>.</p>';
    echo '</div></div>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

$siteId = Site::id();
$db     = App::db();

// 📜 Cross-site write guard is enforced at SELECT time too — we never even
//    render pathways that don't belong to the active site.
$pathways = [];
$stmt = $db->prepare(
    'SELECT p.pathwayID, p.name, p.description, p.isActive, p.createdAt, p.updatedAt, '
    . '       u.fullName AS creatorName, '
    . '       (SELECT COUNT(*) FROM tblPathwaySteps s WHERE s.pathwayID = p.pathwayID) AS stepCount '
    . 'FROM tblPathways p '
    . 'LEFT JOIN tblUsers u ON u.userID = p.createdByID '
    . 'WHERE p.siteID = ? '
    . 'ORDER BY p.isActive DESC, p.updatedAt DESC '
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

$pageTitle   = 'Discipleship Pathways';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Discipleship Pathways' => ''];
$csrf        = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 mb-0"><i class="fa-solid fa-route me-2 text-primary"></i>Discipleship Pathways</h1>
        <a href="/admin/discipleship/pathways/new" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus me-1"></i>New pathway
        </a>
    </div>
    <p class="text-muted small">
        Define ordered formation pathways (e.g. <em>New believer 101</em>, <em>Leadership track</em>)
        for pastoral follow-up. Phase&nbsp;1 ships the structure; per-member progress, mentor pairing
        and auto-completion ship in Phase&nbsp;2.
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
            No pathways defined yet. <a href="/admin/discipleship/pathways/new">Create your first pathway</a>
            to start building a discipleship journey.
        </div>
    <?php else: ?>
        <div class="portal-data-list">
            <?php foreach ($pathways as $p): ?>
                <?php
                $isActive  = (int) $p['isActive'] === 1;
                $stepCount = (int) $p['stepCount'];
                ?>
                <div class="portal-data-row">
                    <div class="portal-data-row-main">
                        <a href="/admin/discipleship/pathways/edit?id=<?php echo (int) $p['pathwayID']; ?>"
                           class="text-decoration-none">
                            <strong><?php echo htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                        <?php if ($isActive === false): ?>
                            <span class="badge bg-secondary ms-1">Inactive</span>
                        <?php endif; ?>
                        <div class="small text-muted">
                            <?php echo (int) $stepCount; ?>&nbsp;step<?php echo $stepCount === 1 ? '' : 's'; ?>
                            <?php if (empty($p['description']) === false): ?>
                                &middot; <?php echo htmlspecialchars((string) $p['description'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                            <br>
                            updated <?php echo htmlspecialchars(date('j M Y, H:i', strtotime((string) $p['updatedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            <?php if (empty($p['creatorName']) === false): ?>
                                &middot; created by <?php echo htmlspecialchars((string) $p['creatorName'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="portal-data-row-aside">
                        <a href="/admin/discipleship/pathways/edit?id=<?php echo (int) $p['pathwayID']; ?>"
                           class="btn btn-sm btn-outline-primary" title="Edit">
                            <i class="fa-solid fa-pen"></i>
                        </a>
                        <form method="post"
                              action="/admin/discipleship/pathways/delete"
                              class="d-inline"
                              onsubmit="return confirm('Delete this pathway and ALL its steps? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="pathwayID" value="<?php echo (int) $p['pathwayID']; ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
