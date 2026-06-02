<?php
// Path: public_html/projects/contributors.php
/**
 * Projects — public contributor list (opt-in named).
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

$db     = App::db();
$siteId = Site::id();
$slug   = (string) ($_GET['slug'] ?? '');

$project = null;
$stmt = $db->prepare('SELECT projectID, title, currency FROM tblProject WHERE siteID = ? AND slug = ? AND isPublic = 1 LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $slug);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($project === null) {
    http_response_code(404);
    exit('Project not found');
}

$projectId = (int) $project['projectID'];
$currency  = (string) ($project['currency'] ?? 'GBP');
$sym       = match ($currency) { 'GBP' => '£', 'EUR' => '€', 'USD' => '$', default => $currency . ' ' };

$rows = [];
$stmt = $db->prepare(
    'SELECT p.amountPence, p.donorName, p.isAnonymous, p.message, p.pledgedAt, u.fullName '
    . 'FROM tblProjectPledge p LEFT JOIN tblUsers u ON u.userID = p.donorID '
    . 'WHERE p.projectID = ? ORDER BY p.pledgedAt DESC LIMIT 200'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Contributors — ' . $project['title'];
$pageSection = 'projects';
$breadcrumbs = ['Dashboard' => '/', 'Projects' => '/projects', (string) $project['title'] => '/projects/view?slug=' . $slug, 'Contributors' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-people-group me-2"></i>Contributors — <?php echo htmlspecialchars((string) $project['title'], ENT_QUOTES, 'UTF-8'); ?></h1>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">No pledges yet — be the first!</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($rows as $r):
                $name = (int) $r['isAnonymous'] === 1
                    ? 'Anonymous'
                    : ((string) ($r['fullName'] ?? '') !== '' ? (string) $r['fullName']
                        : ((string) ($r['donorName'] ?? '') !== '' ? (string) $r['donorName'] : 'Anonymous'));
            ?>
                <div class="row py-2 border-bottom">
                    <div class="col-md-4"><strong><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-md-2"><?php echo $sym . number_format(((int) $r['amountPence']) / 100, 2); ?></div>
                    <div class="col-md-4 small text-muted"><?php echo htmlspecialchars((string) ($r['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2 small text-muted text-end"><?php echo htmlspecialchars(date('j M', (int) strtotime((string) $r['pledgedAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
