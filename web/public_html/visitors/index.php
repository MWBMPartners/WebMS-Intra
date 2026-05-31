<?php
// Path: public_html/visitors/index.php
/**
 * Visitor Tracking — kanban-style board grouping visitors by status.
 *
 * @package   Portal\Visitors
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/258
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();

$columns = ['new' => [], 'in-touch' => [], 'converted' => [], 'lost' => []];
$rs = $db->query(
    "SELECT v.visitorID, v.fullName, v.email, v.phone, v.status, v.firstVisitedAt, "
    . "       u.fullName AS assigneeName, "
    . "       (SELECT MAX(c.contactedAt) FROM tblVisitorContact c WHERE c.visitorID = v.visitorID) AS lastContactedAt "
    . "FROM tblVisitor v "
    . "LEFT JOIN tblUsers u ON u.userID = v.assignedToID "
    . "WHERE v.siteID = " . $siteId . " "
    . "ORDER BY v.status, v.firstVisitedAt DESC"
);
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $columns[$r['status']][] = $r;
    }
    $rs->free();
}

$pageTitle   = 'Visitor Tracking';
$pageSection = 'visitors';
$breadcrumbs = ['Dashboard' => '/', 'Visitors' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$statusMeta = [
    'new'       => ['label' => 'New',         'class' => 'primary'],
    'in-touch'  => ['label' => 'In touch',    'class' => 'info'],
    'converted' => ['label' => 'Converted',   'class' => 'success'],
    'lost'      => ['label' => 'Lost contact','class' => 'secondary'],
];
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-user-plus me-2"></i>Visitor Tracking</h1>
        <p class="text-secondary mb-0">First-time visitors + their follow-up status.</p>
    </div>
    <div>
        <a href="/visitors/my-follow-ups" class="btn btn-outline-primary btn-sm me-1">My follow-ups</a>
        <a href="/visitors/new" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Add visitor</a>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($columns as $status => $visitors): $m = $statusMeta[$status]; ?>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header bg-<?php echo $m['class']; ?> text-white">
                    <strong><?php echo htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="badge bg-light text-dark ms-1"><?php echo count($visitors); ?></span>
                </div>
                <div class="card-body p-2" style="max-height:600px;overflow-y:auto;">
                    <?php if (count($visitors) === 0): ?>
                        <p class="small text-muted text-center my-3">No visitors here.</p>
                    <?php else: foreach ($visitors as $v): ?>
                        <a href="/visitors/profile?id=<?php echo (int) $v['visitorID']; ?>"
                           class="d-block text-decoration-none text-reset card mb-2 p-2 small">
                            <strong><?php echo htmlspecialchars((string) $v['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                            <span class="text-muted">Visited <?php echo htmlspecialchars(date('j M', strtotime((string) $v['firstVisitedAt'])), ENT_QUOTES, 'UTF-8'); ?></span><br>
                            <?php if ($v['assigneeName'] !== null): ?>
                                <span class="badge bg-light text-dark mt-1">→ <?php echo htmlspecialchars((string) $v['assigneeName'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark mt-1">Unassigned</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
