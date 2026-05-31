<?php
// Path: public_html/visitors/my-follow-ups.php
/**
 * Visitor Tracking — my assigned visitors with overdue highlights.
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
$userId = (int) ($_SESSION['user_id'] ?? 0);

$rows = [];
$stmt = $db->prepare(
    'SELECT v.visitorID, v.fullName, v.email, v.phone, v.status, v.firstVisitedAt, '
    . '       (SELECT MAX(c.contactedAt) FROM tblVisitorContact c WHERE c.visitorID = v.visitorID) AS lastContactedAt, '
    . '       (SELECT MIN(c.nextContactAt) FROM tblVisitorContact c WHERE c.visitorID = v.visitorID AND c.nextContactAt >= CURDATE()) AS nextContactAt '
    . 'FROM tblVisitor v '
    . 'WHERE v.siteID = ? AND v.assignedToID = ? AND v.status IN (\'new\',\'in-touch\') '
    . 'ORDER BY nextContactAt IS NULL DESC, nextContactAt ASC, v.firstVisitedAt'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$initialDays = (int) (App::settings()['visitors']['followup_initial_days'] ?? 7);
$today = time();

$pageTitle   = 'My visitor follow-ups';
$pageSection = 'visitors';
$breadcrumbs = ['Dashboard' => '/', 'Visitors' => '/visitors', 'My follow-ups' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-list-check me-2"></i>My follow-ups</h1>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">No active visitors assigned to you.</div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($rows as $r):
                    $lastContact = $r['lastContactedAt'] !== null ? strtotime((string) $r['lastContactedAt']) : null;
                    $firstVisit  = strtotime((string) $r['firstVisitedAt']);
                    $overdue = false;
                    if ($lastContact === null) {
                        $daysSinceVisit = (int) (($today - $firstVisit) / 86400);
                        $overdue = $daysSinceVisit > $initialDays;
                    }
                ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-3"><a href="/visitors/profile?id=<?php echo (int) $r['visitorID']; ?>"><strong><?php echo htmlspecialchars((string) $r['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong></a></div>
                        <div class="col-md-2"><span class="badge bg-info text-dark"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-3 small text-muted">
                            <?php
                            if ($r['lastContactedAt'] !== null) {
                                echo 'Last: ' . htmlspecialchars(date('j M', $lastContact ?: 0), ENT_QUOTES, 'UTF-8');
                            } else {
                                echo 'Never contacted';
                            }
                            ?>
                        </div>
                        <div class="col-md-4 text-end">
                            <?php if ($r['nextContactAt'] !== null): ?>
                                <span class="badge bg-warning text-dark me-2">Due: <?php echo htmlspecialchars(date('j M', strtotime((string) $r['nextContactAt'])), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if ($overdue === true): ?>
                                <span class="badge bg-danger">Overdue first contact</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
