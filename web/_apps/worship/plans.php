<?php
// Path: _apps/worship/plans.php
/**
 * -----------------------------------------------------------------------------
 * Worship — Service Plans list 🎶 (#308 Phase 1)
 * -----------------------------------------------------------------------------
 * Login-required list of all service plans for the active site. Shows each
 * plan with item count, last-updated, and link to view/edit.
 *
 * Admins see all plans. Coordinators (Auth::isCoordinatorOf for any plan's
 * bound event) effectively see + edit just the plans for events they own —
 * non-coordinator non-admin users see all plans READ-ONLY (the editor
 * itself enforces write ACL).
 *
 * @package   Portal\Worship
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$filter = (string) ($_GET['filter'] ?? 'active');
if (in_array($filter, ['active', 'archived', 'all'], true) === false) {
    $filter = 'active';
}

// 📋 Plans with item count derived inline (LEFT JOIN + GROUP BY).
$plans = [];
$sql = 'SELECT p.planID, p.name, p.notes, p.isActive, p.updatedAt, p.eventID, '
     . '       e.eventName, COALESCE(c.itemCount, 0) AS itemCount, u.fullName AS creatorName '
     . 'FROM tblServicePlans p '
     . 'LEFT JOIN tblEvents e ON e.eventID = p.eventID AND e.isDeleted = 0 '
     . 'LEFT JOIN tblUsers  u ON u.userID  = p.createdByID '
     . 'LEFT JOIN (SELECT planID, COUNT(*) AS itemCount FROM tblServicePlanItems GROUP BY planID) c '
     . '         ON c.planID = p.planID '
     . 'WHERE p.siteID = ? ';
if ($filter === 'active') {
    $sql .= 'AND p.isActive = 1 ';
} elseif ($filter === 'archived') {
    $sql .= 'AND p.isActive = 0 ';
}
$sql .= 'ORDER BY p.updatedAt DESC LIMIT 200';

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $plans[] = $r; }
$stmt->close();

$pageTitle = 'Service Plans';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="fa-solid fa-music me-2 text-primary"></i>Service Plans</h1>
        <a href="/worship/plan?new=1" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-1"></i>New plan</a>
    </div>

    <div class="btn-group btn-group-sm mb-3" role="group">
        <?php foreach (['active', 'archived', 'all'] as $f): ?>
            <a class="btn btn-outline-secondary <?php echo $f === $filter ? 'active' : ''; ?>" href="?filter=<?php echo $f; ?>"><?php echo ucfirst($f); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (count($plans) === 0): ?>
        <div class="alert alert-info small">
            <?php if ($filter === 'active'): ?>
                No active service plans yet. <a href="/worship/plan?new=1">Create your first plan</a>.
            <?php else: ?>
                No plans in this view.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($plans as $p): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <a href="/worship/plan?id=<?php echo (int) $p['planID']; ?>" class="text-decoration-none fw-semibold h6 mb-1">
                        <?php echo htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <?php if ((int) $p['isActive'] === 0): ?>
                        <span class="badge bg-secondary ms-1">Archived</span>
                    <?php endif; ?>
                    <?php if ($p['eventName'] !== null): ?>
                        <span class="badge bg-info text-dark ms-1"><i class="fa-solid fa-calendar me-1"></i><?php echo htmlspecialchars((string) $p['eventName'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                        <span class="badge bg-light text-dark border ms-1">Template</span>
                    <?php endif; ?>
                    <div class="small text-muted">
                        <?php echo (int) $p['itemCount']; ?> slide<?php echo (int) $p['itemCount'] === 1 ? '' : 's'; ?>
                        &middot; updated <?php echo htmlspecialchars(date('j M Y, H:i', strtotime((string) $p['updatedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($p['creatorName'])): ?>
                            &middot; by <?php echo htmlspecialchars((string) $p['creatorName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="portal-data-row-aside">
                    <a href="/worship/plan?id=<?php echo (int) $p['planID']; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-pen me-1"></i>Open
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="text-muted small mt-4">
        Live operator + display modes ship in Phase 2. v1 is build-and-store only.
    </p>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
