<?php
// Path: public_html/service-plans/index.php
/**
 * Service Plans — upcoming + recent.
 *
 * @package   Portal\ServicePlans
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/262
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();

$plans = [];
$stmt = $db->prepare(
    'SELECT p.planID, p.title, p.serviceDate, p.status, '
    . '       u.fullName AS preparedByName, '
    . '       (SELECT COUNT(*) FROM tblServicePlanItem i WHERE i.planID = p.planID) AS itemCount '
    . 'FROM tblServicePlan p '
    . 'LEFT JOIN tblUsers u ON u.userID = p.preparedByID '
    . 'WHERE p.siteID = ? '
    . 'ORDER BY p.serviceDate DESC LIMIT 50'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $plans[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Service Plans';
$pageSection = 'service-plans';
$breadcrumbs = ['Dashboard' => '/', 'Service Plans' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-list-ol me-2"></i>Service Plans</h1>
        <p class="text-secondary mb-0">Run-sheet per service: preacher, scripture, hymns, AV, welcome team.</p>
    </div>
    <a href="/service-plans/new" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>New plan</a>
</div>

<?php if (count($plans) === 0): ?>
    <div class="alert alert-info">No service plans yet. <a href="/service-plans/new">Create the first →</a></div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($plans as $p):
                    $statusCls = match ($p['status']) {
                        'published' => 'success',
                        'archived'  => 'secondary',
                        default     => 'warning',
                    };
                ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-3">
                            <a href="/service-plans/edit?id=<?php echo (int) $p['planID']; ?>" class="text-decoration-none">
                                <strong><?php echo htmlspecialchars((string) $p['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </a>
                        </div>
                        <div class="col-md-2 small">
                            <?php echo htmlspecialchars(date('D j M Y', strtotime((string) $p['serviceDate'])), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="col-md-2"><span class="badge bg-<?php echo $statusCls; ?>"><?php echo htmlspecialchars((string) $p['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2 small text-muted"><?php echo (int) $p['itemCount']; ?> items</div>
                        <div class="col-md-3 text-end">
                            <a href="/service-plans/edit?id=<?php echo (int) $p['planID']; ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                            <a href="/service-plans/print?id=<?php echo (int) $p['planID']; ?>" class="btn btn-outline-secondary btn-sm" target="_blank">Print</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
