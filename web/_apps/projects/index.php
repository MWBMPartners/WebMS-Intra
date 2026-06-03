<?php
// Path: public_html/projects/index.php
/**
 * Projects — public listing.
 *
 * @package   Portal\Projects
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/267
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Projects;
use Portal\Core\Site;

Auth::ensureSession();

$db       = App::db();
$siteId   = Site::id();
$settings = App::settings()['projects'] ?? [];
$currency = (string) ($settings['currency'] ?? 'GBP');

$rows = [];
$stmt = $db->prepare(
    'SELECT projectID, slug, title, description, targetAmountPence, currency, status, coverImagePath '
    . 'FROM tblProject WHERE siteID = ? AND isPublic = 1 AND status IN ("active","funded","completed") '
    . 'ORDER BY status = "active" DESC, updatedAt DESC LIMIT 60'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $r['raised'] = Projects::raisedPence((int) $r['projectID']);
        $rows[] = $r;
    }
    $stmt->close();
}

$displayName = (string) ($settings['displayName'] ?? 'Projects');

$pageTitle   = $displayName;
$pageSection = 'projects';
$breadcrumbs = ['Dashboard' => '/', $displayName => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-bullseye me-2"></i><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if (App::isAdmin() === true): ?>
        <a href="/projects/manage" class="btn btn-primary btn-sm"><i class="fa-solid fa-gear me-1"></i>Manage</a>
    <?php endif; ?>
</div>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">No active projects right now.</div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($rows as $r):
            $target = max(1, (int) $r['targetAmountPence']);
            $raised = (int) $r['raised'];
            $pct    = min(100, (int) round(($raised / $target) * 100));
            $cur    = (string) ($r['currency'] ?? $currency);
            $sym    = match ($cur) { 'GBP' => '£', 'EUR' => '€', 'USD' => '$', default => $cur . ' ' };
        ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <?php if (($r['coverImagePath'] ?? '') !== ''): ?>
                        <img src="<?php echo htmlspecialchars((string) $r['coverImagePath'], ENT_QUOTES, 'UTF-8'); ?>" class="card-img-top" alt="" style="max-height:180px;object-fit:cover;">
                    <?php endif; ?>
                    <div class="card-body">
                        <h5><a href="/projects/view?slug=<?php echo urlencode((string) $r['slug']); ?>" class="text-decoration-none"><?php echo htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8'); ?></a></h5>
                        <?php if ((string) $r['status'] === 'funded'): ?>
                            <span class="badge bg-success mb-2"><i class="fa-solid fa-check me-1"></i>Funded</span>
                        <?php endif; ?>
                        <div class="progress mb-2" style="height:14px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $pct; ?>%;" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"><?php echo $pct; ?>%</div>
                        </div>
                        <p class="small text-muted mb-0">
                            <strong><?php echo $sym . number_format($raised / 100, 2); ?></strong>
                            of <?php echo $sym . number_format($target / 100, 2); ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
